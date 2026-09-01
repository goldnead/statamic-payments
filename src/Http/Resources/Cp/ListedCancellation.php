<?php

namespace Goldnead\StatamicPayments\Http\Resources\Cp;

use Goldnead\StatamicPayments\Models\Cancellation;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Eine Kündigungszeile, fertig für den Bildschirm.
 *
 * @mixin Cancellation
 */
class ListedCancellation extends JsonResource
{
    public function toArray($request)
    {
        $subscription = $this->subscription;

        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'identification' => $this->identification,
            'kind' => $this->kind,
            'kind_label' => __('statamic-payments::cancellation.kind_'.$this->kind),
            'reason' => $this->reason,
            'effective_at' => $this->effective_at?->toDateString(),
            'declared_at' => $this->declared_at->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'receipt_sent_at' => $this->receipt_sent_at?->toIso8601String(),
            'merchant_notified_at' => $this->merchant_notified_at?->toIso8601String(),
            'provider_cancelled_at' => $this->provider_cancelled_at?->toIso8601String(),
            'handled_at' => $this->handled_at?->toIso8601String(),
            'handled_note' => $this->handled_note,
            'subscription' => $subscription === null ? null : [
                'id' => $subscription->getKey(),
                'provider_id' => $subscription->provider_id,
                'product' => $subscription->product,
                'status' => $subscription->status,
            ],
        ];
    }
}
