<?php

namespace Goldnead\StatamicPayments\Support;

use Illuminate\Support\Arr;

/**
 * What may be bought, and for how much.
 *
 * The amount comes from here and **never** from the request. A checkout that
 * accepted a posted price would let anyone buy anything for a cent, which is
 * the oldest mistake in online payments and still the most common.
 *
 * Empty as shipped: an addon that carried prices would be wrong about every
 * site that installed it.
 */
class Catalogue
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return (array) config('statamic-payments.products', []);
    }

    /** @return array<string, mixed>|null */
    public function find(string $handle): ?array
    {
        $product = Arr::get($this->all(), $handle);

        if (! is_array($product)) {
            return null;
        }

        $amount = Arr::get($product, 'amount_cent');

        // A product without a positive integer amount is not a product. Letting
        // it through would create a payment for nothing and call it an order.
        if (! is_int($amount) || $amount <= 0) {
            return null;
        }

        return $product + [
            'handle' => $handle,
            'currency' => (string) Arr::get($product, 'currency', config('statamic-payments.currency', 'EUR')),
            'name' => (string) Arr::get($product, 'name', $handle),
        ];
    }
}
