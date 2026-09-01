<?php

namespace Goldnead\StatamicPayments\Legal;

use Goldnead\StatamicPayments\Facades\PaymentLog;
use Goldnead\StatamicPayments\Legal\Mail\CancellationNotice;
use Goldnead\StatamicPayments\Legal\Mail\CancellationReceipt;
use Goldnead\StatamicPayments\Models\Cancellation;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Portal\EmailAddress;
use Goldnead\StatamicPayments\Support\Brands;
use Goldnead\StatamicPayments\Support\Subscriptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Die Kündigung nach § 312k BGB, erklärt ohne Login.
 *
 * Dieselbe Mechanik wie {@see Withdrawals}, mit einem Unterschied am Ende: ein
 * eindeutig zugeordnetes, **laufendes** Abo wird beim Anbieter gekündigt, über
 * dasselbe `Subscriptions::cancel()`, das auch das Portal nimmt — Anbieter
 * zuerst, Zeile danach. Alles andere bleibt Erklärung und wird gemeldet.
 *
 * Die Eingangsbestätigung geht in jedem Fall raus. § 312k Abs. 2 S. 4 verlangt
 * sie für die Erklärung, nicht für den Erfolg der Zuordnung; und eine
 * Kündigung, die nicht zugeordnet werden konnte, ist zugegangen wie jede
 * andere.
 *
 * **Ein genannter Zeitpunkt in der Zukunft hält die Kündigung beim Anbieter
 * nicht auf.** Entscheidung 01.09.2026, von Adrian zu prüfen: was der Anbieter
 * kündigt, ist die nächste Abbuchung, und eine Abbuchung nach einer
 * zugegangenen Kündigung ist der Schaden, den diese Funktion verhindern soll.
 * Der genannte Zeitpunkt steht in der Zeile und in der Meldung an den Händler,
 * der die Leistung bis dahin selbst fortführt, wenn sie geschuldet ist. Keine
 * Rechtsberatung.
 */
class Cancellations
{
    public function __construct(protected Subscriptions $subscriptions) {}

    /**
     * @param  array{name: string, email: string, identification: string, kind: string, reason?: string|null, effective_at?: string|null}  $input
     */
    public function declare(array $input, ?string $ip): Cancellation
    {
        $effective = self::optional($input['effective_at'] ?? null);

        return Cancellation::create([
            'public_id' => PublicId::make(Cancellation::PREFIX, fn (string $id) => Cancellation::query()->where('public_id', $id)->exists()),
            'brand_id' => Brands::stampId(),
            'name' => trim($input['name']),
            'email' => trim($input['email']),
            'identification' => trim($input['identification']),
            'kind' => in_array($input['kind'], Cancellation::kinds(), true) ? $input['kind'] : Cancellation::KIND_ORDINARY,
            'reason' => self::optional($input['reason'] ?? null),
            'effective_at' => $effective === null ? null : Carbon::parse($effective)->startOfDay(),
            'declared_at' => Carbon::now(),
            'ip_hash' => IpHash::of($ip),
        ]);
    }

    /** Schritt 2: „jetzt kündigen". Idempotent, siehe {@see Withdrawals::confirm()}. */
    public function confirm(Cancellation $cancellation): Cancellation
    {
        $claimed = Cancellation::query()
            ->whereKey($cancellation->getKey())
            ->whereNull('confirmed_at')
            ->update(['confirmed_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        if ($claimed !== 1) {
            return $cancellation->fresh() ?? $cancellation;
        }

        $cancellation = $cancellation->fresh() ?? $cancellation;

        $this->attach($cancellation);
        $this->acknowledge($cancellation);
        $this->notify($cancellation);

        return $cancellation->fresh() ?? $cancellation;
    }

    /** Das Abo, das gemeint ist — wenn genau eines passt. */
    public function match(string $email, string $identification): ?Subscription
    {
        $email = EmailAddress::normalise($email);
        $identification = ltrim(trim($identification), '#');

        if ($email === null || $identification === '') {
            return null;
        }

        $hits = Subscription::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->where(function ($q) use ($identification) {
                $q->where('provider_id', $identification);

                if (ctype_digit($identification)) {
                    $q->orWhere('id', (int) $identification);
                }
            })
            ->limit(2)
            ->get();

        return $hits->count() === 1 ? $hits->first() : null;
    }

    protected function attach(Cancellation $cancellation): void
    {
        $subscription = $this->match($cancellation->email, $cancellation->identification);

        if ($subscription === null) {
            return;
        }

        $cancellation->forceFill([
            'subscription_id' => $subscription->getKey(),
            'brand_id' => $subscription->brand_id,
        ])->save();

        if (! $subscription->isLive()) {
            return;
        }

        // Beim Anbieter gekündigt wird nur, was über die **Anbieter-Kennung**
        // getroffen wurde. Die ist lang und zufällig; unsere laufende Nummer
        // ist es nicht. Wer eine fremde Adresse und die Zahl 105 kennt, darf
        // damit kein Abo beenden — die Erklärung wird zugeordnet, der Händler
        // bekommt „über Kundennummer getroffen, bitte prüfen", und kündigt im
        // Control Panel, wenn es stimmt. (Entscheidung 02.09.2026 nach
        // Kritik, von Adrian zu prüfen. Keine Rechtsberatung.)
        if (! self::matchedByProviderId($cancellation, $subscription)) {
            return;
        }

        // Anbieter zuerst. `Subscriptions::cancel()` schreibt nichts, wenn der
        // Anbieter nicht bestätigt hat, und meldet das selbst ins Log. Hier
        // bleibt dann `provider_cancelled_at` leer, und der Händler sieht in
        // der Meldung, dass er nacharbeiten muss.
        if ($this->subscriptions->cancel($subscription)) {
            $cancellation->forceFill(['provider_cancelled_at' => Carbon::now()])->save();
        }
    }

    /**
     * Ob die Erklärung das Abo über die Kennung des Anbieters benannt hat —
     * und nicht nur über unsere laufende Nummer.
     */
    public static function matchedByProviderId(Cancellation $cancellation, Subscription $subscription): bool
    {
        return ltrim(trim($cancellation->identification), '#') === $subscription->provider_id;
    }

    protected function acknowledge(Cancellation $cancellation): void
    {
        try {
            $mailable = new CancellationReceipt($cancellation);

            Mail::to($cancellation->email)->send($mailable);

            $cancellation->forceFill(['receipt_sent_at' => Carbon::now()])->save();

            // Eine Kündigung hängt an einem Abo, das Protokoll an einer
            // Zahlung: genommen wird die jüngste Zahlung des zugeordneten Abos.
            // Ohne Zuordnung keine Zeile.
            if ($payment = $this->latestPaymentOf($cancellation->subscription_id)) {
                PaymentLog::mail($payment, 'cancellation_receipt', $cancellation->email, $mailable->envelope()->subject, meta: ['cancellation' => $cancellation->public_id]);
            }
        } catch (Throwable $e) {
            Log::error('statamic-payments: a cancellation was received and the acknowledgement could not be sent.', [
                'cancellation' => $cancellation->public_id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    protected function notify(Cancellation $cancellation): void
    {
        $to = MerchantAddress::for('cancellation');

        if ($to === null) {
            Log::error('statamic-payments: a cancellation was received and there is no merchant address to tell. Set statamic-payments.cancellation.notify.', [
                'cancellation' => $cancellation->public_id,
            ]);

            return;
        }

        try {
            Mail::to($to)->send(new CancellationNotice($cancellation));

            $cancellation->forceFill(['merchant_notified_at' => Carbon::now()])->save();
        } catch (Throwable $e) {
            Log::error('statamic-payments: a cancellation was received and the merchant could not be notified.', [
                'cancellation' => $cancellation->public_id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    protected function latestPaymentOf(?int $subscriptionId): ?Payment
    {
        if ($subscriptionId === null) {
            return null;
        }

        return Payment::query()
            ->where('subscription_id', $subscriptionId)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->first();
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
