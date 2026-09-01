<?php

namespace Goldnead\StatamicPayments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eine Kündigung nach § 312k BGB, erklärt ohne Login.
 *
 * Nicht zu verwechseln mit `Subscription.cancelled_at`: das dort ist der
 * Zustand des Abos beim Anbieter, das hier ist die **Erklärung** des
 * Verbrauchers. Die zweite gibt es auch dann, wenn die erste nie zustande kam —
 * weil kein Abo gefunden wurde oder der Anbieter nicht antwortete — und genau
 * dann ist sie wichtig.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $subscription_id
 * @property int $brand_id
 * @property string $name
 * @property string $email
 * @property string $identification
 * @property string $kind
 * @property string|null $reason
 * @property Carbon|null $effective_at
 * @property Carbon $declared_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $receipt_sent_at
 * @property Carbon|null $merchant_notified_at
 * @property Carbon|null $provider_cancelled_at
 * @property Carbon|null $handled_at
 * @property string|null $handled_note
 * @property string|null $ip_hash
 * @property Carbon|null $created_at
 */
class Cancellation extends Model
{
    public const PREFIX = 'K-';

    /** Die ordentliche Kündigung: zum nächstmöglichen oder genannten Termin. */
    public const KIND_ORDINARY = 'ordinary';

    /** Die außerordentliche: aus wichtigem Grund, und der Grund wird genannt. */
    public const KIND_EXTRAORDINARY = 'extraordinary';

    protected $table = 'payment_cancellations';

    protected $guarded = [];

    /** @return list<string> */
    public static function kinds(): array
    {
        return [self::KIND_ORDINARY, self::KIND_EXTRAORDINARY];
    }

    protected function casts(): array
    {
        return [
            'effective_at' => 'date',
            'declared_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'receipt_sent_at' => 'datetime',
            'merchant_notified_at' => 'datetime',
            'provider_cancelled_at' => 'datetime',
            'handled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function isHandled(): bool
    {
        return $this->handled_at !== null;
    }

    public function isExtraordinary(): bool
    {
        return $this->kind === self::KIND_EXTRAORDINARY;
    }
}
