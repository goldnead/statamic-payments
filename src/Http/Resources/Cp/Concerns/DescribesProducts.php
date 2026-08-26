<?php

namespace Goldnead\StatamicPayments\Http\Resources\Cp\Concerns;

use Goldnead\StatamicPayments\Support\Catalogue;

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
     * The catalogue's name for a product, if there is one.
     *
     * Through the catalogue rather than the config array, because a handle in
     * this column may belong to something another addon resolves — an offer,
     * for one — and the listing showed the raw `offer:fruehling-upsell` where a
     * name belongs. The dot-notation trap that used to be handled here is
     * handled one level down, in Catalogue::find().
     */
    protected function productName(?string $handle): ?string
    {
        if (! is_string($handle) || $handle === '') {
            return null;
        }

        // Through the catalogue: a row sold through an offer carries a handle
        // no config array knows, and the listing showed the raw
        // `offer:fruehling-upsell` where a name belongs.
        $product = app(Catalogue::class)->find($handle);

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
