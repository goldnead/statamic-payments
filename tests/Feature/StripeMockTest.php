<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Gateways\StripeGateway;
use Goldnead\StatamicPayments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The adapter against Stripe's own API surface.
 *
 * `Http::fake()` proves this package reads Stripe's answers correctly. It
 * cannot prove that what goes *out* is a request Stripe would accept — a fake
 * takes any URL and any form body, so a misspelled parameter or a wrongly
 * nested array passes it silently and fails at the till.
 *
 * `stripe/stripe-mock` is Stripe's own server built from their OpenAPI spec: it
 * validates the path, the method and every parameter, and answers 400 for
 * anything the real API would refuse. That is the half a double cannot cover.
 *
 * Run it, then the suite:
 *
 * ```bash
 * docker run --rm -d -p 12111:12111 --name stripe-mock stripe/stripe-mock:latest
 * STRIPE_MOCK_BASE=http://127.0.0.1:12111 vendor/bin/phpunit
 * ```
 *
 * Skipped when it is not there, which is how CI runs: the workflow has no
 * service containers, and a test that quietly needed one would be a test that
 * fails on somebody else's machine for a reason they cannot see.
 */
class StripeMockTest extends TestCase
{
    protected string $base = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = (string) (getenv('STRIPE_MOCK_BASE') ?: '');

        if ($this->base === '' || ! $this->reachable($this->base)) {
            $this->markTestSkipped('stripe-mock is not running; set STRIPE_MOCK_BASE to use it.');
        }
    }

    protected function reachable(string $base): bool
    {
        $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);

        return @file_get_contents(rtrim($base, '/').'/v1/customers', false, $context) !== false;
    }

    protected function stripe(): StripeGateway
    {
        // stripe-mock accepts any bearer token and moves no money. The live key
        // is never read here, and there is nothing for it to do if it were.
        return new StripeGateway('sk_test_123', $this->base);
    }

    #[Test]
    public function a_checkout_session_is_a_request_stripe_would_accept(): void
    {
        $session = $this->stripe()->createPayment([
            'amount' => ['currency' => 'EUR', 'value' => '19.00'],
            'description' => 'Notenpaket',
            'redirectUrl' => 'https://example.test/danke',
            'metadata' => ['payment_id' => 1, 'product' => 'noten-paket'],
        ]);

        // stripe-mock answers from the spec, so the ids are its own. What is
        // proved here is that the request validated at all.
        $this->assertNotSame('', $session->providerId);
        $this->assertStringStartsWith('http', $session->checkoutUrl);
    }

    #[Test]
    public function a_first_payment_that_keeps_the_card_is_accepted_too(): void
    {
        $session = $this->stripe()->createPayment([
            'amount' => ['currency' => 'EUR', 'value' => '19.00'],
            'description' => 'Notenpaket',
            'redirectUrl' => 'https://example.test/danke',
            'customerId' => 'cus_mock',
            'sequenceType' => 'first',
        ]);

        $this->assertNotSame('', $session->providerId);
    }

    #[Test]
    public function fetching_a_session_an_invoice_and_an_intent_all_validate(): void
    {
        $gateway = $this->stripe();

        // Three different objects behind one `fetch()`, and all three shapes
        // have to be legal requests.
        $this->assertNotSame('', $gateway->fetch('cs_test_a1b2c3')->status);
        $this->assertNotSame('', $gateway->fetch('in_test_a1b2c3')->status);
        $this->assertNotSame('', $gateway->fetch('pi_test_a1b2c3')->status);
    }

    #[Test]
    public function remembering_a_buyer_validates(): void
    {
        $this->assertNotSame('', $this->stripe()->rememberBuyer([
            'name' => 'Adrian Goldner',
            'email' => 'kaeufer@example.com',
        ]));
    }

    #[Test]
    public function an_agreement_is_a_request_stripe_would_accept(): void
    {
        $remote = $this->stripe()->createSubscription('cus_mock', [
            'amount' => ['currency' => 'EUR', 'value' => '19.00'],
            'interval' => '1 month',
            'times' => 3,
            'startDate' => now()->addMonth()->toDateString(),
            'description' => 'Monatsabo',
            'metadata' => ['product' => 'monatsabo'],
        ]);

        $this->assertNotSame('', $remote->providerId);
    }

    #[Test]
    public function a_follow_up_charge_is_a_request_stripe_would_accept(): void
    {
        $remote = $this->stripe()->chargeAgain('cus_mock', [
            'amount' => ['currency' => 'EUR', 'value' => '9.00'],
            'description' => 'Zusatz',
            'mandateId' => 'pm_mock_card',
        ]);

        $this->assertNotSame('', $remote->providerId);
    }

    #[Test]
    public function listing_refunds_for_a_charge_validates(): void
    {
        // An array, possibly empty — stripe-mock decides. What matters is that
        // the request itself did not come back 400.
        $this->assertIsArray($this->stripe()->refundsFor('ch_mock'));
    }
}
