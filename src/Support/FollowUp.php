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
     * Three conditions, and all three are refusals of the same kind: no
     * agreement, no charge.
     */
    public function eligible(Payment $payment): bool
    {
        return $this->available()
            && $payment->isPaid()
            && is_string($payment->customer_reference)
            && $payment->customer_reference !== '';
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
     */
    public function accept(Payment $original, string $productHandle, array $context = []): ?Payment
    {
        if (! $this->eligible($original)) {
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
        $payment = DB::transaction(function () use ($original, $product, $context): Payment {
            $payment = Payment::create([
                'provider' => $this->gateway->provider(),
                'provider_id' => 'pending-'.Str::uuid(),
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
            ]);

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
                'webhookUrl' => route('statamic-payments.webhook'),
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
}
