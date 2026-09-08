<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Contracts\FollowUpGateway;
use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Contracts\SubscriptionGateway;
use Goldnead\StatamicPayments\Gateways\StripeGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * The Stripe adapter against recorded Stripe answers.
 *
 * The bodies below are Stripe's own shapes, trimmed to the fields this package
 * reads. A double that simply said yes would prove the method was called and
 * nothing about whether the right thing went over the wire — so every test here
 * either reads a real Stripe field out of an answer or asserts the exact form
 * body that went out.
 */
class StripeGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A URL nobody stubbed must fail the test, not quietly reach the real
        // Stripe. Without this a missing stub goes out over the network, which
        // is how a test suite ends up depending on somebody's API key.
        Http::preventStrayRequests();
    }

    protected function stripe(): StripeGateway
    {
        return new StripeGateway('sk_test_notARealKey', 'https://api.stripe.com');
    }

    /** @return array<string, mixed> */
    protected function checkoutSession(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'cs_test_a1b2c3',
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_a1b2c3',
            'status' => 'complete',
            'payment_status' => 'paid',
            'currency' => 'eur',
            'amount_total' => 1900,
            'customer' => 'cus_TestBuyer',
            'subscription' => null,
            'customer_details' => [
                'email' => 'kaeufer@example.com',
                'address' => ['country' => 'DE'],
            ],
            'metadata' => ['payment_id' => '1', 'product' => 'noten-paket'],
            'payment_intent' => [
                'id' => 'pi_test_1',
                'object' => 'payment_intent',
                'status' => 'succeeded',
                'payment_method' => [
                    'id' => 'pm_test_card',
                    'object' => 'payment_method',
                    'card' => ['brand' => 'mastercard', 'last4' => '4444', 'country' => 'DE'],
                ],
            ],
        ], $overrides);
    }

    // ------------------------------------------------------------- a purchase

    #[Test]
    public function it_satisfies_all_three_contracts_without_a_fourth(): void
    {
        $gateway = $this->stripe();

        $this->assertInstanceOf(PaymentGateway::class, $gateway);
        $this->assertInstanceOf(FollowUpGateway::class, $gateway);
        $this->assertInstanceOf(SubscriptionGateway::class, $gateway);
        $this->assertSame('stripe', $gateway->provider());
    }

    #[Test]
    public function a_purchase_creates_a_hosted_checkout_and_reads_its_status_back_from_stripe(): void
    {
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response($this->checkoutSession()),
            'api.stripe.com/v1/checkout/sessions/cs_test_a1b2c3*' => Http::response($this->checkoutSession()),
        ]);

        $gateway = $this->stripe();

        $checkout = $gateway->createPayment([
            'amount' => ['currency' => 'EUR', 'value' => '19.00'],
            'description' => 'Notenpaket',
            'redirectUrl' => 'https://example.test/danke',
            'webhookUrl' => 'https://example.test/!/statamic-payments/webhook',
            'metadata' => ['payment_id' => 1, 'product' => 'noten-paket'],
        ]);

        $this->assertSame('cs_test_a1b2c3', $checkout->providerId);
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_a1b2c3', $checkout->checkoutUrl);

        // The amount that went out, in minor units, and the line Stripe will
        // print. Asserted on the wire rather than on a return value.
        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'POST') {
                return false;
            }

            $data = $request->data();

            return $data['line_items'][0]['price_data']['unit_amount'] === 1900
                && $data['line_items'][0]['price_data']['currency'] === 'eur'
                && $data['line_items'][0]['price_data']['product_data']['name'] === 'Notenpaket'
                && $data['mode'] === 'payment';
        });

        $remote = $gateway->fetch('cs_test_a1b2c3');

        $this->assertTrue($remote->isPaid());
        $this->assertSame('kaeufer@example.com', $remote->email);
        $this->assertSame('DE', $remote->country);
        $this->assertSame('4444', $remote->cardLast4);
        $this->assertSame('Mastercard', $remote->cardLabel);
        $this->assertSame('pm_test_card', $remote->mandateId);
    }

    #[Test]
    public function a_session_that_is_complete_but_unpaid_is_not_read_as_paid(): void
    {
        // The trap: Stripe's `status` says the buyer got to the end of the
        // page. Only `payment_status` says money moved.
        Http::fake(['api.stripe.com/*' => Http::response($this->checkoutSession([
            'status' => 'complete',
            'payment_status' => 'unpaid',
        ]))]);

        $remote = $this->stripe()->fetch('cs_test_a1b2c3');

        $this->assertFalse($remote->isPaid());
        $this->assertSame(Payment::STATUS_OPEN, $remote->status);
    }

    #[Test]
    public function an_unknown_status_lands_on_open_and_never_on_paid(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response($this->checkoutSession([
            'status' => 'something_stripe_added_last_tuesday',
            'payment_status' => 'also_new',
        ]))]);

        $this->assertSame(Payment::STATUS_OPEN, $this->stripe()->fetch('cs_test_a1b2c3')->status);
    }

    #[Test]
    public function an_id_stripe_would_not_know_is_refused_rather_than_guessed_at(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response($this->checkoutSession())]);

        $this->expectException(RuntimeException::class);

        // A Mollie id. Asking Stripe about it would be asking about somebody
        // else's object entirely.
        $this->stripe()->fetch('tr_WDqYK6vllg');
    }

    #[Test]
    public function a_refusal_from_stripe_is_an_error_and_not_an_empty_result(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(
            ['error' => ['message' => 'No such checkout session']], 404,
        )]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No such checkout session/');

        $this->stripe()->fetch('cs_test_missing');
    }

    // --------------------------------------------------------------- amounts

    #[Test]
    public function amounts_go_out_in_the_currencys_own_minor_units(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response($this->checkoutSession())]);

        $gateway = $this->stripe();

        // Yen has no minor unit at all; a hard-coded 100 would bill a hundred
        // times the price. The dinar has three; the same 100 would bill a tenth.
        foreach ([
            ['JPY', '1000', 1000],
            ['EUR', '19.99', 1999],
            ['BHD', '1.000', 1000],
            ['EUR', '0.01', 1],
        ] as [$currency, $value, $expected]) {
            $gateway->createPayment([
                'amount' => ['currency' => $currency, 'value' => $value],
                'description' => 'Notenpaket',
                'redirectUrl' => 'https://example.test/danke',
            ]);

            Http::assertSent(fn (Request $r) => $r->method() === 'POST'
                && ($r->data()['line_items'][0]['price_data']['unit_amount'] ?? null) === $expected
                && ($r->data()['line_items'][0]['price_data']['currency'] ?? null) === strtolower($currency));
        }
    }

    #[Test]
    public function an_amount_that_is_not_an_amount_is_refused(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response($this->checkoutSession())]);

        $this->expectException(RuntimeException::class);

        $this->stripe()->createPayment([
            'amount' => ['currency' => 'EUR', 'value' => 'neunzehn'],
            'description' => 'Notenpaket',
            'redirectUrl' => 'https://example.test/danke',
        ]);
    }

    // ---------------------------------------------------------- an agreement

    /** @return array<string, mixed> */
    protected function subscription(string $status = 'active'): array
    {
        return [
            'id' => 'sub_test_1',
            'object' => 'subscription',
            'status' => $status,
            'customer' => 'cus_TestBuyer',
            'current_period_end' => 1789000000,
            'metadata' => ['product' => 'monatsabo'],
        ];
    }

    #[Test]
    public function an_agreement_runs_through_its_states(): void
    {
        Http::fake([
            'api.stripe.com/v1/subscriptions/sub_test_1' => Http::sequence()
                // fetched — Stripe is waiting for the first invoice
                ->push($this->subscription('incomplete'))
                // fetched again — it went through
                ->push($this->subscription('active'))
                // ownership check before the cancel
                ->push($this->subscription('active'))
                // the cancel itself
                ->push($this->subscription('canceled')),
            'api.stripe.com/v1/subscriptions' => Http::response($this->subscription('active')),
            // A subscription item prices against an existing product, not an
            // inline one. stripe-mock is what said so.
            'api.stripe.com/v1/products' => Http::response(['id' => 'prod_test_1', 'object' => 'product']),
        ]);

        $gateway = $this->stripe();

        $created = $gateway->createSubscription('cus_TestBuyer', [
            'amount' => ['currency' => 'EUR', 'value' => '19.00'],
            'interval' => '1 month',
            'times' => 3,
            'startDate' => now()->addMonth()->toDateString(),
            'description' => 'Monatsabo',
            'metadata' => ['product' => 'monatsabo'],
        ]);

        $this->assertSame(Subscription::STATUS_ACTIVE, $created->status);
        $this->assertTrue($created->isLive());

        // The rhythm, the fixed number of instalments and the deferred start,
        // in Stripe's own vocabulary.
        Http::assertSent(function (Request $r) {
            if (! str_ends_with($r->url(), '/v1/subscriptions') || $r->method() !== 'POST') {
                return false;
            }

            $data = $r->data();

            return $data['items'][0]['price_data']['recurring']['interval'] === 'month'
                && (int) $data['items'][0]['price_data']['recurring']['interval_count'] === 1
                && (int) $data['items'][0]['price_data']['unit_amount'] === 1900
                && isset($data['trial_end'], $data['cancel_at'])
                && $data['cancel_at'] > $data['trial_end'];
        });

        // Every state `Subscriptions::refresh()` mirrors from Mollie has a
        // Stripe word behind it, and the two that are only Stripe's — waiting
        // for a first invoice, and a card that stopped working — land on the
        // safe side.
        $this->assertSame(Subscription::STATUS_PENDING, $gateway->fetchSubscription('cus_TestBuyer', 'sub_test_1')->status);
        $this->assertSame(Subscription::STATUS_ACTIVE, $gateway->fetchSubscription('cus_TestBuyer', 'sub_test_1')->status);

        $cancelled = $gateway->cancelSubscription('cus_TestBuyer', 'sub_test_1');

        $this->assertSame(Subscription::STATUS_CANCELLED, $cancelled->status);
        $this->assertFalse($cancelled->isLive());
    }

    #[Test]
    public function every_stripe_state_lands_on_one_of_ours_and_the_unknown_is_suspended(): void
    {
        $expected = [
            'active' => Subscription::STATUS_ACTIVE,
            'trialing' => Subscription::STATUS_ACTIVE,
            'incomplete' => Subscription::STATUS_PENDING,
            'canceled' => Subscription::STATUS_CANCELLED,
            'incomplete_expired' => Subscription::STATUS_CANCELLED,
            'past_due' => Subscription::STATUS_SUSPENDED,
            'unpaid' => Subscription::STATUS_SUSPENDED,
            'paused' => Subscription::STATUS_SUSPENDED,
            // Never `active`: treating an agreement nobody recognises as
            // running keeps somebody's access open after their card died.
            'invented_by_stripe_tomorrow' => Subscription::STATUS_SUSPENDED,
        ];

        // One sequence rather than a fake per turn: repeated `Http::fake()`
        // calls keep the first stub that matched, which would have every
        // iteration reading the first answer.
        $sequence = Http::sequence();

        foreach (array_keys($expected) as $stripe) {
            $sequence->push($this->subscription($stripe));
        }

        Http::fake(['api.stripe.com/*' => $sequence]);

        $gateway = $this->stripe();

        foreach ($expected as $stripe => $ours) {
            $this->assertSame(
                $ours,
                $gateway->fetchSubscription('cus_TestBuyer', 'sub_test_1')->status,
                "Stripe's [{$stripe}] must land on [{$ours}]",
            );
        }
    }

    #[Test]
    public function an_agreement_belonging_to_somebody_else_is_refused(): void
    {
        // The reason the contract passes the customer with every call: an id on
        // its own would let a mixed-up value reach into another account.
        Http::fake(['api.stripe.com/*' => Http::response($this->subscription())]);

        $this->expectException(RuntimeException::class);

        $this->stripe()->fetchSubscription('cus_SomebodyElse', 'sub_test_1');
    }

    #[Test]
    public function cancelling_one_that_is_already_stopped_is_not_a_failure(): void
    {
        Http::fake([
            'api.stripe.com/v1/subscriptions/sub_test_1' => function (Request $request) {
                return $request->method() === 'DELETE'
                    ? Http::response(['error' => ['message' => 'cannot be canceled']], 400)
                    : Http::response($this->subscription('canceled'));
            },
        ]);

        $this->assertSame(
            Subscription::STATUS_CANCELLED,
            $this->stripe()->cancelSubscription('cus_TestBuyer', 'sub_test_1')->status,
        );
    }

    #[Test]
    public function a_cancel_that_leaves_it_running_is_an_error(): void
    {
        // The one refusal that must not be swallowed: Stripe said no and the
        // agreement is still charging somebody.
        Http::fake([
            'api.stripe.com/v1/subscriptions/sub_test_1' => function (Request $request) {
                return $request->method() === 'DELETE'
                    ? Http::response(['error' => ['message' => 'nope']], 500)
                    : Http::response($this->subscription('active'));
            },
        ]);

        $this->expectException(RuntimeException::class);

        $this->stripe()->cancelSubscription('cus_TestBuyer', 'sub_test_1');
    }

    // ---------------------------------------------------------- a follow-up

    #[Test]
    public function a_follow_up_charges_the_card_the_offer_page_named(): void
    {
        Http::fake([
            'api.stripe.com/v1/payment_intents' => Http::response([
                'id' => 'pi_test_followup',
                'object' => 'payment_intent',
                'status' => 'succeeded',
            ]),
            'api.stripe.com/v1/payment_intents/pi_test_followup*' => Http::response([
                'id' => 'pi_test_followup',
                'object' => 'payment_intent',
                'status' => 'succeeded',
                'payment_method' => [
                    'id' => 'pm_test_card',
                    'card' => ['brand' => 'visa', 'last4' => '1881', 'country' => 'DE'],
                ],
            ]),
        ]);

        $remote = $this->stripe()->chargeAgain('cus_TestBuyer', [
            'amount' => ['currency' => 'EUR', 'value' => '9.00'],
            'description' => 'Zusatz',
            'mandateId' => 'pm_test_card',
        ]);

        $this->assertTrue($remote->isPaid());
        $this->assertSame('1881', $remote->cardLast4);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/v1/payment_intents')
            && $r->data()['payment_method'] === 'pm_test_card'
            && $r->data()['off_session'] === 'true'
            && (int) $r->data()['amount'] === 900);
    }

    #[Test]
    public function leaving_the_mandate_out_leaves_it_out(): void
    {
        Http::fake([
            'api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_x', 'status' => 'processing']),
            'api.stripe.com/v1/payment_intents/pi_x*' => Http::response(['id' => 'pi_x', 'status' => 'processing']),
        ]);

        // A blank value is a caller mistake, not an instruction. Sending it on
        // would have Stripe refuse the charge over an empty payment method.
        $this->stripe()->chargeAgain('cus_TestBuyer', [
            'amount' => ['currency' => 'EUR', 'value' => '9.00'],
            'description' => 'Zusatz',
            'mandateId' => '   ',
        ]);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/v1/payment_intents')
            && ! array_key_exists('payment_method', $r->data()));
    }

    // ---------------------------------------------------------- a refund

    #[Test]
    public function refunds_come_back_as_an_amount_each_with_its_own_reference(): void
    {
        Http::fake(['api.stripe.com/v1/refunds*' => Http::response(['object' => 'list', 'data' => [
            ['id' => 're_test_1', 'object' => 'refund', 'amount' => 500, 'status' => 'succeeded'],
            ['id' => 're_test_2', 'object' => 'refund', 'amount' => 400, 'status' => 'succeeded'],
            // Neither of these left the account, and booking them would show a
            // buyer money back that never moved.
            ['id' => 're_test_3', 'object' => 'refund', 'amount' => 900, 'status' => 'failed'],
            ['id' => 're_test_4', 'object' => 'refund', 'amount' => 900, 'status' => 'canceled'],
        ]])]);

        $this->assertSame(
            [['id' => 're_test_1', 'amount' => 500], ['id' => 're_test_2', 'amount' => 400]],
            $this->stripe()->refundsFor('ch_test_1'),
        );
    }

    #[Test]
    public function it_refuses_to_talk_to_stripe_without_a_key(): void
    {
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/STRIPE_KEY/');

        (new StripeGateway('', 'https://api.stripe.com'))->fetch('cs_test_a1b2c3');
    }
}
