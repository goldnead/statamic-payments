<?php

namespace Goldnead\StatamicPayments\Tests\Feature\Portal;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\ServiceProvider;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Portal\Mail\PortalLinkMail;
use Goldnead\StatamicPayments\Support\Brands;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * "Installed and would not answer" is not the same as "not installed".
 *
 * A boolean cannot tell those apart, and the first version of `Support\Brands`
 * did not: `multiBrandEnabled()` was wrapped in `catch (Throwable) { return false; }`,
 * `false` meant "single-brand install", and single-brand means no filter at all.
 * One throwing licence callback — and `brand-context` resolves that callback out
 * of the container, so it is a callback a host wrote — would have turned the
 * tenant boundary off and shown one brand's buyer every brand's orders.
 *
 * A defensive catch that fails open is worse than no catch: it produces a page
 * that works, which is exactly what nobody investigates.
 */
class BrandUnanswerableTest extends TestCase
{
    protected Brand $shopA;

    protected Brand $shopB;

    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), array_values(array_filter([
            class_exists(ServiceProvider::class) ? ServiceProvider::class : null,
        ])));
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(BrandContext::class)) {
            $this->markTestSkipped('goldnead/statamic-brand-context has to be installed for this to mean anything');
        }

        $this->loadMigrationsFrom(__DIR__.'/../../../vendor/goldnead/statamic-brand-context/database/migrations');

        config([
            'brand-context.multi_brand' => true,
            'statamic-payments.products' => [
                'noten-paket' => ['name' => 'Notenpaket', 'amount_cent' => 1900],
                'chorheft' => ['name' => 'Chorheft', 'amount_cent' => 2900],
            ],
        ]);

        $this->shopA = Brand::create(['handle' => 'shop-a', 'name' => 'Shop A']);
        $this->shopB = Brand::create(['handle' => 'shop-b', 'name' => 'Shop B']);

        Mail::fake();
    }

    protected function orderIn(Brand $brand, string $product): Payment
    {
        return BrandContext::runFor($brand, fn () => Payment::create([
            'provider' => 'fake',
            'provider_id' => 'tr_'.uniqid(),
            'product' => $product,
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'anna@example.de',
            'paid_at' => now(),
        ]));
    }

    /**
     * Break the sibling the way a host can break it: a licence gate that throws.
     *
     * `BrandManager::multiBrandEnabled()` resolves `brand-context.license_check`
     * out of the container and calls it. This is the real mechanism, not a mock
     * of it.
     */
    protected function breakTheLicenceCheck(): void
    {
        config(['brand-context.license_check' => function (): never {
            throw new RuntimeException('the licence server is unreachable');
        }]);
    }

    #[Test]
    public function an_unanswerable_sibling_is_its_own_state(): void
    {
        $this->assertSame(Brands::MULTI, Brands::mode());

        $this->breakTheLicenceCheck();

        // Not SINGLE. SINGLE means "no filter", and no filter is the leak.
        $this->assertSame(Brands::UNKNOWN, Brands::mode());
    }

    #[Test]
    public function nothing_is_shown_while_the_sibling_cannot_be_asked(): void
    {
        $this->orderIn($this->shopA, 'noten-paket');
        $this->orderIn($this->shopB, 'chorheft');

        $inA = $this->followLinkFor('shop-a');

        // The link works, the page shows her order — and then the sibling breaks.
        $this->get(route('statamic-payments.portal.show'))->assertOk()->assertSee('Notenpaket');

        $this->breakTheLicenceCheck();

        $overview = $this->get(route('statamic-payments.portal.show'))->assertOk();

        // Not "everything". Not "her brand's". Nothing, until the host fixes
        // whatever is throwing.
        $overview->assertDontSee('Chorheft');
        $overview->assertDontSee('Notenpaket');
        $overview->assertSee(__('statamic-payments::portal.orders_none'));

        $this->get(route('statamic-payments.portal.order', ['payOrder' => $inA->getKey()]))->assertNotFound();
    }

    #[Test]
    public function no_link_is_issued_while_the_sibling_cannot_be_asked(): void
    {
        $this->orderIn($this->shopA, 'noten-paket');

        $this->breakTheLicenceCheck();

        Mail::fake();

        $this->post(route('statamic-payments.portal.request.send'), ['email' => 'anna@example.de']);

        // Handing out a key while the lock cannot be read is the same mistake in
        // the other direction.
        Mail::assertNothingSent();
    }

    /** @return Payment the order that brand owns */
    protected function followLinkFor(string $handle): Payment
    {
        Mail::fake();

        $this->post(route('statamic-payments.portal.request.send'), [
            'email' => 'anna@example.de',
            'payBrand' => $handle,
        ]);

        $url = null;

        Mail::assertSent(PortalLinkMail::class, function (PortalLinkMail $mail) use (&$url) {
            $url ??= $mail->url;

            return true;
        });

        $this->get((string) $url)->assertRedirect(route('statamic-payments.portal.show'));

        $brand = Brand::query()->where('handle', $handle)->firstOrFail();

        return Payment::query()->where('brand_id', $brand->getKey())->firstOrFail();
    }
}
