<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Events\PaymentChargedBack;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        $amountCent = max(0, $amountCent);

        // Der Anspruch und der Zustand in **einer** Transaktion.
        //
        // Getrennt geschrieben waeren es zwei Schritte, und der Prozess kann
        // zwischen ihnen sterben — ein OOM-Kill, ein Timeout, ein Deploy
        // mitten im Webhook-Request. Dann staende die Anspruchszeile, aber
        // `charged_back_at` bliebe fuer immer null, das Ereignis feuerte nie,
        // der Zugang bliebe bestehen. Und jede weitere Zustellung faende den
        // Anspruch belegt und gaebe still `false` zurueck: ein Kaeufer mit
        // zurueckgeholtem Geld und offenem Zugang, ohne eine einzige Zeile
        // irgendwo.
        $gebucht = DB::transaction(function () use ($payment, $reference, $amountCent, $reason): bool {
            if (! $this->claim($payment, $reference, $amountCent, $reason)) {
                // Schon beansprucht. Das heisst normalerweise „schon erledigt",
                // aber nicht immer: ein abgebrochener frueherer Versuch aus der
                // Zeit vor dieser Transaktion kann eine Anspruchszeile ohne
                // Zustand hinterlassen haben. Steht der Zustand noch aus, wird
                // er hier nachgeholt, statt den Fall fuer immer zu verschweigen.
                return $this->finishUnclaimed($payment);
            }

            // Set once. A second dispute on the same payment does not move the
            // date — the first is when this order stopped being money.
            Payment::query()
                ->whereKey($payment->getKey())
                ->whereNull('charged_back_at')
                ->update(['charged_back_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

            return true;
        });

        if (! $gebucht) {
            return false;
        }

        // Nach dem Commit, nie darin. Ein Listener, der den Zugang entzieht,
        // darf nicht in einer Transaktion laufen, die noch zurueckgerollt
        // werden koennte.
        PaymentChargedBack::dispatch($payment->fresh() ?? $payment, $reference, $amountCent, $reason);

        return true;
    }

    /** Whether this payment has been disputed at all. */
    public function chargedBack(Payment $payment): bool
    {
        return $payment->charged_back_at !== null;
    }

    /**
     * Einen halb erledigten Anspruch zu Ende bringen.
     *
     * Die Anspruchszeile steht, der Zustand nicht — der Rest eines Versuchs,
     * der zwischen den beiden Schreibvorgaengen abgebrochen ist. Das bedingte
     * UPDATE entscheidet: setzt es die Zeit, war dieser Aufruf derjenige, der
     * die Rueckbuchung wirksam gemacht hat, und das Ereignis gehoert gefeuert.
     * Findet es die Zeit gesetzt, war wirklich schon alles getan.
     */
    protected function finishUnclaimed(Payment $payment): bool
    {
        $nachgeholt = Payment::query()
            ->whereKey($payment->getKey())
            ->whereNull('charged_back_at')
            ->update(['charged_back_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        if ($nachgeholt === 0) {
            return false;
        }

        Log::warning('statamic-payments: a chargeback had been claimed but never applied; it was completed on a later delivery.', [
            'payment_id' => $payment->getKey(),
        ]);

        return true;
    }

    /**
     * Take this chargeback, or find it is already taken.
     *
     * Der Einsatz steht in einer **eigenen, verschachtelten** Transaktion, und
     * das ist kein Zierrat: auf Postgres bricht eine fehlgeschlagene Anweisung
     * die ganze Transaktion ab, und jede weitere Abfrage darin scheitert mit
     * `25P02`, bis zurueckgerollt wird. Ohne den Sicherungspunkt starb der
     * Aufrufer also genau dann, wenn die Rueckbuchung schon bekannt war — der
     * haeufigste Fall ueberhaupt, weil beide Anbieter erneut zustellen.
     *
     * Laravel legt fuer eine verschachtelte `DB::transaction()` einen
     * SAVEPOINT an und rollt bei einer Ausnahme nur bis dorthin zurueck. Die
     * aeussere Transaktion bleibt damit brauchbar, auf jeder Engine.
     *
     * SQLite und MySQL verzeihen das Fehlen; Postgres nicht. Gefunden hat es
     * der Treiber-Job in der CI, nicht das Nachdenken.
     */
    protected function claim(Payment $payment, string $reference, int $amountCent, ?string $reason): bool
    {
        try {
            DB::transaction(fn () => DB::table('payment_chargebacks')->insert([
                'payment_id' => $payment->getKey(),
                'reference' => mb_substr($reference, 0, 191),
                'amount_cent' => $amountCent,
                'reason' => $reason === null ? null : mb_substr($reason, 0, 191),
                'created_at' => Carbon::now(),
            ]));

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
