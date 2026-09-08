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

        // Gelesen und geschrieben unter derselben Zeilensperre, in einer
        // Transaktion.
        //
        // Vorher lag die Pruefung ausserhalb: „steht die Referenz schon drin,
        // und wieviel ist noch offen" wurde auf dem Objekt beantwortet, das der
        // Aufrufer in der Hand hielt, und danach blind gespeichert. Solange nur
        // Mollie erstattete, kam nie mehr als eine Meldung gleichzeitig an.
        // Stripe schickt zwei Teilerstattungen als zwei Ereignisse, die
        // parallel in zwei Prozessen landen koennen — und dann gewinnt der
        // zweite Schreibvorgang ueber den ersten hinweg: der erste Betrag ist
        // aus `refunded_cent` verschwunden, seine Referenz aus `meta`, und die
        // naechste Meldung bucht ihn ein zweites Mal. Am Ende steht in der
        // Jahresauswertung eine Zahl, die nie jemand ueberwiesen hat.
        $betrag = DB::transaction(function () use ($payment, $amountCent, $reference): int {
            $zeile = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->first();

            if (! $zeile) {
                return 0;
            }

            if ($reference !== null && $this->alreadyRecorded($zeile, $reference)) {
                return 0;
            }

            // Nie mehr zurueck als vorne rein. Eine Ueberzahlung ist ein Fehler
            // beim Anbieter oder beim Aufrufer, und sie hier durchzulassen
            // erzeugte eine Bestellung mit negativem Erloes, die jede Auswertung
            // still verfaelscht.
            $offen = max(0, $zeile->amount_cent - $zeile->refunded_cent);
            $betrag = min($amountCent, $offen);

            if ($betrag <= 0) {
                return 0;
            }

            $zeile->forceFill([
                'refunded_cent' => $zeile->refunded_cent + $betrag,
                'refunded_at' => Carbon::now(),
                'meta' => $this->withReference($zeile, $reference),
            ])->save();

            return $betrag;
        });

        if ($betrag <= 0) {
            return false;
        }

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
