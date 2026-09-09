<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Contracts\FollowUpGateway;
use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The offer that comes after the payment.
 *
 * A buyer who has just paid is offered one more thing. If they accept, they are
 * charged without typing their card details again — because the first payment
 * left a mandate behind, not because anything was skipped.
 *
 * **The consent is not skipped.** Under § 312j BGB an order still needs its own
 * unambiguously labelled button with the essential details directly above it,
 * and this class charges only when it is called from a form submission that
 * carried that button. The saved keystrokes are the card number, not the
 * agreement. `docs/follow-up-offers.md` spells out what the page must show.
 *
 * What this class is not: a funnel. It has no notion of steps, conditions,
 * downsells or "what to offer next". That belongs above it, and the seam is
 * this one method.
 */
class FollowUp
{
    public function __construct(protected PaymentGateway $gateway) {}

    /** Whether this site can charge a returning buyer at all. */
    public function available(): bool
    {
        return config('statamic-payments.follow_up.enabled', false)
            && $this->gateway instanceof FollowUpGateway
            && $this->gateway->supportsFollowUp();
    }

    /**
     * The provider that holds this order's stored card.
     *
     * Read off the order's own `provider` column, not the container's binding.
     * A card stored at one provider is not chargeable at another, and asking
     * the wrong one would fail on a mandate that exists.
     */
    protected function followUpGateway(Payment $payment): ?FollowUpGateway
    {
        $gateway = app(Gateways::class)->for($payment);

        return $gateway instanceof FollowUpGateway && $gateway->supportsFollowUp()
            ? $gateway
            : null;
    }

    /**
     * Whether this particular payment can carry a follow-up.
     *
     * Four conditions, and all four are refusals of the same kind: no
     * agreement, no charge.
     *
     * `$buyerEmail` is who the caller currently has in front of it. Pass it
     * whenever you know — it is the difference between „derselbe Mensch kauft
     * noch etwas" and „auf demselben Rechner sitzt jetzt jemand anderes". A
     * caller that cannot know may omit it; then only the first three hold.
     */
    public function eligible(Payment $payment, ?string $buyerEmail = null): bool
    {
        // The capability question is asked of **this payment's** provider, not
        // of the container's default. Asking the default would let a page show
        // an offer that the provider holding the card cannot charge — and a
        // page that offers a thing and then fails at the till is worse than a
        // page that never offered it (`Tags\Payments`). The config switch stays
        // global: that one really is a property of the site.
        return config('statamic-payments.follow_up.enabled', false)
            && $this->followUpGateway($payment) !== null
            && $payment->isPaid()
            && is_string($payment->customer_reference)
            && $payment->customer_reference !== ''
            && $this->sameBuyer($payment, $buyerEmail);
    }

    /**
     * Ob die Person vor dem Bildschirm dieselbe ist wie bei der ersten Zahlung.
     *
     * Ohne diese Frage wird ein Mandat zu einer Eigenschaft des Geraets statt
     * des Menschen: wer als Zweiter am selben Rechner kauft, wuerde auf die
     * Karte des Ersten abgebucht, und Zugang wie Rechnung gingen an dessen
     * Adresse. Auf einem Familienrechner, im Buero oder in einer Bibliothek ist
     * das kein Randfall.
     *
     * Wer nichts uebergibt, bekommt das alte Verhalten — es gibt Aufrufer, die
     * ihren Kaeufer aus einer signierten Sitzung kennen und keine Adresse zur
     * Hand haben. Wer etwas uebergibt, bekommt eine Ablehnung, sobald es nicht
     * passt. Steht an der Zahlung noch keine Adresse, ist nichts zu
     * widersprechen: dann bleibt es beim Ja.
     */
    public function sameBuyer(Payment $payment, ?string $buyerEmail): bool
    {
        $known = is_string($payment->email) ? trim($payment->email) : '';
        $current = is_string($buyerEmail) ? trim($buyerEmail) : '';

        if ($known === '' || $current === '') {
            return true;
        }

        return mb_strtolower($known) === mb_strtolower($current);
    }

    /**
     * Whether this offer has already been taken from this payment.
     *
     * A refused charge does not count — the buyer got nothing, so offering
     * again is right. A pending one does: a recurring charge sits at `pending`
     * for a while, and an offer that stays on the page in the meantime is an
     * invitation to buy the same thing twice.
     */
    public function alreadyTaken(Payment $original, string $productHandle): bool
    {
        return Payment::query()
            ->where('parent_payment_id', $original->getKey())
            ->where('product', $productHandle)
            ->where('status', '!=', Payment::STATUS_FAILED)
            ->exists();
    }

    /**
     * Wessen Marke auf der Folgezahlung steht.
     *
     * Geerbt wurde sie bisher von der Vorgaengerzahlung, und als Erbe ist das
     * richtig gedacht: hier laeuft keine Anfrage mit einer Marke — ein
     * Nachfassangebot wird auch aus einem Hintergrundlauf angenommen, und
     * {@see Brands::stampId()} gaebe dort null. Nur beantwortet das Erbe die
     * falsche Frage. Gefragt ist nicht „wessen Zahlung war die vorige", sondern
     * „wessen Angebot wird hier verkauft".
     *
     * Die beiden Antworten gehen auseinander, sobald die Vorgaengerzahlung
     * falsch gestempelt war — jede Funnel-Zahlung von vor `statamic-funnels`
     * 1.15.2 trug die Standardmarke, und der Besuchs-Cookie haelt einen Monat —
     * oder sobald ein Funnel ein Upsell fuehrt, das einer anderen Marke gehoert
     * als sein Erstangebot.
     *
     * **Das Angebot gewinnt, und der Verkauf findet trotzdem statt.** Der
     * Kaeufer hat auf den Bestellknopf geklickt; ein Konfigurationsfehler des
     * Betreibers ist nichts, wofuer eine Bestellung abgelehnt werden darf. Er
     * gehoert aber ins Log, mit beiden Marken und dem Handle, denn ein fremdes
     * Upsell ist entweder Absicht oder ein Fehler, und keins von beidem darf
     * man raten.
     *
     * Nennt der Katalogeintrag keine Marke — Altbestand, ein Produkt aus der
     * Config, ein Seeder —, bleibt es beim Erbe. Das ist die einzige Antwort,
     * die keine Erfindung ist, und `info` statt `warning`, weil sie richtig
     * ist und nur festhaelt, dass am Angebot etwas fehlt.
     *
     * Auf einem Einzelmarken-System ueberhaupt keine Frage: dort ist jede Marke
     * null, und ein Hinweis je Bestellung waere reiner Laerm. Gefragt wird nach
     * {@see Brands::mode()} und nicht nach `multiBrand()`, weil der Unterschied
     * genau hier haengt — `multiBrand()` sagt auch dann `false`, wenn das
     * Geschwister nicht antworten wollte ({@see Brands::UNKNOWN}), und das ist
     * der Augenblick, in dem am ehesten etwas schiefgeht. Ein stiller Verkauf
     * unter der geerbten Marke waere dann nicht mehr zu rekonstruieren, also
     * laeuft die Pruefung auch da.
     *
     * @param  array<string, mixed>  $product  Der Katalogeintrag. `brand_id`
     *                                         reicht dieselbe Durchreiche her wie `interval` und `times`:
     *                                         {@see Catalogue::find()} behaelt, was der Katalog sonst noch deklariert.
     */
    protected function brandFor(array $product, Payment $original): int
    {
        $geerbt = (int) $original->brand_id;

        if (Brands::mode() === Brands::SINGLE) {
            return $geerbt;
        }

        // Nicht der blosse Cast: der Katalog ist offen, und der Eintrag kommt
        // womoeglich aus dem Resolver eines fremden Pakets. `(int)` machte aus
        // einem versehentlichen Array eine `1` — also eine echte Marke, die es
        // hier zufaellig gibt. Eine Ziffernfolge im Text zaehlt dagegen: eine
        // Eloquent-Spalte ohne Cast liefert genau die.
        $roh = $product['brand_id'] ?? null;
        $desAngebots = match (true) {
            is_int($roh) => $roh,
            is_string($roh) && ctype_digit($roh) => (int) $roh,
            default => 0,
        };
        $handle = (string) ($product['handle'] ?? '');

        if ($desAngebots < 1) {
            // „Nichts gesagt" und „etwas gesagt, das keine Marke ist" sind
            // nicht dasselbe, und nur das erste ist harmlos. Beim zweiten wird
            // ebenfalls geerbt — raten waere schlimmer —, aber der Grund steht
            // dann laut da, statt als „nennt keine Marke" verkleidet zu werden.
            if ($roh === null || $roh === 0 || $roh === '0') {
                Log::info('statamic-payments: this follow-up offer names no brand, so the charge inherits the brand of the payment it follows.', [
                    'product' => $handle,
                    'original_brand' => $geerbt,
                    'original_payment' => $original->getKey(),
                ]);
            } else {
                Log::warning('statamic-payments: this follow-up offer names something that is not a usable brand id, so the charge inherits the brand of the payment it follows.', [
                    'product' => $handle,
                    // Der Typ und nur bei einem Skalar der Wert: was hier steht,
                    // kommt aus fremdem Code und gehoert nicht ungeprueft in
                    // eine Logzeile.
                    'brand_id_type' => get_debug_type($roh),
                    'brand_id' => is_scalar($roh) ? $roh : null,
                    'original_brand' => $geerbt,
                    'original_payment' => $original->getKey(),
                ]);
            }

            return $geerbt;
        }

        if ($desAngebots !== $geerbt) {
            // Zwei sehr verschiedene Lagen, und die Beschriftung entscheidet,
            // ob jemand etwas tut. Eine Vorgaengerzahlung auf 0 gehoerte nie
            // einer Marke: entstanden, wo keine galt — Webhook, Kommando,
            // Warteschlange —, also stempelte {@see Brands::stampId()} null.
            // Dass das Upsell jetzt eine Marke bekommt, ist die Reparatur und
            // nicht der Fehler.
            //
            // **Der Altbestand von vor `statamic-funnels` 1.15.2 steht
            // ausdruecklich nicht hier.** Der trug die *Standardmarke*, und die
            // ist groesser als null. Er landet also in der zweiten Meldung, und
            // das ist richtig: von aussen ist eine falsch gestempelte alte
            // Zahlung von einem Funnel mit fremdem Upsell nicht zu
            // unterscheiden, und beide will der Betreiber sehen.
            Log::warning($geerbt < 1
                ? 'statamic-payments: the payment this follow-up follows carries no brand, so the charge takes the brand of the offer instead of inheriting none.'
                : 'statamic-payments: this follow-up offer belongs to a different brand than the payment it follows; the charge is made under the brand of the offer.', [
                    'product' => $handle,
                    'offer' => is_string($product['offer'] ?? null) ? $product['offer'] : null,
                    'offer_brand' => $desAngebots,
                    'original_brand' => $geerbt,
                    'original_payment' => $original->getKey(),
                ]);
        }

        return $desAngebots;
    }

    /**
     * Charge the accepted offer.
     *
     * Returns the new payment, or null if it was refused — and a refusal is the
     * normal outcome when the buyer never agreed to be charged again.
     *
     * @param  array<string, mixed>  $context  Free-form, stored on the line.
     * @param  array<string, mixed>|PaymentDetails  $details  Was die aufrufende
     *                                                        Strecke an *diese* Zahlung heften will: `meta`, `country`. Siehe
     *                                                        {@see PaymentDetails}. Wird geschrieben, bevor der Anbieter gerufen
     *                                                        wird, weil ein Nachtragen ein Rennen gegen den Webhook wäre.
     * @param  string|null  $buyerEmail  Wer gerade vor dem Bildschirm sitzt,
     *                                   soweit die Strecke es weiß. Passt es nicht zur Adresse der ersten
     *                                   Zahlung, wird nicht abgebucht. Siehe {@see sameBuyer()}.
     *
     * @throws \InvalidArgumentException wenn $details etwas enthält, das dem Paket gehört
     */
    public function accept(Payment $original, string $productHandle, array $context = [], array|PaymentDetails $details = [], ?string $buyerEmail = null): ?Payment
    {
        // Zuerst, und vor jeder Prüfung, die vom Zustand abhängt: ein Aufrufer,
        // der etwas Unerlaubtes mitgibt, soll das immer erfahren und nicht nur
        // dann, wenn dieses Angebot gerade zulässig ist.
        $details = PaymentDetails::from($details);

        if (! $this->eligible($original, $buyerEmail)) {
            return null;
        }

        // Not twice. Two clicks on the same button, a double submit, a reload
        // of the confirmation — all of them arrive here, and all of them would
        // otherwise be a second charge for the same thing.
        if ($this->alreadyTaken($original, $productHandle)) {
            return null;
        }

        $product = app(Catalogue::class)->find($productHandle);

        if (! $product) {
            return null;
        }

        // The row exists before the provider is called, exactly as at checkout:
        // the other order loses the payment if the process dies in between, and
        // the buyer has by then been charged.
        $payment = DB::transaction(function () use ($original, $product, $context, $details): Payment {
            // Die Angaben des Aufrufers kommen in dasselbe INSERT wie alles
            // andere, also festgeschrieben, bevor `chargeAgain()` unten den
            // Anbieter ruft. Das ist der ganze Punkt: es gibt keinen Moment, in
            // dem die Zahlung beim Anbieter liegt und die Anschrift noch nicht
            // in der Datenbank steht.
            $payment = Payment::create($details->onto([
                // The provider of the order this offer follows, read off its
                // own row. The stored card is that provider's, so charging it
                // through another one would find no mandate at all.
                'provider' => $original->provider,
                'provider_id' => Payment::PLACEHOLDER_PROVIDER_PREFIX.Str::uuid(),
                // Die Marke des verkauften Angebots, sonst das Erbe. Siehe
                // {@see self::brandFor()} — dort steht, warum das Erbe allein
                // die falsche Frage beantwortet.
                'brand_id' => $this->brandFor($product, $original),
                'product' => $product['handle'],
                'amount_cent' => $product['amount_cent'],
                'currency' => $product['currency'],
                'status' => Payment::STATUS_INITIATED,
                'email' => $original->email,
                'name' => $original->name,
                'customer_reference' => $original->customer_reference,
                // Which order this grew out of. Without it a follow-up looks
                // like an unrelated second purchase, and nobody can answer
                // "did the offer work" without guessing.
                'parent_payment_id' => $original->getKey(),
                // The campaign that produced the original produced this too.
                // Left off, the upsell — the very revenue an offer exists to
                // create — counts towards no campaign at all, and the report
                // credits the funnel for less than it earned. `onto()` means a
                // caller that hands its own attribution in still wins.
                //
                // Was **nicht** geerbt wird: `consent_at` und `consent_text`.
                // Die Zustimmung nach § 356 Abs. 5 BGB gilt für den Kauf, bei
                // dem sie erklärt wurde, und für keinen zweiten. Ein Nachfass-
                // angebot ist ein eigener Vertrag mit eigener Bestellschalt-
                // fläche, also kommt seine Zustimmung aus `$details` — oder
                // gar nicht, und dann bleiben die Spalten ehrlich leer.
                // (Rechtliche Entscheidung 01.09.2026, von Adrian zu prüfen.
                // Keine Rechtsberatung.)
                ...self::inheritedAttribution($original),
            ]));

            PaymentItem::create([
                'payment_id' => $payment->id,
                'product' => $product['handle'],
                // Über welches Angebot, in derselben Reihenfolge wie an der
                // Kasse: was der Aufrufer sagt, sonst was der Katalog an das
                // Produkt geheftet hat, sonst null. Nie geraten.
                //
                // Fehlte das hier, war die Spalte genau bei der Zeile leer, für
                // die sie gebaut wurde: der Umsatz eines Nachfassangebots. Der
                // Bericht in `statamic-insights` ordnet ihn dann keinem Angebot
                // zu, und das Angebot, das am meisten einbringt, sieht aus, als
                // hätte es nichts eingebracht. (Feldfund Testkauf 02.09.2026:
                // Zahlung über die Kasse mit `offer`, Upsell darüber ohne.)
                'offer' => $details->offerFor((string) $product['handle'])
                    ?? (is_string($product['offer'] ?? null) && $product['offer'] !== '' ? $product['offer'] : null),
                'name' => $product['name'],
                'amount_cent' => $product['amount_cent'],
                'quantity' => 1,
                'kind' => PaymentItem::KIND_UPSELL,
                'meta' => $context === [] ? null : $context,
            ]);

            return $payment;
        });

        try {
            // Der Anbieter der Erstbestellung, aus deren eigener Spalte
            // gelesen. Die gespeicherte Karte liegt bei ihm; ein anderer
            // Anbieter faende zu dieser Kundenkennung gar kein Mandat.
            $gateway = $this->followUpGateway($original);

            if (! $gateway) {
                throw new RuntimeException(
                    "statamic-payments: the provider [{$original->provider}] cannot charge a returning buyer."
                );
            }

            // Das Mandat der Erstbestellung, ausdruecklich benannt.
            //
            // Ohne diese Zeile bekommt der Anbieter nur die Kundenkennung und
            // sucht sich selbst ein gueltiges Mandat aus. Die Seite, auf der
            // dieses Angebot angenommen wurde, hat aber eine BESTIMMTE Karte
            // angekuendigt — Marke und letzte vier Ziffern der Erstzahlung,
            // aus `card_label`/`card_last4` derselben Zeile. Bei einem Kaeufer
            // mit zwei Mandaten waeren Ankuendigung und Abbuchung sonst zwei
            // verschiedene Dinge.
            //
            // Fehlt die Kennung — Bestandszeile von vor dieser Fassung, oder
            // eine Zahlung, die nie ein Mandat hinterlassen hat —, wird der
            // Schluessel gar nicht erst mitgeschickt. Dann laeuft es wie bisher
            // und der Anbieter waehlt.
            //
            // Das `trim()` traegt dabei mehr, als es aussieht. Mollies Client
            // wirft `null` und `''` vor dem Senden selbst heraus
            // (`Utils/DataTransformer.php`), ein `'   '` aber nicht — das ginge
            // als echte Angabe raus und wuerde abgelehnt. Und der Vertrag ist
            // anbieterunabhaengig: ein zweiter Gateway filtert vielleicht gar
            // nichts.
            //
            // Nur fuer die EINZELNE Folgeabbuchung. Abos pinnen weiterhin kein
            // Mandat, damit ein Kartenwechsel ab dem naechsten Zyklus greift
            // (begruendet in MollieGateway::startMandateUpdate()).
            $mandat = trim((string) ($original->mandate_id ?? ''));

            $remote = $gateway->chargeAgain((string) $original->customer_reference, [
                'amount' => [
                    'currency' => $payment->currency,
                    'value' => $payment->amount(),
                ],
                ...($mandat === '' ? [] : ['mandateId' => $mandat]),
                'description' => $product['name'],
                'webhookUrl' => config('statamic-payments.webhook_url') === false
                    ? null
                    : (config('statamic-payments.webhook_url') ?: route('statamic-payments.webhook')),
                'metadata' => [
                    'payment_id' => $payment->id,
                    'product' => $product['handle'],
                    'email' => $payment->email,
                ],
            ]);
        } catch (Throwable $e) {
            // The provider refused. Most often: no mandate, which means the
            // buyer never agreed to this. The row stays as evidence that the
            // offer was accepted and the charge did not happen.
            Log::warning('statamic-payments: a follow-up charge was refused.', [
                'payment_id' => $payment->getKey(),
                'parent_payment_id' => $original->getKey(),
                'exception' => $e->getMessage(),
            ]);

            $payment->forceFill(['status' => Payment::STATUS_FAILED])->save();

            return null;
        }

        $payment->forceFill([
            'provider_id' => $remote->providerId,
            // Whatever the provider says, and nothing else. A recurring charge
            // often comes back `pending`, and treating that as paid would grant
            // access before the money moved — the exact mistake this package
            // exists to avoid.
            'status' => $remote->status,
        ])->save();

        return $payment->fresh() ?? $payment;
    }

    /**
     * The attribution of the order this one grew out of.
     *
     * Copied rather than looked up: the original froze it at its own checkout,
     * and by the time an upsell is accepted the session that knew it may be
     * gone. The same reason the country is frozen one column over.
     *
     * @return array<string, string>
     */
    protected static function inheritedAttribution(Payment $original): array
    {
        $values = [];

        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'referrer', 'landing_page'] as $column) {
            $value = $original->{$column} ?? null;

            if (is_string($value) && $value !== '') {
                $values[$column] = $value;
            }
        }

        return $values;
    }
}
