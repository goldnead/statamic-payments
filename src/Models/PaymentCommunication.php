<?php

namespace Goldnead\StatamicPayments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ein Eintrag im Kommunikationsprotokoll einer Zahlung.
 *
 * Nur angehängt, nie geändert. Was rausging, ging raus; eine Zeile, die
 * später umgeschrieben wird, beantwortet die Frage „ist die Rechnung
 * verschickt worden" nicht mehr.
 *
 * @property int $id
 * @property int $payment_id
 * @property int $brand_id
 * @property string $channel
 * @property string $kind
 * @property string|null $recipient
 * @property string|null $subject
 * @property string $status
 * @property string|null $reference
 * @property array<string, mixed>|null $meta
 * @property Carbon $happened_at
 * @property Carbon|null $created_at
 */
class PaymentCommunication extends Model
{
    public const CHANNEL_MAIL = 'mail';

    public const CHANNEL_WEBHOOK = 'webhook';

    public const CHANNEL_EXPORT = 'export';

    public const CHANNEL_NOTE = 'note';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_QUEUED = 'queued';

    protected $guarded = [];

    /** @return list<string> */
    public static function channels(): array
    {
        return [self::CHANNEL_MAIL, self::CHANNEL_WEBHOOK, self::CHANNEL_EXPORT, self::CHANNEL_NOTE];
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return [self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_QUEUED];
    }

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'happened_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
