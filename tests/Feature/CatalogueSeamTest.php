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
}
