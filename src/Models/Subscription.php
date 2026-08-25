<?php

namespace Goldnead\StatamicPayments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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

    protected $guarded = [];

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

    /** The price of one cycle, as a decimal string, for display. */
    public function amount(): string
    {
        return number_format($this->amount_cent / 100, 2, '.', '');
    }

    /** What the whole agreement comes to, when it has an end. */
    public function totalCent(): ?int
    {
        return $this->times === null ? null : $this->amount_cent * $this->times;
    }
}
