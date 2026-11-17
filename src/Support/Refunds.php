<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Events\PaymentRefunded;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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

        // Der Anspruch wird eingetragen, nicht erfragt.
        //
        // Vorher stand die Duplikatpruefung auf `meta['refunds']` und lief als
        // Lesen-dann-Schreiben auf dem Objekt, das der Aufrufer in der Hand
        // hielt. Solange nur Mollie erstattete, kam nie mehr als eine Meldung
        // gleichzeitig an. Stripe schickt zwei Teilerstattungen als zwei
        // Ereignisse, die parallel in zwei Prozessen landen koennen — und dann
        // gewinnt der zweite Schreibvorgang ueber den ersten hinweg: dessen
        // Betrag ist aus `refunded_cent` verschwunden, seine Referenz aus
        // `meta`, und weil ein Stripe-Ereignis die GANZE Erstattungsliste der
        // Belastung mitbringt, bucht die naechste Meldung ihn ein zweites Mal.
        //
        // Eine Zeilensperre reicht dafuer nicht: `lockForUpdate()` erzeugt auf
        // SQLite gar keine Sperrklausel. Ein Unique-Index ist kein Hinweis,
        // sondern eine Bedingung, und die setzt jede Datenbank gleich durch.
        if ($reference !== null && ! $this->claim($payment, $amountCent, $reference)) {
            return false;
        }

        $betrag = $this->book($payment, $amountCent);

        if ($betrag <= 0) {
            // Der Anspruch bleibt stehen. Er sagt wahrheitsgemaess, dass diese
            // Erstattung dieser Bestellung bekannt ist — sie war nur ganz oder
            // teilweise nicht mehr unterzubringen, weil vorne nie so viel
            // hereinkam. Ihn wieder zu loeschen hiesse, dieselbe Meldung beim
            // naechsten Mal erneut zu pruefen.
            return false;
        }

        $frisch = $payment->fresh() ?? $payment;

        try {
            PaymentRefunded::dispatch(
                $frisch,
                $betrag,
                $frisch->refunded_cent >= $frisch->amount_cent,
            );
        } catch (Throwable $e) {
            // Wirft ein Listener (Zugang entziehen, Mail), stuenden Anspruch und
            // Buchung sonst fest: die naechste Zustellung faende die Kennung
            // belegt, gaebe `false` zurueck, und das Ereignis kaeme nie wieder.
            // Geld zurueck, Zugang offen, ohne eine Zeile irgendwo. Also
            // zuruecknehmen und werfen, wie `Chargebacks::record()` und
            // `Fulfilment` an den Zwillingsstellen: der Anbieter stellt erneut
            // zu und die Erstattung wird dann vollstaendig gebucht.
            $this->rollBack($payment, $betrag, $reference, $e);

            throw $e;
        }

        return true;
    }

    /**
     * Eine Buchung zuruecknehmen, deren Ereignis gestorben ist.
     *
     * Nur den eigenen Betrag und den eigenen Anspruch: eine fruehere Erstattung
     * derselben Zahlung bleibt stehen. `refunded_at` wird nur geleert, wenn
     * danach nichts mehr erstattet ist; sonst bleibt der Zeitstempel, die
     * erneute Zustellung setzt ihn ohnehin neu.
     */
    protected function rollBack(Payment $payment, int $betrag, ?string $reference, Throwable $e): void
    {
        try {
            DB::transaction(function () use ($payment, $betrag, $reference): void {
                Payment::query()
                    ->whereKey($payment->getKey())
                    ->where('refunded_cent', '>=', $betrag)
                    ->update([
                        'refunded_cent' => DB::raw('refunded_cent - '.$betrag),
                        'updated_at' => Carbon::now(),
                    ]);

                Payment::query()
                    ->whereKey($payment->getKey())
                    ->where('refunded_cent', 0)
                    ->update(['refunded_at' => null]);

                if ($reference !== null) {
                    DB::table('payment_refunds')
                        ->where('payment_id', $payment->getKey())
                        ->where('reference', mb_substr($reference, 0, 191))
                        ->delete();

                    $zeile = Payment::query()->whereKey($payment->getKey())->first();

                    if ($zeile) {
                        $meta = $this->withReferences($zeile);
                        $meta['refunds'] = array_values(array_filter(
                            $meta['refunds'],
                            static fn ($ref): bool => $ref !== $reference,
                        ));
                        $zeile->forceFill(['meta' => $meta])->saveQuietly();
                    }
                }
            });

            Log::error('statamic-payments: a refund was recorded but its event threw; the booking was rolled back so a later delivery books it again.', [
                'payment_id' => $payment->getKey(),
                'reference' => $reference,
                'exception' => $e->getMessage(),
            ]);
        } catch (Throwable $rueckname) {
            // Oft dieselbe verlorene Verbindung, an der der Listener starb.
            // Ohne diesen Fang ginge der urspruengliche Fehler verloren.
            Log::critical('statamic-payments: a refund event threw and rolling the booking back failed too; the access may still be open. Check this payment by hand.', [
                'payment_id' => $payment->getKey(),
                'reference' => $reference,
                'original' => $e->getMessage(),
                'rollback' => $rueckname->getMessage(),
            ]);
        }
    }

    /**
     * Take this refund, or find it is already taken.
     *
     * The insert is the claim. Bestandszeilen aus der Zeit vor dieser Tabelle
     * fuehren ihre Referenzen noch in `meta['refunds']`; die werden weiter
     * beachtet, damit eine alte Erstattung nach dem Update nicht als neu
     * durchgeht.
     */
    protected function claim(Payment $payment, int $amountCent, string $reference): bool
    {
        if ($this->alreadyRecorded($payment->fresh() ?? $payment, $reference)) {
            return false;
        }

        try {
            DB::table('payment_refunds')->insert([
                'payment_id' => $payment->getKey(),
                'reference' => mb_substr($reference, 0, 191),
                'amount_cent' => $amountCent,
                'created_at' => Carbon::now(),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * Den Betrag gutschreiben, ohne ihn vorher zu lesen.
     *
     * Ein bedingtes UPDATE statt Lesen-Rechnen-Schreiben: die Bedingung haelt
     * die Regel „nie mehr zurueck als vorne rein" auch dann ein, wenn zwei
     * Erstattungen gleichzeitig gutgeschrieben werden. Passt der volle Betrag
     * nicht mehr, wird der Rest gebucht — und wo gar nichts mehr offen ist,
     * bleibt es bei null.
     */
    protected function book(Payment $payment, int $amountCent): int
    {
        return (int) DB::transaction(function () use ($payment, $amountCent): int {
            $betrag = min($amountCent, max(0, $this->outstanding($payment)));

            if ($betrag <= 0) {
                return 0;
            }

            $gebucht = Payment::query()
                ->whereKey($payment->getKey())
                ->whereRaw('refunded_cent + ? <= amount_cent', [$betrag])
                ->update([
                    'refunded_cent' => DB::raw('refunded_cent + '.$betrag),
                    'refunded_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

            if ($gebucht === 0) {
                // Zwischen Lesen und Schreiben hat jemand anders gebucht, und
                // der volle Betrag passt nicht mehr. Die Bedingung hat das
                // abgefangen, statt die Bestellung mit einem negativen Erloes
                // zurueckzulassen — aber der Rest, der noch passt, gehoert
                // gebucht. Ihn hier fallen zu lassen hiesse: der Anspruch steht
                // in `payment_refunds`, verhindert damit jede erneute Meldung,
                // und das Geld taucht nirgends auf.
                return $this->bookRemainder($payment, $amountCent);
            }

            // Nur noch eine Anzeige: die Wahrheit ueber Doppelmeldungen steht
            // jetzt in `payment_refunds`. Deshalb darf ein verlorener Eintrag
            // hier auch nichts mehr doppelt buchen.
            $zeile = Payment::query()->whereKey($payment->getKey())->first();

            if ($zeile) {
                $zeile->forceFill(['meta' => $this->withReferences($zeile)])->saveQuietly();
            }

            return $betrag;
        });
    }

    /**
     * Was noch hineinpasst, nachdem jemand anders dazwischenkam.
     *
     * Ein einziger zweiter Versuch, mit dem Rest, den die Zeile jetzt hergibt.
     * Keine Schleife: wer hier ein drittes Mal verliert, verliert gegen einen
     * Andrang, den ein Wiederholen nicht besser macht, und dann ist gar nichts
     * zu buchen die richtige Antwort.
     */
    protected function bookRemainder(Payment $payment, int $amountCent): int
    {
        $rest = min($amountCent, max(0, $this->outstanding($payment)));

        if ($rest <= 0) {
            return 0;
        }

        $gebucht = Payment::query()
            ->whereKey($payment->getKey())
            ->whereRaw('refunded_cent + ? <= amount_cent', [$rest])
            ->update([
                'refunded_cent' => DB::raw('refunded_cent + '.$rest),
                'refunded_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        return $gebucht === 0 ? 0 : $rest;
    }

    /** Wieviel von dieser Zahlung noch nicht zurueckgegeben ist. */
    protected function outstanding(Payment $payment): int
    {
        $zeile = Payment::query()
            ->whereKey($payment->getKey())
            ->first(['amount_cent', 'refunded_cent']);

        return $zeile === null ? 0 : (int) $zeile->amount_cent - (int) $zeile->refunded_cent;
    }

    /** Was this exact refund already noted, by an older version of this class? */
    protected function alreadyRecorded(Payment $payment, string $reference): bool
    {
        $meta = $payment->meta ?? [];

        return in_array($reference, (array) ($meta['refunds'] ?? []), true);
    }

    /**
     * Die Referenzen, wie sie in der Anspruchstabelle stehen, plus was eine
     * aeltere Fassung schon in `meta` hinterlassen hat.
     *
     * Abgeleitet und nicht angehaengt: ein Anhaengen an ein JSON-Feld ist
     * Lesen-dann-Schreiben und verliert bei zwei gleichzeitigen Erstattungen
     * einen Eintrag. Aus der Tabelle gelesen kommt immer die vollstaendige
     * Liste heraus, egal wer zuerst fertig war.
     *
     * @return array<string, mixed>
     */
    protected function withReferences(Payment $payment): array
    {
        $meta = $payment->meta ?? [];

        $referenzen = DB::table('payment_refunds')
            ->where('payment_id', $payment->getKey())
            ->orderBy('id')
            ->pluck('reference')
            ->all();

        $meta['refunds'] = array_values(array_unique(array_merge(
            array_values(array_filter((array) ($meta['refunds'] ?? []), 'is_string')),
            $referenzen,
        )));

        return $meta;
    }
}
