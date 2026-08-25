<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Events\PaymentRefunded;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recording that money went back.
 *
 * This addon does not *make* refunds — that happens in the provider's own
 * dashboard, where somebody with the authority to move money does it, and
 * putting a button for it behind a Control Panel permission would be a way to
 * refund a customer by misclicking. What it does is take note, so that
 * everything downstream can react: access withdrawn, a credit note written,
 * a report that does not count repaid money as revenue.
 *
 * An amount and a time, never a status. An order half repaid is still a paid
 * order — the money moved and the thing was delivered — and a status forced to
 * choose between "paid" and "refunded" would be wrong about the other half.
 */
class Refunds
{
    /**
     * Note a refund against a payment.
     *
     * Idempotent per external reference: a provider re-announcing the same
     * refund must not book it twice, and "the customer was refunded three
     * times" is the kind of number that ends up in an annual return.
     *
     * @param  string|null  $reference  the provider's own id for this refund
     */
    public function record(Payment $payment, int $amountCent, ?string $reference = null): bool
    {
        if ($amountCent <= 0) {
            return false;
        }

        if ($reference !== null && $this->alreadyRecorded($payment, $reference)) {
            return false;
        }

        // Nie mehr zurueck als vorne rein. Eine Ueberzahlung ist ein Fehler
        // beim Anbieter oder beim Aufrufer, und sie hier durchzulassen
        // erzeugte eine Bestellung mit negativem Erloes, die jede Auswertung
        // still verfaelscht.
        $offen = max(0, $payment->amount_cent - $payment->refunded_cent);
        $betrag = min($amountCent, $offen);

        if ($betrag <= 0) {
            return false;
        }

        DB::transaction(function () use ($payment, $betrag, $reference) {
            $payment->forceFill([
                'refunded_cent' => $payment->refunded_cent + $betrag,
                'refunded_at' => Carbon::now(),
                'meta' => $this->withReference($payment, $reference),
            ])->save();
        });

        $frisch = $payment->fresh() ?? $payment;

        PaymentRefunded::dispatch(
            $frisch,
            $betrag,
            $frisch->refunded_cent >= $frisch->amount_cent,
        );

        return true;
    }

    /** Was this exact refund already noted? */
    protected function alreadyRecorded(Payment $payment, string $reference): bool
    {
        $meta = $payment->meta ?? [];

        return in_array($reference, (array) ($meta['refunds'] ?? []), true);
    }

    /**
     * The provider's refund ids, kept so a redelivery is recognisable.
     *
     * @return array<string, mixed>
     */
    protected function withReference(Payment $payment, ?string $reference): array
    {
        $meta = $payment->meta ?? [];

        if ($reference === null) {
            return $meta;
        }

        $meta['refunds'] = array_values(array_unique(
            array_merge((array) ($meta['refunds'] ?? []), [$reference]),
        ));

        return $meta;
    }
}
