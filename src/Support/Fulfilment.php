<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Events\PaymentFailed;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Events\SubscriptionCycleFailed;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides whether money moved, and makes sure the answer is acted on once.
 *
 * The two properties this class exists for, and both are the kind that only a
 * test which *tries* to break them can demonstrate:
 *
 * 1. **The caller is never believed.** A webhook posts an id and nothing else
 *    worth reading. The status is fetched from the provider, so a forged
 *    "paid" call is simply a request to re-check a payment that is not paid.
 * 2. **Fulfilment happens once.** Providers redeliver; a proxy can duplicate.
 *    The claim is staked with a conditional UPDATE, so two simultaneous
 *    deliveries cannot both win it.
 *
 * On the second: the claim is *released again* if a listener throws, and the
 * webhook then answers non-2xx so the provider redelivers. That is deliberate.
 * Holding the claim would be "at most once", and the failure mode of at-most-once
 * is a customer who paid and got nothing, silently, for ever. The cost is that a
 * listener which throws halfway may see the event twice — said plainly in the
 * README, because a listener author has to know which of the two it is.
 */
class Fulfilment
{
    public function __construct(protected PaymentGateway $gateway) {}

    /**
     * @param  bool  $announcedFailure  whether the provider's own event said this charge failed
     */
    public function handle(string $providerId, bool $announcedFailure = false): ?Payment
    {
        $payment = Payment::query()
            ->where('provider', $this->gateway->provider())
            ->where('provider_id', $providerId)
            ->first();

        // Asked once, and asked even for an id we do not know. Fetching only
        // for known ids would answer, in the response time, which ids this site
        // has seen — the very question the flat 200 further down refuses.
        $remote = $this->fetch($providerId);

        $payment ??= $this->recover($providerId, $remote);

        // The one payment a site legitimately never created: a cycle the
        // provider charged on its own, on an agreement this site *did* create.
        // The row is written here rather than refused, because the alternative
        // is a customer paying every month into a system that has no record of
        // it. The evidence is the agreement, not the caller: the subscription id
        // comes from the provider's own answer.
        $payment ??= $this->openCycle($providerId, $remote);

        if (! $payment) {
            // A payment this site never created. Nothing to fulfil, and nothing
            // to create either: an id we did not issue is not evidence of an
            // order, whatever the provider says about it.
            //
            // Logged, though. The one way this happens to a real site is a
            // checkout that died between the provider call and the id being
            // stored — the buyer pays, and without this line nobody ever hears
            // about it.
            Log::warning('statamic-payments: webhook for an unknown payment id.', [
                'provider' => $this->gateway->provider(),
                'provider_id' => $providerId,
            ]);

            return null;
        }

        // Vor der Statusfrage, und mit Absicht: eine zurueckgebuchte Zahlung
        // steht beim Anbieter weiter auf `paid`. Wer erst unten einsteigt,
        // erfuellt sie noch einmal und merkt von der Rueckbuchung nichts.
        $this->noteChargeback($payment, $remote);

        // Und danach nicht mehr erfuellen.
        //
        // `isPaid()` kennt nur den Status, und eine zurueckgebuchte
        // Mollie-Zahlung steht weiter auf `paid`. Ohne diese Zeile buchte
        // derselbe Aufruf erst die Rueckbuchung und erfuellte die Bestellung
        // danach trotzdem: Zugang zu einem Produkt, dessen Geld laut eigenem
        // Protokoll schon weg ist. Der Fall tritt ein, wenn die erste
        // erfolgreiche Zustellung fuer diese Kennung erst **nach** der
        // Rueckbuchung ankommt — ein verlorener erster Webhook reicht.
        if (($payment->fresh() ?? $payment)->charged_back_at !== null) {
            Log::warning('statamic-payments: a payment that has been charged back was not fulfilled.', [
                'payment_id' => $payment->getKey(),
                'provider' => $payment->provider,
                'provider_id' => $payment->provider_id,
            ]);

            return $payment->fresh() ?? $payment;
        }

        if (! $remote || ! $remote->isPaid()) {
            if ($remote) {
                $this->recordUnpaid($payment, $remote);
                $this->announceFailedCycle($payment, $remote, $announcedFailure);
            }

            return $payment;
        }

        return $this->fulfilOnce($payment, $remote);
    }

    /**
     * Eine Rueckbuchung, wenn der Anbieter eine meldet.
     *
     * Mollie kuendigt sie nicht als eigenes Ereignis an, sondern als
     * Zustandsaenderung an der Zahlung — der Webhook traegt wie immer nur deren
     * Kennung, und der Betrag steht in der Antwort. Stripe hat ein eigenes
     * Ereignis und geht deshalb gar nicht hier durch.
     *
     * Ohne Kennung wird nichts gebucht: {@see Chargebacks::record()} ist ueber
     * genau die idempotent, und eine Buchung ohne sie entzieht bei jeder
     * weiteren Zustellung erneut den Zugang.
     */
    protected function noteChargeback(Payment $payment, ?RemotePayment $remote): void
    {
        if (! $remote || ! $remote->chargedBackCent || $remote->chargedBackCent <= 0) {
            return;
        }

        $reference = trim((string) ($remote->chargebackReference ?? ''));

        if ($reference === '') {
            Log::warning('statamic-payments: the provider reported money charged back but named no reference for it; nothing was recorded.', [
                'payment_id' => $payment->getKey(),
                'provider_id' => $payment->provider_id,
            ]);

            return;
        }

        app(Chargebacks::class)->record($payment, $reference, $remote->chargedBackCent);
    }

    /**
     * Ein Zyklus eines laufenden Abos, der nicht bezahlt wurde.
     *
     * `SubscriptionStartFailed` deckt das andere Ende ab — Geld kam an und es
     * entstand keine Vereinbarung. Das hier ist der Fall, der viel oefter
     * eintritt und bisher unsichtbar war: ein Abo laeuft seit Monaten, die
     * Karte laeuft ab, und dieses Paket spiegelte den Anbieterstatus, ohne je
     * etwas dazu zu sagen.
     *
     * Erkannt an der Vereinbarung, die der **Anbieter** nennt, nicht an einer
     * Behauptung des Aufrufers: `subscriptionId` steht auf der Antwort des
     * Anbieters. Ein gefaelschter Aufruf kann damit hoechstens eine Mahnstrecke
     * fuer eine Vereinbarung anstossen, die es wirklich gibt und deren Zahlung
     * der Anbieter wirklich als nicht bezahlt fuehrt.
     */
    protected function announceFailedCycle(Payment $payment, RemotePayment $remote, bool $announcedFailure = false): void
    {
        if (! $remote->subscriptionId) {
            return;
        }

        // `open` ist kein Fehlschlag. Eine Lastschrift unterwegs ist genau das,
        // und wer sie anmahnt, mahnt jemanden, dessen Geld gerade fliesst.
        //
        // **Ausser der Anbieter sagt ausdruecklich, dass sie gescheitert ist.**
        // Und das ist bei Stripe der Normalfall, nicht die Ausnahme: eine
        // Rechnung, deren Abbuchung fehlschlug, bleibt waehrend des ganzen
        // Wiederholungsfensters `open` und wird erst danach `uncollectible` —
        // wenn das Konto so eingestellt ist. Nur auf den Status zu sehen hiess
        // also: auf Stripe beginnt nie eine Mahnstrecke. Gefunden, weil der
        // Test die eine Form gestellt hatte, die funktioniert.
        //
        // Der Behauptung des Aufrufers wird dabei nichts geglaubt, was ihm
        // nuetzt: das Ereignis ist signiert, und es entscheidet nur, ob eine
        // **nicht bezahlte** Zahlung als Fehlschlag gilt. Ein „bezahlt" kann es
        // nicht herbeifuehren — das steht drei Zeilen weiter oben und kommt
        // weiter allein vom Anbieter.
        // `canceled` gehoert **nicht** dazu, anders als bei `recordUnpaid()`.
        // Eine abgebrochene Zahlung ist kein gescheiterter Einzug: bei Stripe
        // ist eine `void` gesetzte Rechnung genau das, ein Handgriff im
        // Dashboard. Wer sie storniert, will keine drei Mahnbriefe ausloesen.
        $failed = in_array($remote->status, [Payment::STATUS_FAILED, Payment::STATUS_EXPIRED], true);

        if (! $failed && ! $announcedFailure) {
            return;
        }

        $subscription = Subscription::query()
            ->where('provider', $payment->provider)
            ->where('provider_id', $remote->subscriptionId)
            ->first();

        if (! $subscription || ! $subscription->isLive()) {
            // Eine beendete Vereinbarung wird nicht angemahnt. Ohne diese Zeile
            // bekaeme jemand, der gerade gekuendigt hat, drei Mahnungen.
            //
            // Gesagt wird es trotzdem, und zwar genau fuer den Fall, der teuer
            // ist: die Zeile steht lokal auf gekuendigt, weil `Dunning::end()`
            // sie beendet hat — und der Anbieter bucht weiter ab, weil seine
            // Kuendigung scheiterte. Der einzige Hinweis darauf war bisher eine
            // einzige Zeile im Augenblick des Fehlschlags; jeder weitere Zyklus
            // verschwand danach kommentarlos hier. Ein Anbieter, der zu einem
            // hier beendeten Abo noch Zyklen schickt, ist eine Meldung wert.
            if ($subscription) {
                Log::warning('statamic-payments: a cycle arrived for an agreement this site has already ended; the provider may still be charging it.', [
                    'subscription_id' => $subscription->getKey(),
                    'status' => $subscription->status,
                    'provider_status' => $remote->status,
                ]);
            }

            return;
        }

        SubscriptionCycleFailed::dispatch($subscription, $payment->fresh() ?? $payment, $remote->status);
    }

    /** The provider's own answer, or null if it would not give one. */
    protected function fetch(string $providerId): ?RemotePayment
    {
        try {
            return $this->gateway->fetch($providerId);
        } catch (ProviderUnavailable $e) {
            // Not swallowed, and this is the one exception to the rule below.
            //
            // "The provider would not answer" covers two very different things:
            // an id this account never issued, and an outage. For Mollie the
            // difference does not matter, because Mollie redelivers on its own
            // schedule whatever this endpoint says. For Stripe it decides
            // everything: the event id is claimed before this runs, so a
            // delivery that returns quietly during an outage never comes back —
            // not on a retry, not from the Resend button. A buyer paid, and the
            // only trace is a warning nobody reads.
            //
            // So a gateway that says "ask again later" is allowed to say it,
            // and the caller decides what to do about it.
            throw $e;
        } catch (Throwable $e) {
            // Most often a 404 for an id this account never issued, which is
            // the ordinary shape of a stray or forged call. A real outage looks
            // the same from here; the provider redelivers, so nothing is lost.
            Log::warning('statamic-payments: the provider would not answer for this id.', [
                'provider_id' => $providerId,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The row the provider is carrying our own id for.
     *
     * The rescue for a checkout that died mid-flight: `metadata.payment_id` is
     * sent to the provider precisely so a payment can still be recognised when
     * the provider id never made it back into the row.
     */
    protected function recover(string $providerId, ?RemotePayment $remote): ?Payment
    {
        $id = $remote?->metadata['payment_id'] ?? null;

        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return null;
        }

        $payment = Payment::query()
            ->where('provider', $this->gateway->provider())
            ->whereKey((int) $id)
            ->first();

        if (! $payment) {
            return null;
        }

        Log::warning('statamic-payments: recovered a payment by metadata; the provider id was never stored.', [
            'payment_id' => $payment->getKey(),
            'provider_id' => $providerId,
        ]);

        $payment->forceFill(['provider_id' => $providerId])->save();

        return $payment->fresh() ?? $payment;
    }

    /**
     * A row for a cycle the provider charged without being asked.
     *
     * Everything about it is taken from the agreement, never from the webhook:
     * the product, the amount, the currency and who it is for. A forged call
     * naming a real subscription id therefore cannot invent an amount — the
     * worst it can do is create a row for a payment the provider will not
     * confirm, and `isPaid()` is checked afterwards.
     *
     * **Hier gibt es keine aufrufende Strecke und deshalb keine Naht.** Der
     * Anbieter treibt, niemand kann dieser Zahlung etwas mitgeben. Was sie
     * trotzdem braucht, wird geerbt: was der Aufrufer der ersten Zahlung dieses
     * Abos an `meta` mitgab, gilt auch für ihre Zyklen. Sonst hätte eine
     * Abo-Rechnung ab dem zweiten Monat keine Anschrift, und das ist genau der
     * Beleg, der über 250 EUR eine Pflichtangabe vermissen lässt — bei der
     * Umsatzart, die diese Grenze am ehesten reißt.
     *
     * Das Land wird **nicht** geerbt. Es trägt der Anbieter nach, noch vor
     * `PaymentPaid`, und was der Kartenherausgeber sagt, ist der bessere
     * Nachweis. Ein geerbtes Land stünde ihm im Weg.
     */
    protected function openCycle(string $providerId, ?RemotePayment $remote): ?Payment
    {
        if (! $remote?->subscriptionId) {
            return null;
        }

        $subscription = Subscription::query()
            ->where('provider', $this->gateway->provider())
            ->where('provider_id', $remote->subscriptionId)
            ->first();

        if (! $subscription) {
            // Its own alarm, not the generic "unknown payment id" further up.
            // This is the shape a phantom agreement takes: the provider charging
            // on a rhythm for something this site has no row for. Told apart
            // from a stray webhook by the fact that it names an agreement.
            Log::error('statamic-payments: a cycle arrived for an agreement this site has no record of. Someone may be being charged for nothing.', [
                'provider_subscription_id' => $remote->subscriptionId,
                'provider_id' => $providerId,
            ]);

            return null;
        }

        $payment = Payment::create([
            'provider' => $this->gateway->provider(),
            'provider_id' => $providerId,
            // Geerbt, nicht erfragt. Dieser Zyklus entsteht im Webhook des
            // Anbieters, wo keine Marke gesetzt ist; `Brands::stampId()` gäbe
            // hier 0 zurück und die Zahlung wäre im Kundenbereich für niemanden
            // sichtbar. Sie gehört der Marke, die das Abo verkauft hat.
            'brand_id' => $subscription->brand_id,
            'product' => $subscription->product,
            'amount_cent' => $subscription->amount_cent,
            'currency' => $subscription->currency,
            'status' => Payment::STATUS_OPEN,
            'email' => $remote->email ?: $subscription->email,
            'name' => $subscription->name,
            'customer_reference' => $subscription->customer_reference,
            // Leer, und das mit Absicht: `Subscriptions::recordCycle()` füllt
            // die Spalte mit einem bedingten UPDATE auf `whereNull`, und das
            // ist der Anspruch, an dem eine zweite Zustellung desselben Zyklus
            // scheitert. Sie hier zu setzen hiesse, jeden Zyklus ungezählt zu
            // lassen. Der Zeiger für einen Listener steht deshalb in `meta`.
            'subscription_id' => null,
            'meta' => $this->fromTheFirstPayment($subscription),
        ]);

        // A line, like every other payment has. Without one
        // `Payment::itemsTotalCent()` reads zero for a cycle, and any report
        // built over lines silently leaves out all recurring revenue.
        //
        // **Der Name stand bis 07.09.2026 als roher Handle darin.** Auf der
        // Rechnung eines Zyklus las der Käufer damit `offer:choiraccelerator-raten`
        // statt „ChoirAccelerator" — die Erstzahlung nimmt den Namen aus dem
        // Katalog, und ab der zweiten Rate wechselte die Beschriftung stumm.
        // `InvoiceWriter` druckt `item.name` unverändert, holt für eine
        // vorhandene Position also nichts nach.
        //
        // Dazu die Zuordnung: die wievielte Rate von wie vielen. `times` am Abo
        // ist die Zahl der **verbleibenden** Einzüge nach der ersten (siehe
        // `Subscriptions::startFromPayment()`), das Ganze also `times + 1`. Und
        // `times_charged` zählt die bereits verbuchten Zyklen; die erste Rate
        // war die Erstzahlung, diese hier ist somit `times_charged + 2`.
        // Gezählt wird vor `recordCycle()`, das gleich danach hochzählt.
        $katalog = app(Catalogue::class)->find($subscription->product);
        $name = is_string($katalog['name'] ?? null) && $katalog['name'] !== ''
            ? $katalog['name']
            : $subscription->product;

        PaymentItem::create([
            'payment_id' => $payment->getKey(),
            'product' => $subscription->product,
            'name' => Subscriptions::lineLabel(
                $name,
                (string) $subscription->interval,
                $subscription->times === null ? null : ((int) $subscription->times) + 1,
                ((int) $subscription->times_charged) + 2,
                (int) $subscription->amount_cent,
                $subscription->currency,
            ),
            'amount_cent' => $subscription->amount_cent,
            'quantity' => 1,
            'kind' => PaymentItem::KIND_PRIMARY,
        ]);

        return $payment;
    }

    /**
     * Was ein Zyklus von der ersten Zahlung seines Abos mitbekommt.
     *
     * Zwei Dinge, und beide stehen da, bevor `PaymentPaid` feuert:
     *
     * 1. Die Angaben, die die aufrufende Strecke der ersten Zahlung mitgab.
     *    Die Anschrift ändert sich nicht dadurch, dass ein Monat vergeht.
     * 2. Ein Zeiger auf das Abo. Die Spalte `subscription_id` steht zu diesem
     *    Zeitpunkt noch nicht (siehe oben), ein Listener hätte sonst nichts in
     *    der Hand als die Kundenkennung und eine Rückwärtssuche.
     *
     * Nicht mitgeerbt wird, was das Paket in `meta` selbst führt. Ein
     * `subscription_intent` gehört der Zahlung, die das Abo begonnen hat, und
     * ein `refunds` der Zahlung, die erstattet wurde.
     *
     * @return array<string, mixed>
     */
    protected function fromTheFirstPayment(Subscription $subscription): array
    {
        $first = Payment::query()
            ->where('subscription_id', $subscription->getKey())
            ->orderBy('id')
            ->first();

        $inherited = $first === null ? [] : array_diff_key(
            $first->meta ?? [],
            array_flip(PaymentDetails::RESERVED_META),
        );

        return $inherited + [
            'cycle_of' => array_filter([
                'subscription_id' => $subscription->getKey(),
                'first_payment_id' => $first?->getKey(),
            ], fn ($v) => $v !== null),
        ];
    }

    protected function recordUnpaid(Payment $payment, RemotePayment $remote): void
    {
        if ($payment->isFulfilled()) {
            // A fulfilled payment is not downgraded. `normalise()` turns every
            // status this package has not met into `open`, so without this line
            // one unfamiliar provider status would reopen a finished order —
            // and a listener on PaymentFailed would revoke access from someone
            // who paid.
            return;
        }

        if ($payment->status !== $remote->status) {
            $payment->forceFill(['status' => $remote->status])->save();
            $payment = $payment->fresh() ?? $payment;
        }

        if (! in_array($remote->status, [Payment::STATUS_FAILED, Payment::STATUS_EXPIRED, Payment::STATUS_CANCELED], true)) {
            return;
        }

        // Same conditional-update claim as fulfilment, for the same reason: two
        // deliveries arriving together both read `open` and would both announce
        // the failure. A "your payment failed" mail twice is a support ticket.
        $claimed = Payment::query()
            ->whereKey($payment->getKey())
            ->whereNull('failed_notified_at')
            ->update(['failed_notified_at' => now(), 'updated_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        PaymentFailed::dispatch($payment->fresh() ?? $payment);
    }

    /**
     * Fulfil a payment there was never anything to charge for.
     *
     * There is no provider to ask, so the usual "never believe the caller" rule
     * has nothing to check against. That is safe here for one reason and it is
     * worth stating: this is only reachable from `Checkout::start()`, after the
     * **catalogue** priced the basket at zero. Nothing a browser sent decided
     * that. It goes through the same claim as every other fulfilment, so a
     * double submit still delivers once.
     */
    public function fulfilFree(Payment $payment): Payment
    {
        return $this->fulfilOnce($payment, new RemotePayment(
            providerId: (string) $payment->provider_id,
            status: Payment::STATUS_PAID,
            metadata: ['free' => true],
            email: $payment->email,
        ));
    }

    protected function fulfilOnce(Payment $payment, RemotePayment $remote): Payment
    {
        // The claim is staked in the database, not in PHP. A read-then-write
        // ("is it fulfilled? no, then fulfil") loses to a second request that
        // reads before the first writes — which is exactly what a redelivery
        // arriving twice within milliseconds looks like.
        $claimed = Payment::query()
            ->whereKey($payment->getKey())
            ->whereNull('fulfilled_at')
            ->update([
                'status' => Payment::STATUS_PAID,
                'paid_at' => now(),
                'fulfilled_at' => now(),
                'updated_at' => now(),
            ]);

        $payment = $payment->fresh() ?? $payment;

        if ($claimed === 0) {
            // Someone else already has it. Not an error — the ordinary result
            // of a provider doing its job — so it is silent.
            return $payment;
        }

        if ($remote->email && ! $payment->email) {
            $payment->forceFill(['email' => $remote->email])->save();
            $payment = $payment->fresh() ?? $payment;
        }

        // Das Land, aber nur wenn keines da ist.
        //
        // Was der Anbieter sagt, ist der bessere Beleg — es kommt vom
        // Kartenherausgeber oder der Bank und ist damit einer der zwei
        // Nachweise, die die EU bei einer digitalen Leistung an Verbraucher
        // verlangt. Ein bereits eingefrorenes Land wird trotzdem nicht
        // ueberschrieben: eine Rechnung, die sich nachtraeglich aendert, ist
        // keine.
        if ($remote->country && ! $payment->country) {
            $payment->forceFill([
                'country' => $remote->country,
                'country_source' => $payment->provider,
            ])->save();
            $payment = $payment->fresh() ?? $payment;
        }

        // Woran der Kaeufer seine Karte wiedererkennt, aber nur wenn noch
        // nichts da ist.
        //
        // Gebraucht wird es erst spaeter, auf der Seite eines
        // Nachfassangebots: die darf nicht abbuchen, ohne vorher zu sagen,
        // womit. Zu holen ist es aber nur jetzt, waehrend der Anbieter die
        // Zahlung noch beschreibt.
        //
        // **Jedes Feld für sich, und nur was der Anbieter für genau diese
        // Zahlung belegt.** Vorher hingen beide an den vier Ziffern: nennt der
        // Anbieter die Kartenmarke ohne Nummer — bei Wallet-Zahlungen der
        // Normalfall —, ging die Marke verloren; und nannte er die Nummer ohne
        // Marke, wurde eine bereits eingetragene Marke mit `null`
        // überschrieben. Auf der Seite eines Nachfassangebots steht dann
        // entweder nichts oder etwas, das aus zwei Antworten
        // zusammengesetzt ist. Beides ist eine Behauptung, keine Auskunft.
        //
        // Eingefroren bleibt eingefroren: was einmal steht, wird von einer
        // späteren Antwort nicht mehr geändert. Der Anbieter beschreibt bei
        // einer Folgeabbuchung die Karte des Mandats, nicht die der
        // Erstzahlung — die beiden dürfen sich nicht gegenseitig überschreiben.
        // Leer zählt wie nicht gesetzt. Eine Spalte, in der ein `''` steht — aus
        // einer älteren Fassung, aus einem Import —, wäre sonst für immer
        // gesperrt: sie sieht belegt aus und sagt nichts.
        $frei = static fn (?string $wert): bool => $wert === null || trim($wert) === '';

        $karte = array_filter([
            'card_last4' => $frei($payment->card_last4) ? $remote->cardLast4 : null,
            'card_label' => $frei($payment->card_label) ? $remote->cardLabel : null,
            // Womit abgebucht werden darf, nach genau derselben Regel wie die
            // beiden Zeilen darueber: nur von der Zahlung, die das Mandat
            // erteilt hat, und einmal eingefroren nicht mehr geaendert.
            //
            // Das Einfrieren ist hier nicht Kosmetik, sondern der Punkt. Eine
            // Folgeabbuchung bekommt von Mollie ihr eigenes `mandateId`
            // zurueck; duerfte die Antwort zurueckschreiben, wanderte die
            // Kennung der Erstzahlung weg — und die naechste Abbuchung ginge
            // wieder gegen ein Mandat, das die Seite nie angekuendigt hat.
            //
            // Bewusst hier und nicht als Waechter im Model: der `updating`-
            // Waechter dort traegt eine Fehlermeldung nach § 356 Abs. 5 BGB und
            // gehoert der Zustimmung. Das Einfrieren haelt hier, weil diese
            // Methode die einzige Stelle ist, die die Spalte schreibt —
            // `PaymentDetails::ALLOWED` sperrt jeden Aufrufer aus, und
            // `Checkout::resume()` kopiert sie nicht mit. Wer das aendert,
            // braucht dann doch einen Waechter.
            'mandate_id' => $frei($payment->mandate_id) ? $remote->mandateId : null,
        ], static fn (?string $wert): bool => $wert !== null && trim($wert) !== '');

        if ($karte !== []) {
            $payment->forceFill($karte)->save();
            $payment = $payment->fresh() ?? $payment;
        }

        if (! $payment->email) {
            // Paid, and nowhere to deliver to. Not a reason to refuse the
            // money, but a listener that delivers by mail is about to have
            // nothing to work with, and that must not pass in silence.
            Log::warning('statamic-payments: paid without an address; delivery by email is not possible.', [
                'payment_id' => $payment->getKey(),
                'provider_id' => $payment->provider_id,
            ]);
        }

        // Wer doch noch bezahlt, ist nicht mehr abgesprungen. Vor dem Ereignis,
        // damit ein Listener, der `abandoned_notified_at` liest, die Wahrheit
        // sieht und nicht die Geschichte.
        app(Abandonment::class)->settled($payment);

        try {
            PaymentPaid::dispatch($payment);
        } catch (Throwable $e) {
            // Give the claim back and let the provider try again. The
            // alternative — keeping it — books the order as fulfilled while
            // nothing was delivered, and no retry ever comes.
            Payment::query()
                ->whereKey($payment->getKey())
                ->update(['fulfilled_at' => null, 'updated_at' => now()]);

            Log::error('statamic-payments: a listener threw; the fulfilment claim was released for redelivery.', [
                'payment_id' => $payment->getKey(),
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->followTheAgreement($payment, $remote);

        return $payment->fresh() ?? $payment;
    }

    /**
     * Keep the subscription row in step with the money.
     *
     * **After** fulfilment, deliberately. A cycle is first and foremost a
     * payment: it grants what it grants through `PaymentPaid`, exactly like a
     * one-off, so a listener needs to know nothing about subscriptions. What
     * happens here is bookkeeping on the agreement, and bookkeeping must never
     * stand between a customer and the thing they paid for.
     *
     * Nothing here throws. A subscription that could not be recorded is a row
     * to repair; a fulfilment claim released over it would make the provider
     * redeliver and the customer receive everything twice.
     */
    protected function followTheAgreement(Payment $payment, RemotePayment $remote): void
    {
        $subscriptions = app(Subscriptions::class);

        try {
            // A payment the provider made on its own: a cycle of a running
            // agreement. The id comes from the provider, never from the caller.
            if ($remote->subscriptionId) {
                $subscriptions->recordCycle($payment, $remote->subscriptionId);

                return;
            }

            // A first payment that carried the intention to start one. Only now
            // is there a mandate to build it on.
            $subscriptions->startFromPayment($payment);
        } catch (Throwable $e) {
            Log::error('statamic-payments: the agreement could not be brought up to date; the payment stands.', [
                'payment_id' => $payment->getKey(),
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
