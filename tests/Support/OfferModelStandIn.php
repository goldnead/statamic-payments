<?php

/*
 * A stand-in for `statamic-offers`' Offer model, with only what payments reads
 * from it: the table, `prefix()` and `pricingOptions()`. Loaded by a test only
 * where the real package is not installed.
 */

namespace Goldnead\StatamicOffers\Models;

use Illuminate\Database\Eloquent\Model;

class Offer extends Model
{
    protected $table = 'offers';

    protected $guarded = [];

    protected $casts = ['pricing_options' => 'array', 'active' => 'boolean', 'brand_id' => 'integer'];

    public static function prefix(): string
    {
        return 'offer:';
    }

    /** @return list<array{key: string, interval: string|null}> */
    public function pricingOptions(): array
    {
        return array_values(array_map(
            fn (array $o) => ['key' => (string) $o['key'], 'interval' => $o['interval'] ?? null],
            (array) $this->pricing_options,
        ));
    }
}
