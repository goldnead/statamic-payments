<?php

namespace Goldnead\StatamicPayments\Facades;

use Goldnead\StatamicPayments\Support\PaymentLog as Log;
use Illuminate\Support\Facades\Facade;

/**
 * Das Kommunikationsprotokoll einer Zahlung, von außen.
 *
 * @method static \Goldnead\StatamicPayments\Models\PaymentCommunication|null mail(\Goldnead\StatamicPayments\Models\Payment|int $payment, string $kind, string $to, ?string $subject = null, string $status = 'sent', array $meta = [], ?string $reference = null)
 * @method static \Goldnead\StatamicPayments\Models\PaymentCommunication|null note(\Goldnead\StatamicPayments\Models\Payment|int $payment, string $kind, string $text, array $meta = [])
 * @method static \Goldnead\StatamicPayments\Models\PaymentCommunication|null record(\Goldnead\StatamicPayments\Models\Payment|int $payment, string $channel, string $kind, array $attributes = [])
 * @method static \Illuminate\Database\Eloquent\Collection<int, \Goldnead\StatamicPayments\Models\PaymentCommunication> for(\Goldnead\StatamicPayments\Models\Payment|int $payment)
 *
 * @see Log
 */
class PaymentLog extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Log::class;
    }
}
