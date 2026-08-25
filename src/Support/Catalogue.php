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
    /**
     * Extra sources of priced things, contributed by other addons.
     *
     * `statamic-offers` registers one so that an offer with its own price — an
     * upsell at €12 for a product that normally costs €29 — resolves like any
     * other product. It has to be a resolver rather than a value the caller
     * passes, because the one rule this package is built on is that an amount
     * never comes from a request.
     *
     * @var list<callable(string): (array<string, mixed>|null)>
     */
    protected static array $resolvers = [];

    /**
     * @param  callable(string): (array<string, mixed>|null)  $resolver
     */
    public static function extend(callable $resolver): void
    {
        static::$resolvers[] = $resolver;
    }

    /** For tests, and for a host that rebuilds its container between requests. */
    public static function forgetResolvers(): void
    {
        static::$resolvers = [];
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return (array) config('statamic-payments.products', []);
    }

    /** @return array<string, mixed>|null */
    public function find(string $handle): ?array
    {
        // Plain array access, deliberately. A handle can come from a request,
        // and `Arr::get()` splits on dots — a handle containing one would walk
        // into a nested config array instead of simply missing.
        $all = $this->all();
        $product = $all[$handle] ?? null;

        if (! is_array($product)) {
            // Nothing in the configured catalogue. Ask whoever else claims to
            // know about priced things before giving up — an offer's own price
            // lives in another addon's table, and it is still server-side.
            foreach (static::$resolvers as $resolver) {
                $resolved = $resolver($handle);

                if (is_array($resolved)) {
                    $product = $resolved;
                    break;
                }
            }
        }

        if (! is_array($product)) {
            return null;
        }

        $amount = Arr::get($product, 'amount_cent');

        // A product needs an amount, and it has to be a whole number of minor
        // units. **Zero is allowed and negative is not**, and the difference
        // matters: a missing or mistyped price arrives here as null or a string
        // and is refused, which is what stops a typo becoming a free giveaway.
        // An explicit `0` is somebody saying "this one is free" — the lead
        // magnet at the top of a funnel, the sample chapter — and refusing it
        // meant every free thing had to live outside this addon.
        if (! is_int($amount) || $amount < 0) {
            return null;
        }

        return $product + [
            'handle' => $handle,
            'currency' => (string) Arr::get($product, 'currency', config('statamic-payments.currency', 'EUR')),
            'name' => (string) Arr::get($product, 'name', $handle),
        ];
    }
}
