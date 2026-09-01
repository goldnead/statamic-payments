<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Contracts\FollowUpGateway;
use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        return $this->available()
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
                'provider' => $this->gateway->provider(),
                'provider_id' => 'pending-'.Str::uuid(),
                // Von der ersten Bestellung geerbt. Ein Nachfassangebot wird
                // auch aus einem Hintergrundlauf angenommen, wo keine Marke
                // gesetzt ist — und es gehört derselben Marke wie die
                // Bestellung, aus der es entstanden ist.
                'brand_id' => $original->brand_id,
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
                'name' => $product['name'],
                'amount_cent' => $product['amount_cent'],
                'quantity' => 1,
                'kind' => PaymentItem::KIND_UPSELL,
                'meta' => $context === [] ? null : $context,
            ]);

            return $payment;
        });

        try {
            /** @var FollowUpGateway $gateway */
            $gateway = $this->gateway;

            $remote = $gateway->chargeAgain((string) $original->customer_reference, [
                'amount' => [
                    'currency' => $payment->currency,
                    'value' => $payment->amount(),
                ],
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
