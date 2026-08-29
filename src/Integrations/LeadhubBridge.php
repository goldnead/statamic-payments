<?php

namespace Goldnead\StatamicPayments\Integrations;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Money;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The optional path from "paid" to "the CRM knows what it was worth".
 *
 * Two facts travel, and they are deliberately two calls rather than one: the
 * purchase as a timeline entry, so somebody looking at a person sees what they
 * did, and the amount as a ledger line, so a segment can ask who has paid more
 * than a hundred euros. A CRM that only knew the story would still not be able
 * to answer the question this bridge exists for — which campaign sold anything.
 *
 * **Nothing here names a class from the sibling.** Not the facade, not the
 * event object, not the model. The coupling is a string, a container lookup and
 * an array — the same shape `statamic-automations` uses, and the reason the
 * tests can stand a fake in without vendoring a CRM. `EntitlementsBridge` is
 * the model for everything else: three conditions before anything happens,
 * every failure logged rather than thrown.
 *
 * Why never thrown: `Fulfilment` catches an exception out of a `PaymentPaid`
 * listener, **releases the fulfilment claim** and rethrows. A CRM that is
 * mid-upgrade would therefore turn into a redelivered webhook and a second
 * attempt at granting access. A line in the log is the whole of the
 * consequence this may have.
 */
class LeadhubBridge
{
    protected const FACADE = '\Goldnead\Leadhub\Facades\LeadHub';

    /** The addon's own name on a contributed ledger line. */
    protected const SOURCE = 'statamic-payments';

    /** Prefixed so it cannot collide with a type the CRM defines itself. */
    protected const TYPE_PURCHASE = 'payments.purchase_completed';

    protected const TYPE_REFUND = 'payments.purchase_refunded';

    /** The attribution columns this addon freezes, in the CRM's own spelling. */
    protected const ATTRIBUTION = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'referrer', 'landing_page',
    ];

    public function available(): bool
    {
        if (! config('statamic-payments.leadhub.enabled', false)) {
            return false;
        }

        $facade = self::FACADE;

        if (! class_exists($facade)) {
            return false;
        }

        try {
            $root = $facade::getFacadeRoot();
        } catch (Throwable) {
            return false;
        }

        // Asked of the object, never of the facade: a facade forwards through
        // `__callStatic` and declares none of what it forwards, so the probe on
        // the facade itself is always false.
        //
        // Both methods, not one. `ingest` has been there for a year and
        // `recordRevenue` has not; an install with the older sibling should get
        // the half that works rather than an error per sale.
        return is_object($root)
            && method_exists($root, 'ingest')
            && method_exists($root, 'recordRevenue');
    }

    /**
     * A sale happened. Put it on the person's timeline and into their total.
     */
    public function recordPurchase(Payment $payment): void
    {
        if (! $this->available()) {
            return;
        }

        $email = is_string($payment->email) ? trim($payment->email) : '';

        if ($email === '') {
            // Nothing to attach it to. The fulfilment already says this loudly.
            return;
        }

        $facade = self::FACADE;
        $reference = $this->referenceFor($payment);

        try {
            // First, because it resolves the contact **or creates one**. A
            // purchase may create a contact — unlike a tracking pixel, which
            // must not — and the ledger call afterwards deliberately refuses to,
            // so the order here is what makes a first-time buyer land at all.
            $facade::ingest([
                'email' => $email,
                'type' => self::TYPE_PURCHASE,
                'summary' => $this->summary('purchase', $payment),
                'source_type' => 'payment',
                'source_id' => (string) $payment->getKey(),
                // One entry per payment. The event fires exactly once per row
                // already, guarded by a conditional update upstream; this is
                // the second net, and the one the database enforces.
                'dedupe_key' => self::TYPE_PURCHASE.':'.$payment->getKey(),
                'occurred_at' => $payment->paid_at,
                'contact' => array_filter([
                    'full_name' => is_string($payment->name) ? trim($payment->name) : null,
                ]),
                // Only ever seeds a contact that has none — the CRM keeps what
                // it already knows. A repeat buyer stays attributed to the
                // campaign that first found them, which is the reading anybody
                // means by "where did this customer come from".
                'attribution' => $this->attributionOf($payment),
                'source' => self::SOURCE,
                'payload' => $this->payload($payment),
            ]);
        } catch (Throwable $e) {
            Log::error('statamic-payments: the purchase could not be written to the CRM timeline; the payment stands.', [
                'payment_id' => $payment->getKey(),
                'exception' => $e->getMessage(),
            ]);

            // Deliberately no return. The ledger line is the more valuable of
            // the two and does not depend on the timeline entry having landed —
            // only on the contact existing, which it may well already.
        }

        try {
            $written = $facade::recordRevenue(
                $email,
                $reference,
                (int) $payment->amount_cent,
                (string) $payment->currency,
                $payment->paid_at,
                self::SOURCE,
                array_filter([
                    'product' => $payment->product,
                    'provider' => $payment->provider,
                    'provider_id' => $payment->provider_id,
                ]),
            );

            if ($written === null) {
                // The contact is missing, which after a successful ingest can
                // only mean the ingest did not happen — an older sibling, or
                // the failure logged just above.
                Log::warning('statamic-payments: no CRM contact to attach the revenue to; the sale is not in anybody\'s total.', [
                    'payment_id' => $payment->getKey(),
                    'reference' => $reference,
                ]);
            }
        } catch (Throwable $e) {
            Log::error('statamic-payments: the revenue could not be recorded in the CRM; the payment stands.', [
                'payment_id' => $payment->getKey(),
                'reference' => $reference,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Money went back. Correct the total and say so on the timeline.
     *
     * The **running** total refunded is handed over, not this one movement:
     * a redelivered webhook then changes nothing, where a delta would subtract
     * twice and leave a customer's lifetime value quietly too low.
     */
    public function recordRefund(Payment $payment, int $amountCent, bool $isFull): void
    {
        if (! $this->available()) {
            return;
        }

        $facade = self::FACADE;
        $reference = $this->referenceFor($payment);

        try {
            $facade::refundRevenue($reference, (int) $payment->refunded_cent);
        } catch (Throwable $e) {
            Log::error('statamic-payments: a refund was recorded but the CRM total was not corrected.', [
                'payment_id' => $payment->getKey(),
                'reference' => $reference,
                'exception' => $e->getMessage(),
            ]);
        }

        $email = is_string($payment->email) ? trim($payment->email) : '';

        if ($email === '') {
            return;
        }

        try {
            $facade::ingest([
                'email' => $email,
                'type' => self::TYPE_REFUND,
                'summary' => $this->summary($isFull ? 'refund_full' : 'refund_partial', $payment, $amountCent),
                'source_type' => 'payment',
                'source_id' => (string) $payment->getKey(),
                // The running total, not the movement: two partial refunds of
                // the same size on the same payment are two facts, and keying
                // on the amount alone would hide the second.
                'dedupe_key' => self::TYPE_REFUND.':'.$payment->getKey().':'.$payment->refunded_cent,
                'occurred_at' => $payment->refunded_at,
                'source' => self::SOURCE,
            ]);
        } catch (Throwable $e) {
            Log::error('statamic-payments: the refund could not be written to the CRM timeline.', [
                'payment_id' => $payment->getKey(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * This addon's stable name for the money in one payment.
     *
     * Namespaced, because the CRM's ledger is shared: an invoice addon, an
     * import and a human may all contribute, and a bare id would eventually
     * mean two different things.
     */
    protected function referenceFor(Payment $payment): string
    {
        return 'payments:payment:'.$payment->getKey();
    }

    /** @return array<string, string> */
    protected function attributionOf(Payment $payment): array
    {
        $values = [];

        foreach (self::ATTRIBUTION as $column) {
            $value = $payment->{$column} ?? null;

            if (is_string($value) && trim($value) !== '') {
                $values[$column] = $value;
            }
        }

        return $values;
    }

    /**
     * The readable line, built here and stored, not rendered later.
     *
     * The CRM shows `summary` as it is; it has no idea what a product is and
     * must never have to look one up to draw a screen.
     */
    protected function summary(string $kind, Payment $payment, ?int $amountCent = null): string
    {
        $betrag = Money::format($amountCent ?? (int) $payment->amount_cent, (string) $payment->currency);
        $produkt = $this->productName($payment);

        return match ($kind) {
            'refund_full' => __('statamic-payments::messages.timeline_refund_full', ['amount' => $betrag]),
            'refund_partial' => __('statamic-payments::messages.timeline_refund_partial', ['amount' => $betrag]),
            default => __('statamic-payments::messages.timeline_purchase', ['product' => $produkt, 'amount' => $betrag]),
        };
    }

    /** What the buyer would recognise, falling back to the handle. */
    protected function productName(Payment $payment): string
    {
        $handle = (string) $payment->product;
        $count = $payment->items->count();

        if ($count > 1) {
            return __('statamic-payments::messages.timeline_product_plus', [
                'product' => $handle,
                'count' => $count - 1,
            ]);
        }

        return $handle;
    }

    /**
     * What a person reading the entry wants to see, in the CRM's own shape.
     *
     * `detail` is its documented convention for readable lines: label/value
     * pairs it renders without knowing what they mean. Everything else lands in
     * the collapsed raw payload, which is where the campaign belongs — useful
     * when somebody goes looking, noise when they are not.
     *
     * @return array<string, mixed>
     */
    protected function payload(Payment $payment): array
    {
        $detail = [
            ['label' => __('statamic-payments::messages.timeline_label_product'), 'value' => (string) $payment->product],
            ['label' => __('statamic-payments::messages.timeline_label_amount'), 'value' => Money::format((int) $payment->amount_cent, (string) $payment->currency)],
        ];

        if ((int) $payment->discount_cent > 0) {
            $detail[] = [
                'label' => __('statamic-payments::messages.timeline_label_discount'),
                'value' => Money::format((int) $payment->discount_cent, (string) $payment->currency)
                    .($payment->discount_code ? ' ('.$payment->discount_code.')' : ''),
            ];
        }

        if ($campaign = $payment->utm_campaign) {
            $detail[] = [
                'label' => __('statamic-payments::messages.timeline_label_campaign'),
                'value' => (string) $campaign,
            ];
        }

        return array_filter([
            'detail' => $detail,
            'payment_id' => $payment->getKey(),
            'product' => $payment->product,
            'amount_cent' => (int) $payment->amount_cent,
            'currency' => $payment->currency,
            'attribution' => $this->attributionOf($payment) ?: null,
        ], fn ($value) => $value !== null);
    }
}
