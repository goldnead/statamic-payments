<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Gateways\MollieGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use Mollie\Api\Fake\MockMollieClient;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use PHPUnit\Framework\Attributes\Test;

/**
 * Two clicks on "buy" are one purchase.
 *
 * The harm of the second payment is real money: two open Mollie payments, and
 * a buyer who finishes both. The door is the checkout start, so that is where
 * it is closed.
 */
class CheckoutDoubleClickTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // One process cannot wait for a request that is "elsewhere"; the wait
        // is for real concurrency and costs three seconds here for nothing.
        config(['statamic-payments.checkout.wait_ms' => 0]);
    }

    protected function start(string $product = 'noten-paket', string $email = 'kim@example.com'): mixed
    {
        return app(Checkout::class)->start($product, ['email' => $email]);
    }

    #[Test]
    public function a_second_start_returns_the_open_payment_instead_of_a_new_one(): void
    {
        $first = $this->start();
        $second = $this->start();

        $this->assertSame($first->payment->getKey(), $second->payment->getKey());
        $this->assertSame($first->checkoutUrl, $second->checkoutUrl);
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, $this->gateway->created, 'the provider was asked to create a second payment');
    }

    #[Test]
    public function the_address_is_compared_without_regard_to_case(): void
    {
        $first = $this->start(email: 'Kim@Example.com');
        $second = $this->start(email: 'kim@example.com');

        $this->assertSame($first->payment->getKey(), $second->payment->getKey());
    }

    #[Test]
    public function another_buyer_or_another_basket_is_another_payment(): void
    {
        config(['statamic-payments.products.chor-paket' => ['name' => 'Chorpaket', 'amount_cent' => 2900]]);

        $this->start();
        $this->start(email: 'lea@example.com');
        $this->start(product: 'chor-paket');

        $this->assertSame(3, Payment::count());
        $this->assertSame(3, $this->gateway->created);
    }

    #[Test]
    public function a_corrected_country_or_name_is_not_the_same_purchase(): void
    {
        // The country is frozen on the row and drives the tax rate. A buyer who
        // fixes it and presses again must not be handed the row with the old one.
        app(Checkout::class)->start('noten-paket', ['email' => 'kim@example.com', 'country' => 'DE', 'name' => 'Kim']);
        app(Checkout::class)->start('noten-paket', ['email' => 'kim@example.com', 'country' => 'AT', 'name' => 'Kim']);
        app(Checkout::class)->start('noten-paket', ['email' => 'kim@example.com', 'country' => 'AT', 'name' => 'Kim B.']);

        $this->assertSame(3, Payment::count());
    }

    #[Test]
    public function when_the_provider_refuses_the_replay_the_row_that_is_ready_by_now_is_returned(): void
    {
        // Mollie can answer a request that repeats a key still being worked on
        // with 409. By then the first request has usually finished: its URL is
        // on the row, and that is the answer. Config 0 = do not wait for it.
        config(['statamic-payments.checkout.wait_ms' => 0]);

        $inner = null;

        $this->gateway->whileCalling = function () use (&$inner) {
            $row = Payment::query()->latest('id')->first();

            // From here on the provider answers the repeated key with a
            // conflict, after the first request has written its URL.
            $this->gateway->whileCalling = function () use ($row) {
                $row->forceFill(['status' => Payment::STATUS_OPEN, 'meta' => ['checkout_url' => 'https://checkout.example/tr_x']])->save();

                throw new \RuntimeException('409 Conflict');
            };

            $inner = $this->start();
        };

        $this->start();

        $this->assertSame('https://checkout.example/tr_x', $inner->checkoutUrl);
        $this->assertSame(1, Payment::count());
    }

    #[Test]
    public function a_paid_payment_does_not_swallow_the_next_purchase(): void
    {
        $first = $this->start();
        $this->gateway->markPaid($first->payment->provider_id);
        $first->payment->forceFill(['status' => Payment::STATUS_PAID])->save();

        $second = $this->start();

        $this->assertNotSame($first->payment->getKey(), $second->payment->getKey());
        $this->assertSame(2, Payment::count());
    }

    #[Test]
    public function after_a_few_minutes_a_start_is_a_new_attempt(): void
    {
        $first = $this->start();

        Carbon::setTestNow(Carbon::now()->addMinutes(11));

        $second = $this->start();

        Carbon::setTestNow();

        $this->assertNotSame($first->payment->getKey(), $second->payment->getKey());
    }

    #[Test]
    public function the_provider_gets_a_key_that_makes_its_own_create_idempotent(): void
    {
        $this->start();

        $key = $this->gateway->lastPayload['idempotencyKey'] ?? null;

        $this->assertIsString($key);
        $this->assertNotSame('', $key);
    }

    #[Test]
    public function mollie_creates_the_payment_with_the_idempotency_key_it_was_given(): void
    {
        if (! class_exists(MockMollieClient::class)) {
            $this->markTestSkipped('mollie/mollie-api-php v2 has no mock client');
        }

        $client = new MockMollieClient([
            CreatePaymentRequest::class => MockResponse::created([
                'resource' => 'payment', 'id' => 'tr_abc123', 'status' => 'open',
                'amount' => ['currency' => 'EUR', 'value' => '19.00'],
                'description' => 'Notenpaket', 'metadata' => null,
                '_links' => ['checkout' => ['href' => 'https://www.mollie.com/checkout/select-method/abc123', 'type' => 'text/html']],
            ]),
        ]);

        $session = (new MollieGateway($client))->createPayment([
            'amount' => ['currency' => 'EUR', 'value' => '19.00'],
            'description' => 'Notenpaket',
            'redirectUrl' => 'https://example.com/danke',
            'idempotencyKey' => 'checkout-key-1',
        ]);

        $this->assertSame('tr_abc123', $session->providerId);

        $client->assertSent(function ($pending) {
            $body = json_decode((string) $pending->createPsrRequest()->getBody(), true);

            return $pending->headers()->get('Idempotency-Key') === 'checkout-key-1'
                && ! isset($body['idempotencyKey']);
        });
    }

    #[Test]
    public function a_second_request_while_the_first_is_still_at_the_provider_makes_no_second_payment(): void
    {
        // The real double click: both requests are in flight at once. The
        // second one starts while the first has its row but no provider id yet,
        // which is exactly the state `whileCalling` lets a test stand in.
        $inner = null;
        $this->gateway->whileCalling = function (array $payload) use (&$inner) {
            if ($inner === null) {
                $inner = 'running';
                $inner = $this->start();
            }
        };

        $outer = $this->start();

        $this->assertSame(1, Payment::count(), 'the concurrent start wrote a second payment row');
        $this->assertSame(1, $this->gateway->created, 'the provider created two payments');
        $this->assertSame($outer->checkoutUrl, $inner->checkoutUrl);
        $this->assertSame($outer->payment->getKey(), $inner->payment->getKey());
    }
}
