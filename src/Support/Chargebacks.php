<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Events\PaymentChargedBack;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recording that the bank took the money back.
 *
 * Same shape as {@see Refunds}, and deliberately not the same column. A refund
 * is a decision somebody here made; a chargeback is one made against them, it
 * carries a fee, and it can still be won. Adding it to `refunded_cent` would
 * put two different things behind one number and make every revenue report
 * quietly wrong about both.
 *
 * **The insert is the claim.** A provider may announce the same dispute twice —
 * Stripe redelivers until it gets a 2xx, and a Mollie payment webhook arrives
 * for every state change on a payment that has already been charged back. Read
 * then write would let two deliveries milliseconds apart both revoke access and
 * both fire the event, and "your access was withdrawn" twice is a support
 * ticket. A unique index is a condition every engine enforces; a row lock is
 * not (`lockForUpdate()` compiles to nothing on SQLite).
 *
 * **The invoice is left alone.** Cancelling one says the sale did not happen,
 * and that is a human decision — the same line this package holds on refunds.
 */
class Chargebacks
{
    /**
     * Note a chargeback against a payment.
     *
     * @param  string  $reference  the provider's own id for the dispute
     * @param  int  $amountCent  in minor units; zero where the provider did not say
     * @return bool whether this call was the one that recorded it
     */
    public function record(Payment $payment, string $reference, int $amountCent = 0, ?string $reason = null): bool
    {
        $reference = trim($reference);

        if ($reference === '') {
            // Without a reference there is nothing to be idempotent about, and
            // a chargeback booked twice revokes access twice. Refused rather
            // than booked blind.
            return false;
        }

        if (! $this->claim($payment, $reference, max(0, $amountCent), $reason)) {
            return false;
        }

        // Set once. A second dispute on the same payment does not move the
        // date — the first is when this order stopped being money.
        Payment::query()
            ->whereKey($payment->getKey())
            ->whereNull('charged_back_at')
            ->update(['charged_back_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        $frisch = $payment->fresh() ?? $payment;

        PaymentChargedBack::dispatch($frisch, $reference, max(0, $amountCent), $reason);

        return true;
    }

    /** Whether this payment has been disputed at all. */
    public function chargedBack(Payment $payment): bool
    {
        return $payment->charged_back_at !== null;
    }

    protected function claim(Payment $payment, string $reference, int $amountCent, ?string $reason): bool
    {
        try {
            DB::table('payment_chargebacks')->insert([
                'payment_id' => $payment->getKey(),
                'reference' => mb_substr($reference, 0, 191),
                'amount_cent' => $amountCent,
                'reason' => $reason === null ? null : mb_substr($reason, 0, 191),
                'created_at' => Carbon::now(),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
