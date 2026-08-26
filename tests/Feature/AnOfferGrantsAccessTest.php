<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\IdentityContracts\ServiceProvider;
use Goldnead\StatamicPayments\Integrations\EntitlementsBridge;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Buying through an offer has to hand over the access it was sold with.
 *
 * The bridge used to read `config('statamic-payments.products')` directly,
 * which walks past every resolver another addon registered with the catalogue —
 * and `statamic-offers` registers one. So anything bought through an offer
 * granted nothing at all: the payment settled, the money arrived, and the
 * access never appeared.
 *
 * It failed as quietly as a bug can. "This product grants nothing" and "I have
 * never heard of this product" both came back as the same null, so there was
 * no error to see, no log line, and no difference from a product that
 * legitimately grants nothing.
 *
 * Against the real sibling, not a stand-in: the last time this bridge was
 * checked only against a double, it had never worked on a single real
 * installation.
 */
class AnOfferGrantsAccessTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), array_values(array_filter([
            class_exists(ServiceProvider::class) ? ServiceProvider::class : null,
            class_exists(\Goldnead\BrandContext\ServiceProvider::class) ? \Goldnead\BrandContext\ServiceProvider::class : null,
            class_exists(\Goldnead\Entitlements\ServiceProvider::class) ? \Goldnead\Entitlements\ServiceProvider::class : null,
        ])));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-entitlements/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-brand-context/database/migrations');

        if (! class_exists(Entitlements::class)) {
            $this->markTestSkipped('the sibling has to be installed for this to mean anything');
        }

        config([
            'statamic-payments.entitlements.enabled' => true,
            'statamic-payments.products.kurs' => [
                'name' => 'Chorleitungskurs', 'amount_cent' => 24900, 'grants' => 'kurs-zugang',
            ],
        ]);

        Catalogue::forgetResolvers();

        // What statamic-offers registers. As strict as the real one: it answers
        // for its own prefix and for nothing else, and it returns the product's
        // array with the offer's price on top — which is exactly where `grants`
        // rides along.
        Catalogue::extend(function (string $handle): ?array {
            if ($handle !== 'offer:fruehling-upsell') {
                return null;
            }

            $product = (array) (config('statamic-payments.products')['kurs'] ?? []);
            unset($product['handle']);

            return ['name' => 'Frühlings-Upsell', 'amount_cent' => 4900, 'offer' => 'fruehling-upsell', 'product' => 'kurs'] + $product;
        });
    }

    protected function tearDown(): void
    {
        Catalogue::forgetResolvers();

        parent::tearDown();
    }

    private function zahlung(string $produkt): Payment
    {
        return Payment::create([
            'provider' => 'fake', 'provider_id' => 'tr_'.bin2hex(random_bytes(4)),
            'product' => $produkt, 'amount_cent' => 4900, 'currency' => 'EUR',
            'status' => Payment::STATUS_PAID, 'email' => 'wer@example.com',
        ]);
    }

    #[Test]
    public function a_purchase_through_an_offer_grants_the_products_access(): void
    {
        app(EntitlementsBridge::class)->grantFor($this->zahlung('offer:fruehling-upsell'));

        $this->assertSame(1, Entitlement::count(), 'der Kunde hat bezahlt und bekam nichts');
        $this->assertSame('kurs-zugang', Entitlement::first()->product_slug);
    }

    #[Test]
    public function a_purchase_of_the_plain_product_still_grants_it(): void
    {
        app(EntitlementsBridge::class)->grantFor($this->zahlung('kurs'));

        $this->assertSame(1, Entitlement::count());
        $this->assertSame('kurs-zugang', Entitlement::first()->product_slug);
    }

    #[Test]
    public function a_handle_nobody_knows_still_grants_nothing(): void
    {
        // The refusal must survive the fix: an unknown handle is not an excuse
        // to hand out access.
        app(EntitlementsBridge::class)->grantFor($this->zahlung('offer:gibt-es-nicht'));

        $this->assertSame(0, Entitlement::count());
    }
}
