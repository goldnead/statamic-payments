<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Gateways\MollieGateway;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\ProviderUnavailable;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Mollie\Api\Exceptions\NotFoundException;
use Mollie\Api\Exceptions\RetryableNetworkRequestException;
use Mollie\Api\Fake\MockMollieClient;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Fake\SequenceMockResponse;
use Mollie\Api\Http\LinearRetryStrategy;
use Mollie\Api\Http\PendingRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * A Mollie webhook during a Mollie outage must not be answered with 200.
 *
 * Mollie redelivers on anything but a 200 (10 calls over 26 hours,
 * https://docs.mollie.com/reference/webhooks), so a 5xx from us is the whole
 * recovery mechanism. A 200 with nothing done ends it.
 */
class MollieWebhookOutageTest extends TestCase
{
    #[Test]
    public function an_unreachable_provider_makes_the_webhook_answer_5xx_and_a_redelivery_fulfils(): void
    {
        Event::fake([PaymentPaid::class]);

        $payment = app(Checkout::class)->start('noten-paket', ['email' => 'kaeufer@example.com'])->payment;
        $this->gateway->markPaid($payment->provider_id);

        $this->gateway->unavailableOnFetch = true;

        $this->postJson('/!/statamic-payments/webhook', ['id' => $payment->provider_id])->assertStatus(503);

        $this->assertFalse($payment->fresh()->isFulfilled());
        Event::assertNotDispatched(PaymentPaid::class);

        // Mollie calls again, the provider answers this time.
        $this->gateway->unavailableOnFetch = false;

        $this->postJson('/!/statamic-payments/webhook', ['id' => $payment->provider_id])->assertOk();

        $this->assertTrue($payment->fresh()->isFulfilled());
        Event::assertDispatchedTimes(PaymentPaid::class, 1);
    }

    #[Test]
    public function a_payment_the_provider_does_not_know_is_still_answered_200(): void
    {
        // An answer, not an outage: retrying a stray id for 26 hours helps nobody.
        $this->postJson('/!/statamic-payments/webhook', ['id' => 'tr_fremd'])->assertOk();
    }

    // ---------------------------------------------------------- the real SDK

    protected function mollie(array $responses): MollieGateway
    {
        if (! class_exists(MockMollieClient::class)) {
            $this->markTestSkipped('mollie/mollie-api-php v2 has no mock client');
        }

        return new MollieGateway(new MockMollieClient($responses));
    }

    /** @return array<string, array{0: int}> */
    public static function outageStatuses(): array
    {
        return [
            '500 internal error' => [500],
            '502 bad gateway' => [502],
            '503 unavailable' => [503],
            '504 gateway timeout' => [504],
            '429 rate limit' => [429],
            '408 request timeout' => [408],
        ];
    }

    #[Test]
    #[DataProvider('outageStatuses')]
    public function the_gateway_says_ask_again_later_for_what_is_not_an_answer(int $status): void
    {
        $gateway = $this->mollie([
            GetPaymentRequest::class => MockResponse::error($status, 'Upstream', 'Not now'),
        ]);

        $this->expectException(ProviderUnavailable::class);

        $gateway->fetch('tr_abc123');
    }

    #[Test]
    public function a_network_failure_is_ask_again_later_too(): void
    {
        // The SDK retries a network error by itself (5 times, with a growing
        // pause). No pause here, and enough failures to outlast the retries:
        // what reaches the gateway is the last one.
        $fail = fn (PendingRequest $request) => throw new RetryableNetworkRequestException($request, 'Connection failed');

        $client = new MockMollieClient([
            GetPaymentRequest::class => new SequenceMockResponse(...array_fill(0, 6, $fail)),
        ]);
        $client->setRetryStrategy(new LinearRetryStrategy(5, 0));

        $this->mollie([]);

        $this->expectException(ProviderUnavailable::class);

        (new MollieGateway($client))->fetch('tr_abc123');
    }

    #[Test]
    public function a_404_stays_an_answer(): void
    {
        $gateway = $this->mollie([
            GetPaymentRequest::class => MockResponse::notFound(),
        ]);

        $this->expectException(NotFoundException::class);

        $gateway->fetch('tr_fremd');
    }
}
