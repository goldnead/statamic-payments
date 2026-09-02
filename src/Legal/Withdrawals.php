<?php

namespace Goldnead\StatamicPayments\Legal;

use Goldnead\StatamicPayments\Facades\PaymentLog;
use Goldnead\StatamicPayments\Legal\Mail\WithdrawalNotice;
use Goldnead\StatamicPayments\Legal\Mail\WithdrawalReceipt;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Withdrawal;
use Goldnead\StatamicPayments\Portal\EmailAddress;
use Goldnead\StatamicPayments\Support\Brands;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Der Widerruf nach § 356a BGB, in zwei Schritten.
 *
 * Das Gesetz schreibt die Form vor, und das hier ist diese Form: eine Erklärung
 * (Schritt 1, `declare()`), eine Bestätigung (Schritt 2, `confirm()`), und
 * unverzüglich danach eine Eingangsbestätigung mit Zeitangabe. Was der Code
 * besitzt, ist die Reihenfolge; was die Sprachdateien besitzen, ist der Text.
 *
 * **Die Zuordnung zur Zahlung ist eine Zutat, kein Türsteher.** Sie passiert
 * nach der Bestätigung, auf dem Server, nur bei eindeutigem Treffer, und ihr
 * Ergebnis erfährt der Händler — nie der Absender. Ein Formular, das auf
 * „diese Bestellung gibt es nicht" antwortet, ist ein Orakel dafür, welche
 * Adresse hier gekauft hat. Ein Formular, das eine Erklärung deshalb
 * zurückweist, nimmt dem Verbraucher den rechtzeitigen Zugang, den § 356a
 * Abs. 5 ihm garantiert. Also geht alles durch und wird gemeldet.
 *
 * Dasselbe gilt für ein **erloschenes** Widerrufsrecht: steht am Treffer eine
 * Zustimmung nach § 356 Abs. 5 (`consent_at`), wird das dem Händler als
 * Hinweis mitgegeben. Ob das Recht wirklich erloschen ist, ist eine Frage an
 * einen Menschen mit dem Vorgang vor sich, nicht an eine Spalte.
 *
 * Rechtliche Entscheidungen 01.09.2026, von Adrian zu prüfen. Keine
 * Rechtsberatung.
 */
class Withdrawals
{
    /**
     * Schritt 1: die Erklärung, wie eingegeben.
     *
     * @param  array{name: string, email: string, order_reference: string, contact?: string|null, message?: string|null}  $input
     */
    public function declare(array $input, ?string $ip): Withdrawal
    {
        $contact = trim((string) ($input['contact'] ?? ''));

        return Withdrawal::create([
            'public_id' => PublicId::make(Withdrawal::PREFIX, fn (string $id) => Withdrawal::query()->where('public_id', $id)->exists()),
            'brand_id' => Brands::stampId(),
            'name' => trim($input['name']),
            'email' => trim($input['email']),
            'order_reference' => trim($input['order_reference']),
            // Das Kontaktmittel, vorbelegt mit der Adresse: § 356a Abs. 2
            // verlangt eines, und wer keins nennt, hat mit seiner Adresse
            // eines genannt.
            'contact' => $contact !== '' ? $contact : trim($input['email']),
            'message' => self::optional($input['message'] ?? null),
            'declared_at' => Carbon::now(),
            'ip_hash' => IpHash::of($ip),
        ]);
    }

    /**
     * Schritt 2: der Widerruf.
     *
     * Idempotent. Der Zeitpunkt wird mit einem bedingten UPDATE beansprucht,
     * nicht mit lesen-dann-schreiben: zwei Klicks, ein Reload, ein zweiter Tab
     * — alle kommen hier an, und nur der erste darf die Uhr stellen. Die
     * anderen bekommen die Zeile zurück, wie sie ist, und die Seite zeigt den
     * ersten Zeitpunkt. Ein Widerruf hat einen Eingang, nicht drei.
     */
    public function confirm(Withdrawal $withdrawal): Withdrawal
    {
        $claimed = Withdrawal::query()
            ->whereKey($withdrawal->getKey())
            ->whereNull('confirmed_at')
            ->update(['confirmed_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        if ($claimed !== 1) {
            return $withdrawal->fresh() ?? $withdrawal;
        }

        $withdrawal = $withdrawal->fresh() ?? $withdrawal;

        $this->attach($withdrawal);
        $this->acknowledge($withdrawal);
        $this->notify($withdrawal);

        return $withdrawal->fresh() ?? $withdrawal;
    }

    /**
     * Die Zahlung, die gemeint ist — wenn genau eine passt.
     *
     * Adresse normalisiert wie im Portal, dazu die Kennung: unsere Nummer oder
     * die des Anbieters. Zwei Treffer sind keiner. Eine Zuordnung, die rät,
     * wäre auf einem Händlerbildschirm schlimmer als keine, weil sie eine
     * Entscheidung vorwegnimmt, die ein Mensch treffen muss.
     */
    public function match(string $email, string $reference): ?Payment
    {
        $email = EmailAddress::normalise($email);
        $reference = ltrim(trim($reference), '#');

        if ($email === null || $reference === '') {
            return null;
        }

        $hits = Payment::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->where(function ($q) use ($reference) {
                $q->where('provider_id', $reference);

                if (ctype_digit($reference)) {
                    $q->orWhere('id', (int) $reference);
                }
            })
            ->limit(2)
            ->get();

        return $hits->count() === 1 ? $hits->first() : null;
    }

    /**
     * Ob die Erklärung innerhalb der konfigurierten Frist einging.
     *
     * Ein Hinweis für den Händler, nie ein Grund zur Ablehnung. Null, wenn es
     * keinen Treffer oder kein Zahldatum gibt — dann ist nichts zu rechnen.
     */
    public function withinPeriod(Withdrawal $withdrawal): ?bool
    {
        $payment = $withdrawal->payment;

        if ($payment === null || $withdrawal->confirmed_at === null) {
            return null;
        }

        $start = $payment->paid_at ?? $payment->created_at;

        if ($start === null) {
            return null;
        }

        $days = max(0, (int) config('statamic-payments.withdrawal.days', 14));

        return $withdrawal->confirmed_at->lessThanOrEqualTo($start->copy()->addDays($days)->endOfDay());
    }

    protected function attach(Withdrawal $withdrawal): void
    {
        $payment = $this->match($withdrawal->email, $withdrawal->order_reference);

        if ($payment === null) {
            return;
        }

        $withdrawal->forceFill([
            'payment_id' => $payment->getKey(),
            // Die Marke der Zahlung, nicht die des Formulars: das Formular
            // steht meist außerhalb jedes Markenkontexts.
            'brand_id' => $payment->brand_id,
            'right_expired_hint' => $payment->consent_at !== null,
        ])->save();
    }

    /**
     * Die Eingangsbestätigung nach § 356a Abs. 4 BGB, unverzüglich.
     *
     * Ein Fehler hier macht den Widerruf nicht ungeschehen — er ist zugegangen
     * — und darf ihn nicht so aussehen lassen. Deshalb bleibt `receipt_sent_at`
     * leer, die Seite sagt es dem Verbraucher, und das Log sagt es laut.
     */
    protected function acknowledge(Withdrawal $withdrawal): void
    {
        try {
            $mailable = new WithdrawalReceipt($withdrawal);

            Mail::to($withdrawal->email)->send($mailable);

            $withdrawal->forceFill(['receipt_sent_at' => Carbon::now()])->save();

            // Nur bei zugeordneter Zahlung: das Protokoll hängt an der Zahlung,
            // und ein Widerruf ohne Treffer hat keine, an der es hängen könnte.
            if ($withdrawal->payment_id !== null) {
                PaymentLog::mail($withdrawal->payment_id, 'withdrawal_receipt', $withdrawal->email, $mailable->envelope()->subject, meta: ['withdrawal' => $withdrawal->public_id]);
            }
        } catch (Throwable $e) {
            Log::error('statamic-payments: a withdrawal was received and the acknowledgement could not be sent.', [
                'withdrawal' => $withdrawal->public_id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    protected function notify(Withdrawal $withdrawal): void
    {
        $to = MerchantAddress::for('withdrawal');

        if ($to === null) {
            Log::error('statamic-payments: a withdrawal was received and there is no merchant address to tell. Set statamic-payments.withdrawal.notify.', [
                'withdrawal' => $withdrawal->public_id,
            ]);

            return;
        }

        try {
            $mailable = new WithdrawalNotice($withdrawal, $this->withinPeriod($withdrawal));

            Mail::to($to)->send($mailable);

            $withdrawal->forceFill(['merchant_notified_at' => Carbon::now()])->save();
        } catch (Throwable $e) {
            Log::error('statamic-payments: a withdrawal was received and the merchant could not be notified.', [
                'withdrawal' => $withdrawal->public_id,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        // Eigener Versuch, eigene Meldung — siehe {@see Cancellations::notify()}.
        // Ohne Zuordnung zu einer Zahlung gibt es nichts, woran die Zeile
        // hängen könnte.
        try {
            if ($withdrawal->payment_id !== null) {
                PaymentLog::mail($withdrawal->payment_id, 'withdrawal_notice', $to, $mailable->envelope()->subject, meta: ['withdrawal' => $withdrawal->public_id]);
            }
        } catch (Throwable $e) {
            Log::warning('statamic-payments: the merchant was notified of a withdrawal, but the payment log was not written.', [
                'withdrawal' => $withdrawal->public_id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private static function optional(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
