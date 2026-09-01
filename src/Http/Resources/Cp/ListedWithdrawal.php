<?php

namespace Goldnead\StatamicPayments\Http\Resources\Cp;

use Goldnead\StatamicPayments\Legal\Withdrawals;
use Goldnead\StatamicPayments\Models\Withdrawal;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Eine Widerrufszeile, fertig für den Bildschirm.
 *
 * @mixin Withdrawal
 */
class ListedWithdrawal extends JsonResource
{
    public function toArray($request)
    {
        $payment = $this->payment;

        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'order_reference' => $this->order_reference,
            'contact' => $this->contact,
            'message' => $this->message,
            'declared_at' => $this->declared_at->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'receipt_sent_at' => $this->receipt_sent_at?->toIso8601String(),
            'merchant_notified_at' => $this->merchant_notified_at?->toIso8601String(),
            'right_expired_hint' => (bool) $this->right_expired_hint,
            'within_period' => app(Withdrawals::class)->withinPeriod($this->resource),
            'handled_at' => $this->handled_at?->toIso8601String(),
            'handled_note' => $this->handled_note,
            'payment' => $payment === null ? null : [
                'id' => $payment->getKey(),
                'provider_id' => $payment->provider_id,
                'product' => $payment->product,
                'amount' => $payment->amount(),
                'currency' => $payment->currency,
                'status' => $payment->status,
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ],
        ];
    }
}
