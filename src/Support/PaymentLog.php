<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Events\PaymentCommunicationLogged;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentCommunication;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Das Kommunikationsprotokoll: was zu einer Zahlung hinausging.
 *
 * Eine Zeile je Ereignis, nie geändert. Dieses Paket schreibt seine eigenen
 * Mails hinein (Portal-Link, Eingangsbestätigungen, Abbruch-Mail); Nachbarn
 * und der Host schreiben ihre dazu:
 *
 *     PaymentLog::mail($payment, 'invoice', $to, $subject);
 *     PaymentLog::note($payment, 'support', 'Kunde hat angerufen, Zugang manuell verlängert.');
 *
 * **Ein Fehler beim Schreiben bricht nichts.** Die Tabelle kann fehlen (die
 * Migration ist noch nicht gelaufen), die Verbindung kann weg sein — in beiden
 * Fällen ist die Mail trotzdem raus, und ein Kaufpfad, der daran scheitert,
 * dass sein Protokoll nicht schreibt, wäre der falsche Handel. Deshalb wird
 * gefangen und **laut** ins Log geschrieben, nicht still verschluckt: ein
 * Protokoll, das lückenhaft ist und es nicht sagt, ist schlimmer als keines.
 */
class PaymentLog
{
    /**
     * Eine Mail, die zu dieser Zahlung ging.
     *
     * @param  array<string, mixed>  $meta
     */
    public function mail(Payment|int $payment, string $kind, string $to, ?string $subject = null, string $status = PaymentCommunication::STATUS_SENT, array $meta = [], ?string $reference = null): ?PaymentCommunication
    {
        return $this->record($payment, PaymentCommunication::CHANNEL_MAIL, $kind, [
            'recipient' => $to,
            'subject' => $subject,
            'status' => $status,
            'reference' => $reference,
            'meta' => $meta,
        ]);
    }

    /**
     * Eine Notiz von Hand oder von einem System, das nichts verschickt hat.
     *
     * @param  array<string, mixed>  $meta
     */
    public function note(Payment|int $payment, string $kind, string $text, array $meta = []): ?PaymentCommunication
    {
        return $this->record($payment, PaymentCommunication::CHANNEL_NOTE, $kind, [
            'subject' => mb_substr($text, 0, 255),
            'status' => PaymentCommunication::STATUS_SENT,
            'meta' => $meta + ['text' => $text],
        ]);
    }

    /**
     * Der allgemeine Eintrag, für jeden Kanal.
     *
     * @param  array{recipient?: string|null, subject?: string|null, status?: string, reference?: string|null, meta?: array<string, mixed>, happened_at?: Carbon|null}  $attributes
     */
    public function record(Payment|int $payment, string $channel, string $kind, array $attributes = []): ?PaymentCommunication
    {
        try {
            [$paymentId, $brandId] = $this->identify($payment);

            if ($paymentId === null) {
                Log::warning('statamic-payments: a communication was not logged because the payment could not be identified.', [
                    'kind' => $kind,
                    'channel' => $channel,
                ]);

                return null;
            }

            $status = (string) ($attributes['status'] ?? PaymentCommunication::STATUS_SENT);

            $communication = PaymentCommunication::create([
                'payment_id' => $paymentId,
                'brand_id' => $brandId,
                'channel' => in_array($channel, PaymentCommunication::channels(), true) ? $channel : PaymentCommunication::CHANNEL_NOTE,
                'kind' => mb_substr(trim($kind), 0, 64),
                'recipient' => self::clip($attributes['recipient'] ?? null, 191),
                'subject' => self::clip($attributes['subject'] ?? null, 255),
                'status' => in_array($status, PaymentCommunication::statuses(), true) ? $status : PaymentCommunication::STATUS_SENT,
                'reference' => self::clip($attributes['reference'] ?? null, 191),
                'meta' => ($attributes['meta'] ?? []) === [] ? null : $attributes['meta'],
                'happened_at' => $attributes['happened_at'] ?? Carbon::now(),
            ]);

            PaymentCommunicationLogged::dispatch($communication);

            return $communication;
        } catch (Throwable $e) {
            // Laut. Die Mail ist raus, das Protokoll hat eine Lücke, und wer
            // das Log liest, soll wissen, dass die Lücke kein „nichts
            // verschickt" ist.
            Log::warning('statamic-payments: a communication could not be written to the payment log.', [
                'payment' => $payment instanceof Payment ? $payment->getKey() : $payment,
                'kind' => $kind,
                'channel' => $channel,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Alles, was zu einer Zahlung protokolliert ist, neueste zuerst.
     *
     * @return Collection<int, PaymentCommunication>
     */
    public function for(Payment|int $payment): Collection
    {
        $id = $payment instanceof Payment ? (int) $payment->getKey() : $payment;

        try {
            return PaymentCommunication::query()
                ->where('payment_id', $id)
                ->orderByDesc('happened_at')
                ->orderByDesc('id')
                ->get();
        } catch (Throwable $e) {
            Log::warning('statamic-payments: the payment log could not be read.', [
                'payment' => $id,
                'exception' => $e->getMessage(),
            ]);

            return new Collection;
        }
    }

    /**
     * @return array{0: int|null, 1: int}
     */
    protected function identify(Payment|int $payment): array
    {
        if ($payment instanceof Payment) {
            return [$payment->getKey() === null ? null : (int) $payment->getKey(), (int) $payment->brand_id];
        }

        $row = Payment::query()->whereKey($payment)->first(['id', 'brand_id']);

        return $row === null ? [null, 0] : [(int) $row->getKey(), (int) $row->brand_id];
    }

    private static function clip(mixed $value, int $length): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
