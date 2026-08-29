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
     * Extra sources that can *enumerate*, contributed by other addons.
     *
     * `extend()` answers "what does this handle cost". It cannot answer "what
     * is there at all", because a resolver is only ever handed one handle. Any
     * screen that offers a product to pick from — the product select in the
     * offer form, and the `Rule::in()` that checks what came back from it — was
     * therefore blind to everything that did not live in the config file. It
     * showed three of six products and then refused the save with a 422.
     *
     * `statamic-products` keeps its products in a table, so it registers both:
     * a contributor that lists them, and a resolver that prices one.
     *
     * Typed `mixed` on the way out on purpose. The shape asked for is
     * `array<string, array<string, mixed>>` — see `contribute()` — but PHP does
     * not enforce a callable's return type, and every contributor is another
     * package's code. Claiming here that it already holds would make the checks
     * in `all()` look redundant while a single third-party typo still reached
     * a product picker.
     *
     * @var list<callable(): mixed>
     */
    protected static array $contributors = [];

    /**
     * Guards against a contributor that asks the catalogue what there is.
     *
     * Reachable without malice: a contributor that skips handles already taken
     * would naturally call `all()` to find out. It would then be called again,
     * for ever. While enumerating, contributors are simply not consulted a
     * second time and the configured catalogue is the answer.
     */
    protected static bool $enumerating = false;

    /**
     * @param  callable(string): (array<string, mixed>|null)  $resolver
     */
    public static function extend(callable $resolver): void
    {
        static::$resolvers[] = $resolver;
    }

    /**
     * Register a source that can list what it has.
     *
     * **A contributor must also `extend()`.** Listing and pricing are separate
     * seams on purpose — `find()` stays a config lookup plus a handful of cheap
     * resolvers, and never walks a database because somebody asked about a
     * handle that does not exist. The cost of that split is this rule: an addon
     * that contributes a handle it cannot resolve puts something in the picker
     * that cannot be bought.
     *
     * @param  callable(): array<string, array<string, mixed>>  $contributor
     */
    public static function contribute(callable $contributor): void
    {
        static::$contributors[] = $contributor;
    }

    /**
     * For tests, and for a host that rebuilds its container between requests.
     *
     * Clears contributors too, despite the name. Every downstream test suite
     * already calls this in `tearDown()`, and a contributor that survived it
     * would leak a whole product list into the next test — the kind of failure
     * that shows up as an unrelated assertion three files away.
     */
    public static function forgetResolvers(): void
    {
        static::$resolvers = [];
        static::$contributors = [];
    }

    /** The configured catalogue alone, before anyone else has a say. */
    public function configured(): array
    {
        return (array) config('statamic-payments.products', []);
    }

    /**
     * Everything that can be bought, config and contributed together.
     *
     * **Config wins on a collision, and that is a decision.** A price in a file
     * is the site owner's explicit word, it is in version control, and a deploy
     * must not be silently overruled by a row in a table. The collision itself
     * is still worth surfacing — two truths about one price is exactly the
     * illness that had a checkout show 330 while the catalogue said 332 — but
     * that belongs in whatever screen shows the catalogue, not in the answer.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $configured = $this->configured();

        if (static::$enumerating || static::$contributors === []) {
            return $configured;
        }

        static::$enumerating = true;

        try {
            $contributed = [];

            foreach (static::$contributors as $contributor) {
                $products = $contributor();

                if (! is_array($products)) {
                    continue;
                }

                foreach ($products as $handle => $product) {
                    // A contributed entry is checked here, where a configured
                    // one is not, and the asymmetry is deliberate. Config has
                    // always been listed as it stands; validating it now would
                    // make a site's mistyped price *disappear* from its own
                    // picker on upgrade, which reads as "nothing to sell". A
                    // contributed entry has no history to break, and listing
                    // one `find()` will refuse puts an unbuyable line in the
                    // picker — the failure this seam exists to end.
                    if (! is_string($handle) || $handle === '' || ! is_array($product)) {
                        continue;
                    }

                    $amount = Arr::get($product, 'amount_cent');

                    if (! is_int($amount) || $amount < 0) {
                        continue;
                    }

                    // First contributor to claim a handle keeps it. Same reason
                    // as config winning: whoever spoke first is not overwritten
                    // without anyone noticing.
                    $contributed[$handle] ??= $product;
                }
            }
        } finally {
            static::$enumerating = false;
        }

        // `+` keeps the left-hand keys: config over contributed.
        return $configured + $contributed;
    }

    /**
     * NO memo here, deliberately, and it was written and taken back out.
     *
     * `find()` is asked for the same handle repeatedly — once per row of a
     * listing, three times per invoice line — and a resolver may hit the
     * database, so caching looks free. It is not: the catalogue is read from
     * configuration, and configuration changes within a request. A memo would
     * answer a question about a price from before that change, and the one rule
     * this class exists for is that an amount is always the current
     * server-side one.
     *
     * Cheap and wrong beats expensive and right nowhere, and least of all here.
     * If the query count ever matters, the place to fix it is the resolver in
     * `statamic-offers`, which knows when its own table changed.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $handle): ?array
    {
        // `configured()`, not `all()`, and that is the point of the split.
        // A handle arriving here can be anything a browser sent; walking every
        // contributor — and every table behind one — for a handle that does not
        // exist would turn a garbage request into a query. Contributors list,
        // resolvers price, and a contributor that wants its handles buyable
        // registers a resolver as well.
        //
        // Plain array access, also deliberately. `Arr::get()` splits on dots —
        // a handle containing one would walk into a nested config array instead
        // of simply missing.
        $configured = $this->configured();
        $product = $configured[$handle] ?? null;

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

        // `$product + [...]` keeps whatever else the catalogue declared, which
        // is how `interval`, `times`, `trial_days` and `trial_amount_cent`
        // reach the subscription code without this class knowing what they are.
        return $product + [
            'handle' => $handle,
            'currency' => (string) Arr::get($product, 'currency', config('statamic-payments.currency', 'EUR')),
            'name' => (string) Arr::get($product, 'name', $handle),
        ];
    }
}
