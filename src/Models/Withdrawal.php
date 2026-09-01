<?php

namespace Goldnead\StatamicPayments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ein Widerruf nach § 356a BGB, wie der Verbraucher ihn erklärt hat.
 *
 * Was der Verbraucher eingegeben hat, steht hier unverändert. Was das Paket
 * daraus gemacht hat — die Zuordnung, der Hinweis auf ein erloschenes Recht —
 * steht daneben und ist als Zutat erkennbar. Vermischt man beides, weiß später
 * niemand mehr, was gesagt und was geschlossen wurde.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $payment_id
 * @property int $brand_id
 * @property string $name
 * @property string $email
 * @property string $order_reference
 * @property string $contact
 * @property string|null $message
 * @property Carbon $declared_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $receipt_sent_at
 * @property Carbon|null $merchant_notified_at
 * @property bool $right_expired_hint
 * @property Carbon|null $handled_at
 * @property string|null $handled_note
 * @property string|null $ip_hash
 * @property Carbon|null $created_at
 */
class Withdrawal extends Model
{
    public const PREFIX = 'W-';

    protected $table = 'payment_withdrawals';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'declared_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'receipt_sent_at' => 'datetime',
            'merchant_notified_at' => 'datetime',
            'right_expired_hint' => 'boolean',
            'handled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** Schritt 2 ist gedrückt: das ist der Widerruf. */
    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function isHandled(): bool
    {
        return $this->handled_at !== null;
    }
}
