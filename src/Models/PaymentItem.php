<?php

namespace Goldnead\StatamicPayments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a payment.
 *
 * @property int $id
 * @property int $payment_id
 * @property string $product
 * @property string $name
 * @property int $amount_cent
 * @property int $quantity
 * @property string $kind
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 */
class PaymentItem extends Model
{
    /** What the buyer came for. */
    public const KIND_PRIMARY = 'primary';

    /** Ticked at checkout, before paying. */
    public const KIND_BUMP = 'bump';

    /** Accepted after paying, on a follow-up offer. */
    public const KIND_UPSELL = 'upsell';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_cent' => 'integer',
            'quantity' => 'integer',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** Quantity × unit price, in minor units. Never stored — see the migration. */
    public function lineTotalCent(): int
    {
        return $this->amount_cent * $this->quantity;
    }

    /** The line total as a decimal string, for display. */
    public function lineTotal(): string
    {
        return number_format($this->lineTotalCent() / 100, 2, '.', '');
    }
}
