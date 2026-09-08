<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Events\PaymentChargedBack;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        // Der Rueckgabewert ist bewusst der **Zeitstempel dieses Aufrufs**, nicht
        // ein blankes `true`: nur wer den Zustand selbst gesetzt hat, darf ihn
        // spaeter zuruecknehmen. Bei einem zweiten Widerspruch auf derselben
        // Zahlung — auf Stripe erreichbar, weil mehrere Charges einer Rechnung
        // auf dieselbe Zeile zeigen und jeder seinen eigenen `dp_…` bekommt —
        // steht `charged_back_at` schon vom ersten. Ein unbedingtes Zuruecknehmen
        // loeschte dann dessen Zustand: das CP zeigte keinen Widerspruch mehr,
        // und die Wache in `Fulfilment` waere aus, die eine zurueckgebuchte
        // Zahlung von der Erfuellung abhaelt.
        $gesetzt = DB::transaction(function () use ($payment, $reference, $amountCent, $reason): Carbon|false|null {
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
            $jetzt = Carbon::now();

            $gesetzt = Payment::query()
                ->whereKey($payment->getKey())
                ->whereNull('charged_back_at')
                ->update(['charged_back_at' => $jetzt, 'updated_at' => $jetzt]);

            // Null Zeilen heisst: ein frueherer Widerspruch hat den Zustand
            // schon gesetzt. Gebucht ist dieser hier trotzdem — die
            // Anspruchszeile steht —, aber der Zustand gehoert nicht uns.
            return $gesetzt > 0 ? $jetzt : null;
        });

        if ($gesetzt === false) {
            return false;
        }

        // Nach dem Commit, nie darin. Ein Listener, der den Zugang entzieht,
        // darf nicht in einer Transaktion laufen, die noch zurueckgerollt
        // werden koennte.
        try {
            PaymentChargedBack::dispatch($payment->fresh() ?? $payment, $reference, $amountCent, $reason);
        } catch (Throwable $e) {
            // Der Zustand steht, das Ereignis ist gestorben — und ohne das
            // Folgende waere das endgueltig. Die Anspruchszeile steht ja, und
            // `charged_back_at` auch: die naechste Zustellung faende beides
            // erledigt, `finishUnclaimed()` gaebe `false`, und das Ereignis
            // wuerde nie wieder gefeuert. Zurueck bliebe ein Kaeufer mit
            // zurueckgeholtem Geld und offenem Zugang, ab der zweiten
            // Zustellung ohne eine einzige Zeile irgendwo.
            //
            // Also den Zustand zuruecknehmen und werfen, wie es `Fulfilment`
            // an der Zwillingsstelle tut. Der Anbieter stellt erneut zu, die
            // Anspruchszeile bleibt und wird zum Wiedereinstieg, und
            // `finishUnclaimed()` feuert das Ereignis dann doch noch.
            //
            // Nur den **eigenen** Zeitstempel: `$gesetzt` ist null, wenn ein
            // frueherer Widerspruch den Zustand gesetzt hat. Ihn dann zu
            // loeschen naehme dem ersten Widerspruch seinen Zustand, und die
            // Wache in `Fulfilment` waere aus.
            try {
                if ($gesetzt instanceof Carbon) {
                    Payment::query()
                        ->whereKey($payment->getKey())
                        ->where('charged_back_at', $gesetzt)
                        ->update(['charged_back_at' => null, 'updated_at' => Carbon::now()]);
                }

                Log::error('statamic-payments: a chargeback was recorded but its event threw; the state was rolled back so a later delivery fires it again.', [
                    'payment_id' => $payment->getKey(),
                    'reference' => $reference,
                    'rolled_back' => $gesetzt instanceof Carbon,
                    'exception' => $e->getMessage(),
                ]);
            } catch (Throwable $rueckname) {
                // Die Zuruecknahme selbst ist gescheitert — oft dieselbe
                // verlorene Verbindung, an der eine Zeile vorher der Listener
                // starb. Ohne diesen Fang ginge `$e` verloren, die Zeile mit
                // Zahlung und Kennung liefe nie, und `charged_back_at` bliebe
                // gesetzt: jede weitere Zustellung faende alles erledigt und
                // das Ereignis feuerte nie wieder. Genau der Ausgang, den der
                // Kommentar oben auszuschliessen behauptet.
                Log::critical('statamic-payments: a chargeback event threw and rolling its state back failed too; the access may still be open. Check this payment by hand.', [
                    'payment_id' => $payment->getKey(),
                    'reference' => $reference,
                    'original' => $e->getMessage(),
                    'rollback' => $rueckname->getMessage(),
                ]);
            }

            // Immer der urspruengliche Fehler, nie der Ersatz: der Anbieter
            // soll erneut zustellen, und wer nachsieht, will wissen, was
            // wirklich passiert ist.
            throw $e;
        }

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
     *
     * Gibt den gesetzten Zeitstempel zurueck, nicht `true` — der Aufrufer darf
     * spaeter nur zuruecknehmen, was er selbst geschrieben hat.
     */
    protected function finishUnclaimed(Payment $payment): Carbon|false
    {
        $jetzt = Carbon::now();

        $nachgeholt = Payment::query()
            ->whereKey($payment->getKey())
            ->whereNull('charged_back_at')
            ->update(['charged_back_at' => $jetzt, 'updated_at' => $jetzt]);

        if ($nachgeholt === 0) {
            return false;
        }

        Log::warning('statamic-payments: a chargeback had been claimed but never applied; it was completed on a later delivery.', [
            'payment_id' => $payment->getKey(),
        ]);

        return $jetzt;
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
