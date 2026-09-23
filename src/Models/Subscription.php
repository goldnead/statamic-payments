<?php

namespace Goldnead\StatamicPayments\Models;

use Goldnead\StatamicPayments\Support\Brands;
use Goldnead\StatamicPayments\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * An agreement to be charged again, on a rhythm.
 *
 * **One mechanism, three faces**, and saying so is the point of this class:
 *
 * | What a site calls it | `times` | `starts_at` |
 * |---|---|---|
 * | Subscription | null | now |
 * | Payment plan, instalments | a number | now |
 * | Trial | either | in the future |
 *
 * They are not three features. A payment plan is a subscription that stops
 * counting, and a trial is one that starts late. Building them as three would
 * have meant three cancellation paths, three webhook shapes and three ways to
 * get the last instalment wrong.
 *
 * What this row is **not** is the truth about whether money moved. Each cycle is
 * an ordinary `Payment`, fetched from the provider and fulfilled exactly once,
 * by the same code every other payment goes through. This row records the
 * *agreement*; the payments record the money.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $provider
 * @property string $provider_id
 * @property string $customer_reference
 * @property string $product
 * @property int $amount_cent
 * @property string $currency
 * @property string $interval
 * @property int|null $times
 * @property int $times_charged
 * @property string $status
 * @property Carbon|null $starts_at
 * @property Carbon|null $next_payment_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $dunning_started_at
 * @property int $dunning_stage
 * @property Carbon|null $dunning_last_at
 * @property int|null $dunning_payment_id
 * @property Carbon|null $paused_at
 * @property Carbon|null $resumes_at
 * @property Carbon|null $card_expires_at
 * @property Carbon|null $card_checked_at
 * @property string|null $email
 * @property string|null $name
 * @property array<string, mixed>|null $meta
 */
class Subscription extends Model
{
    /** Created here, the provider has not confirmed it. Not yet an agreement. */
    public const STATUS_INITIATED = 'initiated';

    /** Running. The provider will charge on the rhythm. */
    public const STATUS_ACTIVE = 'active';

    /** Agreed, but the first charge is still in the future. A trial. */
    public const STATUS_PENDING = 'pending';

    /** Somebody stopped it. No further charges. */
    public const STATUS_CANCELLED = 'cancelled';

    /** It ran to its end: a payment plan that has paid its last instalment. */
    public const STATUS_COMPLETED = 'completed';

    /** The provider paused it, usually after failed charges. */
    public const STATUS_SUSPENDED = 'suspended';

    /**
     * Somebody paused it. Nothing is charged until it resumes.
     *
     * Not live — the provider charges nothing — and not over either: the
     * agreement stands, can be resumed, and can still be cancelled. That middle
     * is what {@see isRunning()} answers for.
     */
    public const STATUS_PAUSED = 'paused';

    protected $guarded = [];

    /**
     * Whose agreement this is. Same rule as on {@see Payment}: only set where
     * the caller did not say, so that an agreement created in a webhook does
     * not ask which tenant it is standing in — there is none.
     *
     * **The caller does say, for an agreement out of a first payment.** Since
     * 1.24.2 {@see Subscriptions::startFromPayment()} works the brand out with
     * {@see Brands::forCatalogueEntry()}: the sold offer wins, the payment's
     * brand is the fallback where the catalogue names none. Inheritance is the
     * fallback here, not the rule. This hook covers what is left — an agreement
     * built by hand, by a seeder, by an import.
     */
    protected static function booted(): void
    {
        static::creating(function (self $subscription) {
            if ($subscription->getAttribute('brand_id') === null) {
                $subscription->setAttribute('brand_id', Brands::stampId());
            }
        });
    }

    protected function casts(): array
    {
        return [
            'amount_cent' => 'integer',
            'times' => 'integer',
            'times_charged' => 'integer',
            'starts_at' => 'datetime',
            'next_payment_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'ended_at' => 'datetime',
            'dunning_started_at' => 'datetime',
            'dunning_last_at' => 'datetime',
            'paused_at' => 'datetime',
            'resumes_at' => 'datetime',
            'card_expires_at' => 'date',
            'card_checked_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * Every status this package writes.
     *
     * One list, so a filter, a screen and this model cannot drift apart.
     *
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_INITIATED,
            self::STATUS_PENDING,
            self::STATUS_ACTIVE,
            self::STATUS_SUSPENDED,
            self::STATUS_PAUSED,
            self::STATUS_CANCELLED,
            self::STATUS_COMPLETED,
        ];
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Whether the provider will still charge this. */
    public function isLive(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_ACTIVE], true);
    }

    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    /**
     * Whether the agreement still stands: charged, or paused.
     *
     * The question a cancel button asks. A paused membership is one somebody may
     * want to end for good, and hiding the button because nothing is being
     * charged right now would leave them a contract they cannot leave.
     */
    public function isRunning(): bool
    {
        return $this->isLive() || $this->isPaused();
    }

    /** A plan stops; a subscription does not. */
    public function isPlan(): bool
    {
        return $this->times !== null;
    }

    /**
     * How many charges are still to come, or null when there is no end.
     *
     * Never negative: a provider that charged one more than it was told to is a
     * problem to notice, not a number to render as `-1`.
     */
    public function remaining(): ?int
    {
        return $this->times === null ? null : max(0, $this->times - $this->times_charged);
    }

    /** The price of one cycle, as a decimal string, for display and for the provider's API. */
    public function amount(): string
    {
        // Not a hard-coded 100, for the same reason `Payment::amount()` stopped
        // being one in 1.11.0: how many minor units make one depends on the
        // currency. This string is handed to the provider when an agreement is
        // created (`Subscriptions::startFromPayment()`), so a yen plan billed
        // through the old two-decimal arithmetic went out at a hundredth of its
        // price. See {@see Money}.
        return Money::format($this->amount_cent, $this->currency);
    }

    /** What the whole agreement comes to, when it has an end. */
    public function totalCent(): ?int
    {
        return $this->times === null ? null : $this->amount_cent * $this->times;
    }

    /**
     * Bis wann der Käufer bezahlt hat — abgeleitet aus dem, was er überwiesen
     * hat, nicht aus einer Spalte, die jemand anders leeren darf.
     *
     * **Warum es das braucht.** `next_payment_at` ist die naheliegende Antwort
     * und die richtige, solange die Vereinbarung läuft. Eine Kündigung setzt
     * sie auf `null` ({@see \Goldnead\StatamicPayments\Support\Subscriptions::cancel()}),
     * und zwar zu Recht: es wird nichts mehr eingezogen. Nur war sie bis dahin
     * auch die einzige Auskunft darüber, wie lange der laufende Zeitraum noch
     * geht — und mit ihr verschwand die Antwort auf „was habe ich schon
     * bezahlt".
     *
     * Was am 15.09.2026 dabei herauskam, gemessen an einem echten Testkauf auf
     * staging: eine Ratenzahlung über 3 × 520 €, erste Rate bezahlt, Kündigung,
     * und der Zugang lief in derselben Sekunde ab. Der Käufer hatte den
     * laufenden Monat bezahlt und verlor ihn.
     *
     * Die letzte eingegangene Rate plus ein Intervall überlebt jede Kündigung,
     * weil sie eine Tatsache ist und keine Absicht.
     *
     * **Eine voll erstattete Rate zählt nicht.** Dieselbe Grenze wie beim
     * Entzug nebenan: nur die volle Erstattung dreht die Aussage „bezahlt" um.
     * Eine Teilerstattung ist ein Nachlass, kein Rückzug.
     *
     * `null`, wenn nie etwas eingegangen ist — dann gibt es auch keinen
     * Zeitraum, den man jemandem lassen müsste.
     */
    public function paidThroughAt(): ?Carbon
    {
        $interval = trim((string) $this->interval);

        if ($interval === '') {
            return null;
        }

        $letzte = $this->payments()
            ->where('status', Payment::STATUS_PAID)
            ->whereNotNull('paid_at')
            // Voll erstattet heisst nicht bezahlt. `refunded_cent` ist 0,
            // solange nichts zurückging, deshalb reicht der Vergleich mit dem
            // Betrag — eine Teilerstattung bleibt darunter.
            ->where(fn ($q) => $q->whereNull('refunded_at')->orWhereColumn('refunded_cent', '<', 'amount_cent'))
            ->orderByDesc('paid_at')
            ->first();

        if ($letzte === null) {
            return null;
        }

        return self::addInterval(Carbon::parse($letzte->paid_at), $interval);
    }

    /**
     * Ein Intervall auf ein Datum, in der Sprache des Anbieters.
     *
     * `"1 month"`, `"12 weeks"`, `"2 days"` — dieselben Worte, die der Anbieter
     * nimmt, weshalb sie so gespeichert sind, wie sie getippt wurden, statt in
     * eine Einheiten-Aufzählung zerlegt. Was nicht lesbar ist, fällt auf einen
     * Monat zurück, statt zu werfen: ein leicht falsches Datum lässt sich
     * geraderücken, eine Vereinbarung nicht aufzuzeichnen, für die jemand schon
     * bezahlt hat, nicht.
     *
     * Öffentlich und statisch, weil zwei Stellen dieselbe Rechnung brauchen —
     * {@see \Goldnead\StatamicPayments\Support\Subscriptions::afterOneInterval()}
     * für „wann wird das nächste Mal eingezogen" und {@see self::paidThroughAt()}
     * für „bis wann ist bezahlt". Zwei Kopien wären zwei Wege, sich über die
     * Monatsenden zu uneinigen.
     */
    /**
     * The start of the period that ends on `$bis`: the mirror of {@see addInterval()}.
     *
     * Needed where a part of the current period is worth something — switching
     * to another amount mid-period, crediting what is left of a replaced one.
     */
    public static function subInterval(Carbon $bis, string $interval): Carbon
    {
        if (preg_match('/^(\d+)\s*months?$/i', trim($interval), $m)) {
            return $bis->copy()->subMonthsNoOverflow((int) $m[1]);
        }

        try {
            return $bis->copy()->sub($interval);
        } catch (\Throwable) {
            return $bis->copy()->subMonth();
        }
    }

    /**
     * How much of the current period is still ahead, between 0 and 1.
     *
     * Zero when there is no next charge to measure against. The period is the
     * one that ends on `next_payment_at`.
     */
    public function remainingFraction(?Carbon $now = null): float
    {
        $bis = $this->next_payment_at;
        $interval = trim((string) $this->interval);

        if ($bis === null || $interval === '') {
            return 0.0;
        }

        $now ??= Carbon::now();
        $von = self::subInterval($bis, $interval);
        $laenge = $bis->getTimestamp() - $von->getTimestamp();

        if ($laenge <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, ($bis->getTimestamp() - $now->getTimestamp()) / $laenge));
    }

    public static function addInterval(Carbon $von, string $interval): Carbon
    {
        // Ein Monat, ohne hinten herauszufallen. `add('1 month')` landet am
        // 31. Januar auf dem 3. März: der Februar wird übersprungen, und der
        // Anbieter rechnet danach für immer auf dem 3. weiter. Gemessen, nicht
        // angenommen.
        if (preg_match('/^(\d+)\s*months?$/i', trim($interval), $m)) {
            return $von->copy()->addMonthsNoOverflow((int) $m[1]);
        }

        try {
            return $von->copy()->add($interval);
        } catch (\Throwable) {
            Log::warning('statamic-payments: an interval this package cannot read; the next date is a guess.', [
                'interval' => $interval,
            ]);

            return $von->copy()->addMonth();
        }
    }
}
