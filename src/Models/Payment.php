<?php

namespace Goldnead\StatamicPayments\Models;

use Illuminate\Database\Eloquent\Model;
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
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $fulfilled_at
 * @property Carbon|null $failed_notified_at
 * @property Carbon|null $paid_at
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

    protected function casts(): array
    {
        return [
            'amount_cent' => 'integer',
            'meta' => 'array',
            'fulfilled_at' => 'datetime',
            'failed_notified_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
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
        return number_format($this->amount_cent / 100, 2, '.', '');
    }
}
