<?php

namespace Goldnead\StatamicPayments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Something an agreement has already been told, or already counted.
 *
 * A claim, not a log. The unique index over agreement, kind and reference is
 * what makes a reminder run idempotent: whoever inserts the row first sends the
 * mail, everybody after them hits the index and sends nothing. Same pattern as
 * `payment_chargebacks`, for the same reason — a scheduler that overlaps, a
 * second worker or a redelivered webhook must not produce a second letter.
 *
 * @property int $id
 * @property int $subscription_id
 * @property string $kind
 * @property string $reference
 * @property Carbon|null $created_at
 */
class SubscriptionNotice extends Model
{
    public const KIND_UPCOMING = 'upcoming';

    public const KIND_CARD_EXPIRING = 'card_expiring';

    public const KIND_CARD_EXPIRED = 'card_expired';

    public const KIND_FAILED_ATTEMPT = 'failed_attempt';

    protected $table = 'payment_subscription_notices';

    protected $guarded = [];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['subscription_id' => 'integer'];
    }

    /**
     * Stake the claim. True when this call was the first.
     *
     * `insertOrIgnore` rather than a read and a write: between the two another
     * worker can claim the same thing, and then both send.
     */
    public static function claim(Subscription $subscription, string $kind, string $reference): bool
    {
        return static::query()->insertOrIgnore([
            'subscription_id' => $subscription->getKey(),
            'kind' => $kind,
            'reference' => mb_substr($reference, 0, 64),
            'created_at' => Carbon::now(),
        ]) > 0;
    }

    /** Give a claim back, so the next run tries again. For a mail that did not go out. */
    public static function release(Subscription $subscription, string $kind, string $reference): void
    {
        static::query()
            ->where('subscription_id', $subscription->getKey())
            ->where('kind', $kind)
            ->where('reference', mb_substr($reference, 0, 64))
            ->delete();
    }
}
