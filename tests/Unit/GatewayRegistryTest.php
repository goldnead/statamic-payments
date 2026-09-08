<?php

namespace Goldnead\StatamicPayments\Tests\Unit;

use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Gateways\FreeGateway;
use Goldnead\StatamicPayments\Gateways\MollieGateway;
use Goldnead\StatamicPayments\Gateways\StripeGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\CheckoutSession;
use Goldnead\StatamicPayments\Support\Gateways;
use Goldnead\StatamicPayments\Support\RemotePayment;
use Goldnead\StatamicPayments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * The handle on the row decides which provider is asked.
 *
 * The bug this replaces was quiet: one global binding, so a webhook from the
 * second provider was handed to the first, which answered "not mine" and left a
 * paid order unfulfilled with nothing in the log.
 */
class GatewayRegistryTest extends TestCase
{
    protected function registry(): Gateways
    {
        return app(Gateways::class);
    }

    #[Test]
    public function the_default_binding_answers_for_its_own_handle(): void
    {
        // The suite binds a fake that calls itself `fake`. Asking for that
        // handle must reach it, not build a real provider behind its back.
        $this->assertSame($this->gateway, $this->registry()->resolve('fake'));
    }

    #[Test]
    public function a_row_without_a_handle_falls_back_to_the_binding(): void
    {
        // Rows written before the column was filled in. They belong to whatever
        // the site was using then, which is the binding.
        $this->assertSame($this->gateway, $this->registry()->resolve(''));
        $this->assertSame($this->gateway, $this->registry()->resolve(null));
    }

    #[Test]
    public function free_resolves_without_reaching_a_provider(): void
    {
        $gateway = $this->registry()->resolve('free');

        $this->assertInstanceOf(FreeGateway::class, $gateway);
        $this->assertSame('free', $gateway->provider());

        // A zero-price order is settled by definition — there is nothing
        // outstanding for a provider to be asked about.
        $this->assertTrue($gateway->fetch('free-7')->isPaid());
    }

    #[Test]
    public function stripe_ships_registered(): void
    {
        $this->assertInstanceOf(StripeGateway::class, $this->registry()->resolve('stripe'));
        $this->assertContains('stripe', $this->registry()->handles());
    }

    #[Test]
    public function a_host_can_add_a_provider(): void
    {
        $this->registry()->register('paypal', fn () => new class implements PaymentGateway
        {
            public function createPayment(array $payload): CheckoutSession
            {
                return new CheckoutSession('pp_1', 'https://example.test');
            }

            public function fetch(string $providerId): RemotePayment
            {
                return new RemotePayment($providerId, Payment::STATUS_OPEN);
            }

            public function provider(): string
            {
                return 'paypal';
            }
        });

        $this->assertSame('paypal', $this->registry()->resolve('paypal')->provider());
        $this->assertTrue($this->registry()->has('paypal'));
    }

    #[Test]
    public function the_handle_is_read_off_the_row(): void
    {
        $payment = Payment::create([
            'provider' => 'stripe',
            'provider_id' => 'cs_test_row',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_OPEN,
            'email' => 'kaeufer@example.com',
        ]);

        $this->assertInstanceOf(StripeGateway::class, $this->registry()->for($payment));
    }

    #[Test]
    public function existing_mollie_rows_still_resolve_after_a_site_switches_to_stripe(): void
    {
        // The migration path the README describes: a host binds Stripe as its
        // default. Every historic order still says `mollie`, and the customer
        // portal asks about them on every page load — so a handle that only
        // resolved through the default binding would turn the whole order
        // history into a 500.
        $this->app->instance(PaymentGateway::class, new StripeGateway('sk_test_x'));

        $this->assertSame('mollie', $this->registry()->resolve('mollie')->provider());
        $this->assertInstanceOf(MollieGateway::class, $this->registry()->resolve('mollie'));
        $this->assertSame('stripe', $this->registry()->resolve('stripe')->provider());
    }

    #[Test]
    public function the_binding_wins_for_its_own_handle_so_a_host_subclass_is_not_replaced(): void
    {
        // A host that bound its own subclass means *that* object when it says
        // the handle. Handing back the stock class instead would silently drop
        // whatever it was subclassed for.
        $own = new class('sk_test_x') extends StripeGateway {};

        $this->app->instance(PaymentGateway::class, $own);

        $this->assertSame($own, $this->registry()->resolve('stripe'));
    }

    #[Test]
    public function an_unregistered_handle_is_refused_and_not_quietly_defaulted(): void
    {
        // The whole point. A silent fallback to the default gateway is exactly
        // the failure this registry exists to remove: the wrong provider is
        // asked about an id it cannot know, says no, and an order that was paid
        // for is never delivered.
        $this->assertFalse($this->registry()->has('adyen'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/adyen/');

        $this->registry()->resolve('adyen');
    }

    #[Test]
    public function the_handle_is_matched_regardless_of_spelling(): void
    {
        $this->assertInstanceOf(StripeGateway::class, $this->registry()->resolve('  STRIPE '));
    }
}
