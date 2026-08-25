<?php

namespace Goldnead\StatamicPayments\Http\Resources\Cp;

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
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'created_at' => $this->created_at?->toIso8601String(),
            'product' => $this->product,
            'product_name' => $this->productName(),
            'amount' => $this->amount(),
            'currency' => $this->currency,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'fulfilled_at' => $this->fulfilled_at?->toIso8601String(),
            'email' => $this->email,
            'name' => $this->name,
            'provider_id' => $this->provider_id,
        ];
    }

    /**
     * The configured name, if there is one.
     *
     * Plain array access, deliberately. A product handle comes from the
     * database, and both `config('…products.'.$handle)` and `Arr::get()` split
     * on dots — a handle containing one would walk into nested configuration
     * instead of missing.
     */
    protected function productName(): ?string
    {
        $products = config('statamic-payments.products', []);
        $product = is_array($products) ? ($products[$this->product] ?? null) : null;

        return is_array($product) ? ($product['name'] ?? null) : null;
    }

    /**
     * A status the package does not know shows its raw value rather than a
     * missing translation key. `statamic-payments::messages.status_xyz` in the
     * interface tells the reader nothing and looks like a broken install.
     */
    protected function statusLabel(): string
    {
        $key = 'statamic-payments::messages.status_'.$this->status;
        $label = __($key);

        return $label === $key ? (string) $this->status : $label;
    }
}
