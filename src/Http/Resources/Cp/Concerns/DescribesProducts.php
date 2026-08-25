<?php

namespace Goldnead\StatamicPayments\Http\Resources\Cp\Concerns;

/**
 * The two lookups every listed row in this package needs.
 *
 * Extracted rather than written twice: payments and subscriptions name the same
 * products and speak the same language files, and two copies of "what is this
 * handle called" is two places for the dot-notation trap below to come back.
 */
trait DescribesProducts
{
    /**
     * The configured name of a product, if there is one.
     *
     * Plain array access, deliberately. A product handle comes from the
     * database, and both `config('…products.'.$handle)` and `Arr::get()` split
     * on dots — a handle containing one would walk into nested configuration
     * instead of missing.
     */
    protected function productName(?string $handle): ?string
    {
        $products = config('statamic-payments.products', []);
        $product = is_array($products) ? ($products[$handle] ?? null) : null;

        return is_array($product) ? ($product['name'] ?? null) : null;
    }

    /**
     * A translation, or the raw value when this package has no word for it.
     *
     * A status the provider invented tomorrow must show its own name rather
     * than `statamic-payments::messages.status_xyz`, which tells the reader
     * nothing and looks like a broken install.
     */
    protected function translatedOrRaw(string $key, string $raw): string
    {
        $full = 'statamic-payments::messages.'.$key;
        $label = __($full);

        return $label === $full ? $raw : $label;
    }
}
