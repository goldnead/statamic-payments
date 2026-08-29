<?php

namespace Goldnead\StatamicPayments\Tests\Feature\Portal;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\ServiceProvider;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Portal\Mail\PortalLinkMail;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;

/**
 * A link for one shop must not open another shop's orders.
 *
 * **This test crosses the real HTTP path and multi-brand at the same time, on
 * purpose.** That intersection is where webhook-manager 2.1.0's total outage
 * sat: every unit test was green, every multi-brand test was green, and the one
 * thing nobody had was a request that went through routing, middleware, session
 * and scope in one go. So nothing here reaches into a service. It asks for a
 * link the way a buyer does, follows it out of the mail, and then tries to see
 * somebody else's order through the URL bar.
 *
 * The same person, one address, two shops on one host. That is the hard case:
 * the address is not the boundary, so the boundary has to be somewhere else.
 */
class PortalBrandIsolationTest extends TestCase
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

    /**
     * Created the way a checkout creates one: inside the brand, through the
     * ordinary model, with nobody setting `brand_id` by hand. A test that
     * stamped the column itself would prove the query and not the stamping.
     */
    protected function orderIn(Brand $brand, string $product, string $email): Payment
    {
        return BrandContext::runFor($brand, fn () => Payment::create([
            'provider' => 'fake',
            'provider_id' => 'tr_'.uniqid(),
            'product' => $product,
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => $email,
            'paid_at' => now(),
        ]));
    }

    protected function subscriptionIn(Brand $brand, string $product, string $email): Subscription
    {
        return BrandContext::runFor($brand, fn () => Subscription::create([
            'provider' => 'fake',
            'provider_id' => 'sub_'.uniqid(),
            'customer_reference' => 'cst_1',
            'product' => $product,
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'interval' => '1 month',
            'times_charged' => 0,
            'status' => Subscription::STATUS_ACTIVE,
            'email' => $email,
        ]));
    }

    #[Test]
    public function a_row_is_stamped_with_the_brand_it_was_created_in(): void
    {
        $order = $this->orderIn($this->shopA, 'noten-paket', 'anna@example.de');

        $this->assertSame((int) $this->shopA->getKey(), (int) $order->brand_id);
    }

    #[Test]
    public function a_link_for_one_shop_shows_only_that_shops_orders(): void
    {
        $inA = $this->orderIn($this->shopA, 'noten-paket', 'anna@example.de');
        $inB = $this->orderIn($this->shopB, 'chorheft', 'anna@example.de');

        $this->followLinkFor('anna@example.de', 'shop-a');

        $overview = $this->get(route('statamic-payments.portal.show'))->assertOk();

        $overview->assertSee('Notenpaket');
        $overview->assertDontSee('Chorheft');

        // And not through the URL bar either. 404, not 403: whether an order
        // exists that this person may not see is exactly the thing a numbered
        // URL must not be able to answer.
        $this->get(route('statamic-payments.portal.order', ['payOrder' => $inA->getKey()]))->assertOk();
        $this->get(route('statamic-payments.portal.order', ['payOrder' => $inB->getKey()]))->assertNotFound();
    }

    #[Test]
    public function a_link_for_one_shop_cannot_cancel_another_shops_subscription(): void
    {
        $inA = $this->subscriptionIn($this->shopA, 'noten-paket', 'anna@example.de');
        $inB = $this->subscriptionIn($this->shopB, 'chorheft', 'anna@example.de');

        $this->followLinkFor('anna@example.de', 'shop-a');

        $this->get(route('statamic-payments.portal.cancel.confirm', ['paySubscription' => $inA->getKey()]))->assertOk();

        $this->get(route('statamic-payments.portal.cancel.confirm', ['paySubscription' => $inB->getKey()]))->assertNotFound();

        $this->post(route('statamic-payments.portal.cancel.run', ['paySubscription' => $inB->getKey()]))->assertNotFound();

        // Nothing happened to it. The provider was never asked, which is the
        // point: a cancellation that 404s must not have reached the gateway.
        $this->assertSame(Subscription::STATUS_ACTIVE, $inB->fresh()->status);
        $this->assertSame([], $this->gateway->cancelled);
    }

    #[Test]
    public function a_link_for_one_shop_cannot_download_another_shops_invoice(): void
    {
        // Shop A has to know her, or there would be no link to follow — which
        // is itself the correct behaviour and is proved a few tests down.
        $this->orderIn($this->shopA, 'noten-paket', 'anna@example.de');
        $inB = $this->orderIn($this->shopB, 'chorheft', 'anna@example.de');

        $this->followLinkFor('anna@example.de', 'shop-a');

        $this->get(route('statamic-payments.portal.invoice', ['payOrder' => $inB->getKey()]))->assertNotFound();
    }

    #[Test]
    public function a_row_that_belongs_to_no_brand_belongs_to_nobody(): void
    {
        // What a webhook leaves behind when it could not work out whose sale it
        // was: `brand_id` zero. On a single-brand install that is every row and
        // it is right. Here it is a row nobody may see, and the link that would
        // claim it is refused at the door.
        $orphan = Payment::create([
            'provider' => 'fake',
            'provider_id' => 'tr_waise',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'anna@example.de',
            'paid_at' => now(),
        ]);

        $this->assertSame(0, (int) $orphan->brand_id);

        $this->orderIn($this->shopA, 'noten-paket', 'anna@example.de');

        $this->followLinkFor('anna@example.de', 'shop-a');

        $this->get(route('statamic-payments.portal.order', ['payOrder' => $orphan->getKey()]))->assertNotFound();
    }

    #[Test]
    public function each_shop_that_knows_the_address_gets_its_own_mail(): void
    {
        $this->orderIn($this->shopA, 'noten-paket', 'anna@example.de');
        $this->orderIn($this->shopB, 'chorheft', 'anna@example.de');

        Mail::fake();

        // No brand named — the ordinary case, because the form has no brand
        // field. The address answers it, and it answers "both".
        $this->post(route('statamic-payments.portal.request.send'), ['email' => 'anna@example.de']);

        // Two mails, not one mail with two links. One mail speaking for two
        // shops has no correct sender, and a transport that verifies sending
        // domains per account refuses or rewrites it.
        Mail::assertSent(PortalLinkMail::class, 2);
    }

    #[Test]
    public function a_shop_that_does_not_know_the_address_issues_no_link(): void
    {
        $this->orderIn($this->shopA, 'noten-paket', 'anna@example.de');

        Mail::fake();

        $this->post(route('statamic-payments.portal.request.send'), [
            'email' => 'anna@example.de',
            'payBrand' => 'shop-b',
        ]);

        Mail::assertNothingSent();
    }

    /** Ask for a link as the named shop, then follow it as a browser would. */
    protected function followLinkFor(string $email, string $brandHandle): void
    {
        Mail::fake();

        $this->post(route('statamic-payments.portal.request.send'), [
            'email' => $email,
            'payBrand' => $brandHandle,
        ]);

        $url = null;

        Mail::assertSent(PortalLinkMail::class, function (PortalLinkMail $mail) use (&$url) {
            $url ??= $mail->url;

            return true;
        });

        $this->assertNotNull($url, 'no link was mailed for '.$brandHandle);

        $this->get((string) $url)->assertRedirect(route('statamic-payments.portal.show'));
    }
}
