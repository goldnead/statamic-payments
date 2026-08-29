<?php

namespace Goldnead\StatamicPayments\Tests\Feature\Portal;

use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Portal\Mail\PortalLinkMail;
use Goldnead\StatamicPayments\Tests\Support\MandateFakeGateway;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;

/**
 * Putting a different card on file.
 *
 * The first two tests are about the seam rather than the feature: with a
 * provider that cannot take a new mandate, the button is not on the page and the
 * route says so politely instead of throwing. That is what "a second provider
 * fits beside this later" has to mean in practice — the screen asks the gateway
 * what it can do and never asks it by name.
 */
class PaymentMethodTest extends TestCase
{
    protected Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->subscription = Subscription::create([
            'provider' => 'fake',
            'provider_id' => 'sub_1',
            'customer_reference' => 'cst_1',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'interval' => '1 month',
            'times_charged' => 1,
            'status' => Subscription::STATUS_ACTIVE,
            'next_payment_at' => now()->addMonth(),
            'email' => 'anna@example.de',
        ]);

        $this->signIn('anna@example.de');
    }

    /** Swap in a provider that can do mandates, and let it know this customer. */
    protected function withMandateGateway(): MandateFakeGateway
    {
        $gateway = new MandateFakeGateway;
        $gateway->mandates[] = 'cst_1';

        $this->app->instance(PaymentGateway::class, $gateway);

        return $gateway;
    }

    #[Test]
    public function a_provider_that_cannot_do_it_shows_no_button(): void
    {
        // The bound gateway is the ordinary fake, which is a SubscriptionGateway
        // and not a MandateGateway.
        $this->get(route('statamic-payments.portal.show'))
            ->assertOk()
            ->assertDontSee(__('statamic-payments::portal.method_button'));
    }

    #[Test]
    public function and_the_route_says_so_rather_than_throwing(): void
    {
        $this->post(route('statamic-payments.portal.method.start', ['paySubscription' => $this->subscription->getKey()]))
            ->assertRedirect(route('statamic-payments.portal.show'))
            ->assertSessionHas('statamic-payments.portal.status', __('statamic-payments::portal.method_unavailable'));
    }

    #[Test]
    public function a_provider_that_can_do_it_shows_the_button_and_the_price_of_pressing_it(): void
    {
        $this->withMandateGateway();

        $this->get(route('statamic-payments.portal.show'))
            ->assertOk()
            ->assertSee(__('statamic-payments::portal.method_button'))
            // The charge is named before the button, not after it. On Mollie
            // there is no way to store a card without taking money, and hiding
            // that would be the wrong kind of convenience.
            ->assertSee('0.01 EUR');
    }

    #[Test]
    public function pressing_it_sends_the_buyer_to_the_provider(): void
    {
        $gateway = $this->withMandateGateway();

        $this->post(route('statamic-payments.portal.method.start', ['paySubscription' => $this->subscription->getKey()]))
            ->assertRedirect('https://checkout.example/mandat/1');

        $this->assertSame(1, $gateway->mandateUpdatesStarted);
        $this->assertSame('cst_1', $gateway->lastMandateCustomer);
        $this->assertSame(['currency' => 'EUR', 'value' => '0.01'], $gateway->lastMandatePayload['amount']);
        $this->assertSame(
            route('statamic-payments.portal.method.return'),
            $gateway->lastMandatePayload['redirectUrl'],
        );

        // No webhook — the strict double refuses one, because a paid payment
        // with no local row reaches the fulfilment path as a phantom purchase.
        $this->assertArrayNotHasKey('webhookUrl', $gateway->lastMandatePayload);
    }

    #[Test]
    public function no_order_is_invented_for_the_verification_charge(): void
    {
        $this->withMandateGateway();

        $before = Payment::count();

        $this->post(route('statamic-payments.portal.method.start', ['paySubscription' => $this->subscription->getKey()]));

        // The buyer's order history is a history of what they bought. A cent
        // spent proving a card works is not a purchase, and a row for it would
        // land in every revenue report this addon feeds.
        $this->assertSame($before, Payment::count());
    }

    #[Test]
    public function a_provider_that_refuses_leaves_the_agreement_alone(): void
    {
        $gateway = $this->withMandateGateway();
        $gateway->refuseMandateUpdate = true;

        $this->post(route('statamic-payments.portal.method.start', ['paySubscription' => $this->subscription->getKey()]))
            ->assertRedirect(route('statamic-payments.portal.show'));

        $this->assertSame(0, $gateway->mandateUpdatesStarted);
        $this->assertSame(Subscription::STATUS_ACTIVE, $this->subscription->fresh()->status);
    }

    #[Test]
    public function an_agreement_that_is_over_cannot_have_its_card_changed(): void
    {
        $gateway = $this->withMandateGateway();

        $this->subscription->forceFill([
            'status' => Subscription::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ])->save();

        $this->get(route('statamic-payments.portal.show'))
            ->assertDontSee(__('statamic-payments::portal.method_button'));

        $this->post(route('statamic-payments.portal.method.start', ['paySubscription' => $this->subscription->getKey()]))
            ->assertSessionHas('statamic-payments.portal.status', __('statamic-payments::portal.method_unavailable'));

        $this->assertSame(0, $gateway->mandateUpdatesStarted);
    }

    #[Test]
    public function a_buyer_cannot_change_the_card_on_somebody_elses_agreement(): void
    {
        $gateway = $this->withMandateGateway();
        $gateway->mandates[] = 'cst_2';

        $someoneElse = Subscription::create([
            'provider' => 'fake',
            'provider_id' => 'sub_2',
            'customer_reference' => 'cst_2',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'interval' => '1 month',
            'times_charged' => 0,
            'status' => Subscription::STATUS_ACTIVE,
            'email' => 'boris@example.de',
        ]);

        $this->post(route('statamic-payments.portal.method.start', ['paySubscription' => $someoneElse->getKey()]))
            ->assertNotFound();

        $this->assertSame(0, $gateway->mandateUpdatesStarted);
    }

    #[Test]
    public function coming_back_claims_nothing(): void
    {
        $this->withMandateGateway();

        // The buyer reaching a return URL is not evidence that anything worked,
        // here as everywhere else in this package. The page says the change was
        // started, never that it succeeded.
        $this->get(route('statamic-payments.portal.method.return'))
            ->assertRedirect(route('statamic-payments.portal.show'))
            ->assertSessionHas('statamic-payments.portal.status', __('statamic-payments::portal.method_returned'));
    }

    protected function signIn(string $email): void
    {
        Mail::fake();

        $this->post(route('statamic-payments.portal.request.send'), ['email' => $email]);

        $url = null;

        Mail::assertSent(PortalLinkMail::class, function (PortalLinkMail $mail) use (&$url) {
            $url ??= $mail->url;

            return true;
        });

        $this->get((string) $url)->assertRedirect(route('statamic-payments.portal.show'));
    }
}
