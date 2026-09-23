<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Gateways\MollieGateway;
use Goldnead\StatamicPayments\Gateways\StripeGateway;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Mollie\Api\Fake\MockMollieClient;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Api\Http\Requests\GetPaginatedMandateRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use PHPUnit\Framework\Attributes\Test;

/**
 * What goes over the wire for pause, switch and card expiry (P1, P2, P3).
 *
 * Stripe against its own answer shapes through `Http::fake()`, Mollie against
 * the SDK's own mock client. Both assert the request that went out, not only
 * that a method was called.
 */
class ProviderSubscriptionControlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function stripe(): StripeGateway
    {
        return new StripeGateway('sk_test_notARealKey', 'https://api.stripe.com');
    }

    /** @return array<string, mixed> */
    protected function subscription(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'sub_test_1',
            'object' => 'subscription',
            'customer' => 'cus_TestBuyer',
            'status' => 'active',
            'current_period_end' => 1790000000,
            'pause_collection' => null,
            'items' => ['data' => [[
                'id' => 'si_test_1',
                'price' => ['recurring' => ['interval' => 'month', 'interval_count' => 1]],
            ]]],
            'metadata' => [],
        ], $overrides);
    }

    #[Test]
    public function stripe_pauses_with_void_and_a_resume_date(): void
    {
        Http::fake([
            'api.stripe.com/v1/subscriptions/sub_test_1' => fn (Request $r) => $r->method() === 'POST'
                ? Http::response($this->subscription(['pause_collection' => ['behavior' => 'void', 'resumes_at' => 1796000000]]))
                : Http::response($this->subscription()),
        ]);

        $remote = $this->stripe()->pauseSubscription('cus_TestBuyer', 'sub_test_1', Carbon::parse('2026-12-01 00:00:00', 'UTC'));

        $this->assertSame(Subscription::STATUS_PAUSED, $remote->status, 'a paused collection read as running');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r['pause_collection']['behavior'] === 'void'
            && (int) $r['pause_collection']['resumes_at'] === Carbon::parse('2026-12-01 00:00:00', 'UTC')->getTimestamp());
    }

    #[Test]
    public function stripe_resumes_by_clearing_the_pause(): void
    {
        Http::fake([
            'api.stripe.com/v1/subscriptions/sub_test_1' => Http::response($this->subscription()),
        ]);

        $remote = $this->stripe()->resumeSubscription('cus_TestBuyer', 'sub_test_1');

        $this->assertSame(Subscription::STATUS_ACTIVE, $remote->status);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->body() === 'pause_collection=');
    }

    #[Test]
    public function stripe_refuses_to_pause_somebody_elses_agreement(): void
    {
        Http::fake([
            'api.stripe.com/v1/subscriptions/sub_test_1' => Http::response($this->subscription(['customer' => 'cus_Other'])),
        ]);

        $this->expectException(\RuntimeException::class);

        try {
            $this->stripe()->pauseSubscription('cus_TestBuyer', 'sub_test_1');
        } finally {
            Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
        }
    }

    #[Test]
    public function stripe_takes_a_new_price_on_the_same_item_without_prorating(): void
    {
        Http::fake([
            'api.stripe.com/v1/products' => Http::response(['id' => 'prod_test_1']),
            'api.stripe.com/v1/subscriptions/sub_test_1' => Http::response($this->subscription()),
        ]);

        $this->stripe()->updateSubscription('cus_TestBuyer', 'sub_test_1', [
            'amount' => ['currency' => 'EUR', 'value' => '29.00'],
            'description' => 'Plus',
        ]);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/v1/subscriptions/sub_test_1')
            && $r['items'][0]['id'] === 'si_test_1'
            && (int) $r['items'][0]['price_data']['unit_amount'] === 2900
            && $r['items'][0]['price_data']['recurring']['interval'] === 'month'
            && $r['proration_behavior'] === 'none');
    }

    #[Test]
    public function stripe_creates_an_agreement_with_the_idempotency_key_it_was_given(): void
    {
        Http::fake([
            'api.stripe.com/v1/products' => Http::response(['id' => 'prod_test_1']),
            'api.stripe.com/v1/subscriptions' => Http::response($this->subscription()),
        ]);

        $this->stripe()->createSubscription('cus_TestBuyer', [
            'amount' => ['currency' => 'EUR', 'value' => '19.00'],
            'interval' => '1 month',
            'description' => 'Mitgliedschaft',
            'idempotencyKey' => 'statamic-payments-resume-7-1790000000',
        ]);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/subscriptions')
            && $r->header('Idempotency-Key') === ['statamic-payments-resume-7-1790000000']
            && ! isset($r['idempotencyKey']));
    }

    #[Test]
    public function mollie_creates_an_agreement_with_the_idempotency_key_it_was_given(): void
    {
        $this->needsMollieMocks();

        [$gateway, $client] = $this->mollie([
            CreateSubscriptionRequest::class => MockResponse::created($this->mollieSubscription('19.00')),
        ]);

        $gateway->createSubscription('cst_1', [
            'amount' => ['currency' => 'EUR', 'value' => '19.00'],
            'interval' => '1 month',
            'description' => 'Mitgliedschaft',
            'idempotencyKey' => 'statamic-payments-resume-7-1790000000',
        ]);

        $client->assertSent(function ($pending) {
            $body = json_decode((string) $pending->createPsrRequest()->getBody(), true);

            return $pending->headers()->get('Idempotency-Key') === 'statamic-payments-resume-7-1790000000'
                && ! isset($body['idempotencyKey']);
        });
    }

    #[Test]
    public function stripe_lists_a_customers_agreements_with_their_metadata(): void
    {
        Http::fake([
            'api.stripe.com/v1/subscriptions*' => Http::response(['data' => [
                $this->subscription(['id' => 'sub_neu', 'metadata' => ['resumed_subscription_id' => '7']]),
            ]]),
        ]);

        $liste = $this->stripe()->subscriptionsFor('cus_TestBuyer');

        $this->assertSame('sub_neu', $liste[0]->providerId);
        $this->assertSame('7', $liste[0]->meta['resumed_subscription_id']);
        Http::assertSent(fn (Request $r) => $r['customer'] === 'cus_TestBuyer' && $r['status'] === 'all');
    }

    #[Test]
    public function stripe_reads_the_expiry_of_the_newest_card(): void
    {
        Http::fake([
            'api.stripe.com/v1/customers/cus_TestBuyer/payment_methods*' => Http::response(['data' => [
                ['id' => 'pm_1', 'card' => ['exp_month' => 2, 'exp_year' => 2027]],
            ]]),
        ]);

        $this->assertSame('2027-02-28', $this->stripe()->cardExpiry('cus_TestBuyer')?->toDateString());
    }

    #[Test]
    public function stripe_without_a_card_has_no_expiry(): void
    {
        Http::fake(['api.stripe.com/v1/customers/cus_TestBuyer/payment_methods*' => Http::response(['data' => []])]);

        $this->assertNull($this->stripe()->cardExpiry('cus_TestBuyer'));
    }

    // ------------------------------------------------------------------ Mollie

    /**
     * The SDK's mock client exists from v3 on; composer also allows v2, which
     * CI's prefer-lowest job installs. Asked first in each test, before the
     * `MockResponse` arguments are built.
     */
    protected function needsMollieMocks(): void
    {
        if (! class_exists(MockMollieClient::class)) {
            $this->markTestSkipped('mollie/mollie-api-php v2 has no mock client');
        }
    }

    protected function mollie(array $responses): array
    {

        $client = new MockMollieClient($responses);

        return [new MollieGateway($client), $client];
    }

    /** @return array<string, mixed> */
    protected function mollieSubscription(string $value): array
    {
        return [
            'resource' => 'subscription', 'id' => 'sub_mollie_1', 'customerId' => 'cst_1',
            'status' => 'active', 'amount' => ['currency' => 'EUR', 'value' => $value],
            'interval' => '1 month', 'nextPaymentDate' => '2026-10-05', 'metadata' => null,
        ];
    }

    #[Test]
    public function mollie_updates_the_amount_in_place(): void
    {
        $this->needsMollieMocks();

        [$gateway, $client] = $this->mollie([
            UpdateSubscriptionRequest::class => MockResponse::ok($this->mollieSubscription('29.00')),
        ]);

        $remote = $gateway->updateSubscription('cst_1', 'sub_mollie_1', [
            'amount' => ['currency' => 'EUR', 'value' => '29.00'],
            'description' => 'Plus',
        ]);

        $this->assertSame(Subscription::STATUS_ACTIVE, $remote->status);
        $client->assertSent(function ($pending) {
            $body = json_decode((string) $pending->createPsrRequest()->getBody(), true);

            return $body['amount'] === ['currency' => 'EUR', 'value' => '29.00'] && $body['description'] === 'Plus';
        });
    }

    #[Test]
    public function mollie_reads_the_expiry_of_the_newest_valid_card_mandate(): void
    {
        $this->needsMollieMocks();

        [$gateway] = $this->mollie([
            GetPaginatedMandateRequest::class => MockResponse::ok([
                'count' => 3,
                '_embedded' => ['mandates' => [
                    ['resource' => 'mandate', 'id' => 'mdt_old', 'status' => 'valid', 'method' => 'creditcard',
                        'createdAt' => '2025-01-01T10:00:00+00:00', 'details' => ['cardExpiryDate' => '2026-01-31']],
                    ['resource' => 'mandate', 'id' => 'mdt_new', 'status' => 'valid', 'method' => 'creditcard',
                        'createdAt' => '2026-02-01T10:00:00+00:00', 'details' => ['cardExpiryDate' => '2028-06-30']],
                    ['resource' => 'mandate', 'id' => 'mdt_sepa', 'status' => 'valid', 'method' => 'directdebit',
                        'createdAt' => '2026-03-01T10:00:00+00:00', 'details' => ['consumerAccount' => 'NL55INGB0000000000']],
                ]],
                '_links' => ['self' => ['href' => 'x', 'type' => 'application/hal+json'], 'next' => null, 'previous' => null],
            ]),
        ]);

        $this->assertSame('2028-06-30', $gateway->cardExpiry('cst_1')?->toDateString());
    }
}
