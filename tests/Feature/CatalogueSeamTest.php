<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The seam another addon contributes priced things through.
 *
 * It exists so that `statamic-offers` can give an upsell its own price without
 * that price ever passing through a browser. Everything here is about keeping
 * that true.
 */
class CatalogueSeamTest extends TestCase
{
    protected function tearDown(): void
    {
        Catalogue::forgetResolvers();

        parent::tearDown();
    }

    #[Test]
    public function a_resolver_can_supply_a_product_the_config_does_not_have(): void
    {
        Catalogue::extend(fn (string $handle) => $handle === 'offer:fruehling'
            ? ['name' => 'Frühlingsangebot', 'amount_cent' => 1200]
            : null);

        $payment = app(Checkout::class)->start('offer:fruehling')->payment;

        $this->assertSame(1200, $payment->amount_cent);
        $this->assertSame('offer:fruehling', $payment->product);
    }

    #[Test]
    public function the_configured_catalogue_wins(): void
    {
        // A resolver must not be able to reprice something the site has already
        // decided about. Config is the site owner's word; an addon is a helper.
        Catalogue::extend(fn () => ['name' => 'Untergeschoben', 'amount_cent' => 1]);

        $payment = app(Checkout::class)->start('noten-paket')->payment;

        $this->assertSame(1900, $payment->amount_cent);
    }

    #[Test]
    public function a_resolver_that_answers_nonsense_sells_nothing(): void
    {
        // Same rule as for the config: no positive integer amount, no product.
        Catalogue::extend(fn () => ['name' => 'Kaputt', 'amount_cent' => '12,00']);

        $this->assertNull(app(Catalogue::class)->find('irgendwas'));
        $this->assertNull(app(Checkout::class)->start('irgendwas'));
    }

    #[Test]
    public function without_a_resolver_nothing_changes(): void
    {
        $this->assertNull(app(Catalogue::class)->find('offer:fruehling'));
    }

    #[Test]
    public function a_contributor_appears_in_the_list_a_picker_is_built_from(): void
    {
        // The whole reason this seam exists: a product select showed three of
        // six products, because everything outside the config file was invisible
        // to `all()` and the `Rule::in()` built from it then refused the save.
        Catalogue::contribute(fn () => [
            'atemkurs' => ['name' => 'Atemkurs', 'amount_cent' => 4900],
        ]);

        $all = app(Catalogue::class)->all();

        $this->assertArrayHasKey('atemkurs', $all);
        $this->assertSame('Atemkurs', $all['atemkurs']['name']);
        $this->assertArrayHasKey('noten-paket', $all, 'The configured catalogue must survive.');
    }

    #[Test]
    public function the_configured_catalogue_wins_over_a_contributor(): void
    {
        // Same rule as for resolvers, and for the same reason: a price in a file
        // is in version control and was written on purpose. A row in a table
        // must not overrule a deploy without anyone seeing it happen.
        Catalogue::contribute(fn () => [
            'noten-paket' => ['name' => 'Untergeschoben', 'amount_cent' => 1],
        ]);

        $all = app(Catalogue::class)->all();

        $this->assertSame(1900, $all['noten-paket']['amount_cent']);
        $this->assertNotSame('Untergeschoben', $all['noten-paket']['name'] ?? null);
    }

    #[Test]
    public function a_contributed_entry_without_a_usable_price_is_not_listed(): void
    {
        // Listing something `find()` will refuse puts a line in the picker that
        // cannot be bought — a click that ends in a 422 and no explanation.
        Catalogue::contribute(fn () => [
            'kaputt' => ['name' => 'Kaputt', 'amount_cent' => '49,00'],
            'negativ' => ['name' => 'Negativ', 'amount_cent' => -100],
            'heil' => ['name' => 'Heil', 'amount_cent' => 0],
        ]);

        $all = app(Catalogue::class)->all();

        $this->assertArrayNotHasKey('kaputt', $all);
        $this->assertArrayNotHasKey('negativ', $all);
        $this->assertArrayHasKey('heil', $all, 'Free is a price, and a valid one.');
    }

    #[Test]
    public function contributing_alone_does_not_make_a_handle_buyable(): void
    {
        // The contract of the split, stated as a test: `contribute()` lists,
        // `extend()` prices. It keeps `find()` — reached by anything a browser
        // sends — from walking a table for a handle that does not exist.
        Catalogue::contribute(fn () => [
            'atemkurs' => ['name' => 'Atemkurs', 'amount_cent' => 4900],
        ]);

        $this->assertArrayHasKey('atemkurs', app(Catalogue::class)->all());
        $this->assertNull(app(Catalogue::class)->find('atemkurs'));

        // An addon that registers both — which is what `statamic-products` does —
        // gets a handle that is both visible and buyable.
        Catalogue::extend(fn (string $handle) => $handle === 'atemkurs'
            ? ['name' => 'Atemkurs', 'amount_cent' => 4900]
            : null);

        $this->assertSame(4900, app(Catalogue::class)->find('atemkurs')['amount_cent']);
    }

    #[Test]
    public function a_contributor_that_asks_what_there_is_does_not_hang(): void
    {
        // Reachable without malice: a contributor that wants to skip handles
        // somebody else already claimed would naturally call `all()`.
        Catalogue::contribute(function () {
            $taken = array_keys(app(Catalogue::class)->all());

            return in_array('atemkurs', $taken, true)
                ? []
                : ['atemkurs' => ['name' => 'Atemkurs', 'amount_cent' => 4900]];
        });

        $all = app(Catalogue::class)->all();

        $this->assertArrayHasKey('atemkurs', $all);
    }

    #[Test]
    public function without_a_contributor_the_list_is_the_config(): void
    {
        $catalogue = app(Catalogue::class);

        $this->assertSame($catalogue->configured(), $catalogue->all());
    }
}
