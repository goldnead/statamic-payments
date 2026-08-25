<?php

namespace Goldnead\StatamicPayments\Models;

use Goldnead\StatamicPayments\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One payment, as the provider reports it.
 *
 * @property int $id
 * @property string $provider
 * @property string $provider_id
 * @property string $product
 * @property int $amount_cent
 * @property string $currency
 * @property string $status
 * @property string|null $email
 * @property string|null $name
 * @property string|null $country
 * @property string|null $country_source
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $fulfilled_at
 * @property Carbon|null $failed_notified_at
 * @property int $refunded_cent
 * @property Carbon|null $refunded_at
 * @property Carbon|null $abandoned_notified_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $created_at
 * @property string|null $customer_reference
 * @property int|null $parent_payment_id
 * @property int|null $subscription_id
 * @property string|null $discount_code
 * @property int|null $discount_cent
 */
class Payment extends Model
{
    /** Created here, not yet acknowledged by the provider. Not an order in flight. */
    public const STATUS_INITIATED = 'initiated';

    public const STATUS_OPEN = 'open';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELED = 'canceled';

    protected $guarded = [];

    /**
     * Every status this package writes.
     *
     * One list, so the filter, the screen and the model cannot drift apart.
     *
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PAID,
            self::STATUS_OPEN,
            self::STATUS_INITIATED,
            self::STATUS_FAILED,
            self::STATUS_EXPIRED,
            self::STATUS_CANCELED,
        ];
    }

    /**
     * The lines go with the payment.
     *
     * The migration already declares `cascadeOnDelete`, but that is only
     * enforced when the connection has foreign keys switched on — and on
     * SQLite, which is what a small client site is most likely to run, it
     * quietly is not. Orphaned lines would then count towards every revenue
     * report ever run. Belt and braces, deliberately.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $payment) {
            $payment->items()->delete();
        });
    }

    protected function casts(): array
    {
        return [
            'amount_cent' => 'integer',
            'meta' => 'array',
            'fulfilled_at' => 'datetime',
            'failed_notified_at' => 'datetime',
            'abandoned_notified_at' => 'datetime',
            'refunded_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_payment_id');
    }

    /** @return HasMany<PaymentItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PaymentItem::class);
    }

    /**
     * What the lines add up to, in minor units.
     *
     * Not stored a second time. `amount_cent` on the payment is the total the
     * provider was asked to charge; this recomputes it from the lines, which is
     * what makes the two checkable against each other instead of merely equal
     * by assumption.
     */
    public function itemsTotalCent(): int
    {
        return $this->items->sum(fn (PaymentItem $item) => $item->lineTotalCent());
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isFulfilled(): bool
    {
        return $this->fulfilled_at !== null;
    }

    /** The amount as a decimal string, for display and for the provider's API. */
    public function amount(): string
    {
        // Not a hard-coded 100. `amount_cent` is minor units, and how many of
        // those make one depends on the currency: the yen has none, the dinar
        // three. See {@see Money}.
        return Money::format($this->amount_cent, $this->currency);
    }
}
