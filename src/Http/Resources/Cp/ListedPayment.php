<?php

namespace Goldnead\StatamicPayments\Http\Resources\Cp;

use Goldnead\StatamicPayments\Http\Resources\Cp\Concerns\DescribesProducts;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row.
 *
 * @mixin Payment
 *
 * The amount is formatted here, from the integer, so that no template in this
 * package ever divides by a hundred.
 */
class ListedPayment extends JsonResource
{
    use DescribesProducts;

    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'created_at' => $this->created_at?->toIso8601String(),
            'product' => $this->product,
            'product_name' => $this->productName($this->product),
            'amount' => $this->amount(),
            'currency' => $this->currency,
            'status' => $this->status,
            'status_label' => $this->translatedOrRaw('status_'.$this->status, (string) $this->status),
            'fulfilled_at' => $this->fulfilled_at?->toIso8601String(),
            // Next to the status, not instead of it. A charged-back payment is
            // still `paid` — the money did move, and the sale did happen —
            // which is the same line this package holds on refunds. The list
            // shows it as a second badge so a disputed order is visible without
            // opening it.
            'charged_back_at' => $this->charged_back_at?->toIso8601String(),
            'email' => $this->email,
            'name' => $this->name,
            'provider_id' => $this->provider_id,
            'url' => cp_route('utilities.payments.show', ['payPayment' => $this->id]),
        ];
    }
}
