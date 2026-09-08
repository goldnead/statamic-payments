<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Events\PaymentFailed;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

/**
 * Stripe's endpoint, and the two things it must never do.
 *
 * Every test here tries to get something for free, or to make the same thing
 * happen twice. The last one crosses the line the whole gateway-resolution
 * ticket is about: a Stripe delivery naming a Mollie payment.
 */
class StripeWebhookTest extends TestCase
{
    protected string $secret = 'whsec_kJ8vN2mQ4pR7sT1uV3wX5yZ6aB8cD0eF';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.stripe.key', 'sk_test_notARealKey');
        $app['config']->set('statamic-payments.stripe.webhook_secret', $this->secret);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // A URL nobody stubbed must fail the test rather than quietly reaching
        // the real Stripe.
        Http::preventStrayRequests();
    }

    /** A Stripe event body, as raw bytes — the signature covers exactly these. */
    protected function body(string $eventId, string $type, array $object): string
    {
        return json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ], JSON_UNESCAPED_SLASHES);
    }

    protected function deliver(string $body, ?string $header = null): TestResponse
    {
        return $this->call(
            'POST',
            '/!/statamic-payments/webhook/stripe',
            [],
            [],
            [],
            array_filter([
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $header ?? $this->sign($body),
            ]),
            $body,
        );
    }

    protected function sign(string $body, ?string $secret = null, ?int $timestamp = null): string
    {
        $timestamp ??= Carbon::now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? $this->secret);

        return "t={$timestamp},v1={$signature}";
    }

    /** A Stripe payment that this site created and is waiting on. */
    protected function stripePayment(string $sessionId = 'cs_test_a1b2c3'): Payment
    {
        return Payment::create([
            'provider' => 'stripe',
            'provider_id' => $sessionId,
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_OPEN,
            'email' => 'kaeufer@example.com',
        ]);
    }

    protected function fakePaidSession(string $sessionId = 'cs_test_a1b2c3'): void
    {
        Http::fake(['api.stripe.com/*' => Http::response([
            'id' => $sessionId,
            'object' => 'checkout.session',
            'status' => 'complete',
            'payment_status' => 'paid',
            'customer_details' => ['email' => 'kaeufer@example.com', 'address' => ['country' => 'DE']],
            'payment_intent' => [
                'id' => 'pi_test_1',
                'payment_method' => ['id' => 'pm_test_card', 'card' => ['brand' => 'visa', 'last4' => '4242']],
            ],
        ])]);
    }

    // ------------------------------------------------------------- signature

    #[Test]
    public function a_signed_delivery_is_acted_on(): void
    {
        $payment = $this->stripePayment();
        $this->fakePaidSession();

        $body = $this->body('evt_1', 'checkout.session.completed', ['id' => 'cs_test_a1b2c3']);

        $this->deliver($body)->assertOk()->assertExactJson(['received' => true]);

        $this->assertTrue($payment->fresh()->isPaid());
        $this->assertNotNull($payment->fresh()->fulfilled_at);
    }

    #[Test]
    public function a_forged_signature_changes_nothing(): void
    {
        $payment = $this->stripePayment();
        $this->fakePaidSession();
        Event::fake([PaymentPaid::class]);

        $body = $this->body('evt_forged', 'checkout.session.completed', ['id' => 'cs_test_a1b2c3']);

        $this->deliver($body, $this->sign($body, 'whsec_the_forgers_own_secret'))->assertStatus(400);

        // Not merely "not fulfilled": nothing was asked of Stripe either. A
        // body this site cannot verify is not read at all.
        $this->assertFalse($payment->fresh()->isPaid());
        $this->assertNull($payment->fresh()->fulfilled_at);
        Event::assertNotDispatched(PaymentPaid::class);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_body_rewritten_after_signing_changes_nothing(): void
    {
        $payment = $this->stripePayment();
        $this->fakePaidSession();

        $body = $this->body('evt_2', 'checkout.session.completed', ['id' => 'cs_test_a1b2c3']);
        $header = $this->sign($body);

        // The genuine event, one field swapped for another site's session.
        $tampered = str_replace('cs_test_a1b2c3', 'cs_test_somebody_else', $body);

        $this->deliver($tampered, $header)->assertStatus(400);

        $this->assertFalse($payment->fresh()->isPaid());
    }

    #[Test]
    public function a_delivery_with_no_signature_at_all_is_refused(): void
    {
        $this->stripePayment();
        $this->fakePaidSession();

        $body = $this->body('evt_3', 'checkout.session.completed', ['id' => 'cs_test_a1b2c3']);

        $this->deliver($body, '')->assertStatus(400);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_site_without_a_signing_secret_refuses_everything(): void
    {
        // The other way round — accepting everything because nothing is
        // configured — would be a webhook anybody on the internet can post to.
        config(['statamic-payments.stripe.webhook_secret' => '']);

        $payment = $this->stripePayment();
        $this->fakePaidSession();

        $body = $this->body('evt_4', 'checkout.session.completed', ['id' => 'cs_test_a1b2c3']);

        $this->deliver($body)->assertStatus(400);
        $this->assertFalse($payment->fresh()->isPaid());
    }

    // ------------------------------------------------------------ redelivery

    #[Test]
    public function the_same_event_twice_has_one_effect(): void
    {
        $payment = $this->stripePayment();
        $this->fakePaidSession();
        Event::fake([PaymentPaid::class]);

        $body = $this->body('evt_replayed', 'checkout.session.completed', ['id' => 'cs_test_a1b2c3']);
        $header = $this->sign($body);

        $this->deliver($body, $header)->assertOk();
        $this->deliver($body, $header)->assertOk();

        Event::assertDispatchedTimes(PaymentPaid::class, 1);

        $this->assertSame(1, DB::table('payment_webhook_events')
            ->where('provider', 'stripe')->where('event_id', 'evt_replayed')->count());
    }

    #[Test]
    public function a_redelivery_looks_exactly_like_a_first_delivery_from_outside(): void
    {
        $this->stripePayment();
        $this->fakePaidSession();

        $body = $this->body('evt_quiet', 'checkout.session.completed', ['id' => 'cs_test_a1b2c3']);
        $header = $this->sign($body);

        $first = $this->deliver($body, $header);
        $second = $this->deliver($body, $header);

        // Otherwise the endpoint answers, for anybody who asks, which events it
        // has already seen.
        $this->assertSame($first->getStatusCode(), $second->getStatusCode());
        $this->assertSame($first->getContent(), $second->getContent());
    }

    #[Test]
    public function two_different_events_about_the_same_payment_both_get_through(): void
    {
        $this->stripePayment();
        $this->fakePaidSession();

        $one = $this->body('evt_a', 'checkout.session.completed', ['id' => 'cs_test_a1b2c3']);
        $two = $this->body('evt_b', 'checkout.session.completed', ['id' => 'cs_test_a1b2c3']);

        $this->deliver($one)->assertOk();
        $this->deliver($two)->assertOk();

        $this->assertSame(2, DB::table('payment_webhook_events')->count());
    }

    #[Test]
    public function a_delivery_whose_work_throws_is_left_for_stripe_to_retry(): void
    {
        $this->stripePayment();
        $this->fakePaidSession();

        // A listener that throws is the case the claim must be released for:
        // holding it would be "at most once", whose failure mode is a buyer who
        // paid and got nothing, for ever, in silence.
        Event::listen(PaymentPaid::class, function () {
            throw new \RuntimeException('a listener fell over');
        });

        $body = $this->body('evt_throws', 'checkout.session.completed', ['id' => 'cs_test_a1b2c3']);

        $this->withoutExceptionHandling();

        try {
            $this->deliver($body);
            $this->fail('the exception should have reached the error handler');
        } catch (\RuntimeException $e) {
            $this->assertSame('a listener fell over', $e->getMessage());
        }

        $this->assertSame(0, DB::table('payment_webhook_events')
            ->where('event_id', 'evt_throws')->count(), 'the claim must be released for the retry');
    }

    // ------------------------------------------------- the provider is down

    #[Test]
    public function a_delivery_stripe_could_not_answer_is_given_back_and_not_swallowed(): void
    {
        // The failure mode this endpoint can have that Mollie's cannot: the
        // event id is claimed before the work runs, so a delivery answered 200
        // during an outage never comes back — not on a retry, not from the
        // Resend button, which sends the same id into the same claim.
        $payment = $this->stripePayment();

        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'Bad gateway']], 502)]);

        $body = $this->body('evt_outage', 'checkout.session.completed', ['id' => 'cs_test_a1b2c3']);

        $this->deliver($body)->assertStatus(503);

        $this->assertFalse($payment->fresh()->isPaid());
        $this->assertSame(0, DB::table('payment_webhook_events')
            ->where('event_id', 'evt_outage')->count(), 'the claim must be released for the redelivery');
    }

    #[Test]
    public function the_redelivery_after_an_outage_fulfils(): void
    {
        $payment = $this->stripePayment();

        Http::fake(['api.stripe.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Service unavailable']], 503)
            ->push([
                'id' => 'cs_test_a1b2c3',
                'object' => 'checkout.session',
                'status' => 'complete',
                'payment_status' => 'paid',
                'customer_details' => ['email' => 'kaeufer@example.com'],
            ]),
        ]);

        $body = $this->body('evt_retried', 'checkout.session.completed', ['id' => 'cs_test_a1b2c3']);
        $header = $this->sign($body);

        $this->deliver($body, $header)->assertStatus(503);
        $this->assertFalse($payment->fresh()->isPaid());

        // The same event id a second time. Without the release above this would
        // be treated as a redelivery of something already done.
        $this->deliver($body, $header)->assertOk();

        $this->assertTrue($payment->fresh()->isPaid());
        $this->assertNotNull($payment->fresh()->fulfilled_at);
    }

    #[Test]
    public function a_payment_stripe_says_it_never_issued_is_still_a_quiet_two_hundred(): void
    {
        // A 404 is an answer, not an outage. Retrying it would have Stripe
        // redeliver a stray or forged call for days.
        $this->stripePayment();

        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'No such session']], 404)]);

        $this->deliver($this->body('evt_404', 'checkout.session.completed', ['id' => 'cs_test_unknown']))
            ->assertOk();

        $this->assertSame(1, DB::table('payment_webhook_events')
            ->where('event_id', 'evt_404')->count(), 'an answered delivery keeps its claim');
    }

    // ------------------------------------------------ a delayed payment

    #[Test]
    public function a_sepa_debit_that_was_refused_marks_the_order_failed(): void
    {
        // The whole path, not just the mapping: Stripe announces
        // `checkout.session.async_payment_failed`, the endpoint asks Stripe what
        // actually happened, and the row ends up failed with the event that
        // downstream listeners react to.
        $payment = $this->stripePayment();

        Event::fake([PaymentFailed::class]);

        Http::fake(['api.stripe.com/*' => Http::response([
            'id' => 'cs_test_a1b2c3',
            'object' => 'checkout.session',
            // Days after the buyer left the page, and still not paid.
            'status' => 'complete',
            'payment_status' => 'unpaid',
            'customer_details' => ['email' => 'kaeufer@example.com'],
            'payment_intent' => [
                'id' => 'pi_test_sepa',
                'status' => 'requires_payment_method',
                'last_payment_error' => [
                    'type' => 'invalid_request_error',
                    'code' => 'debit_not_authorized',
                    'message' => 'The customer has not authorized this debit.',
                ],
            ],
        ])]);

        $this->deliver($this->body('evt_sepa_failed', 'checkout.session.async_payment_failed', [
            'id' => 'cs_test_a1b2c3',
        ]))->assertOk();

        $payment->refresh();

        $this->assertSame(Payment::STATUS_FAILED, $payment->status);
        $this->assertNull($payment->fulfilled_at);
        $this->assertNotNull($payment->failed_notified_at);
        Event::assertDispatched(PaymentFailed::class);
    }

    #[Test]
    public function an_ordinary_open_checkout_is_not_failed_by_the_same_path(): void
    {
        // The buyer opened the page and has not paid yet. Reading that as a
        // failure would cancel every checkout somebody merely looked at.
        $payment = $this->stripePayment();

        Event::fake([PaymentFailed::class]);

        Http::fake(['api.stripe.com/*' => Http::response([
            'id' => 'cs_test_a1b2c3',
            'object' => 'checkout.session',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'payment_intent' => ['id' => 'pi_open', 'status' => 'requires_payment_method'],
        ])]);

        $this->deliver($this->body('evt_still_open', 'checkout.session.completed', ['id' => 'cs_test_a1b2c3']))
            ->assertOk();

        $payment->refresh();

        $this->assertSame(Payment::STATUS_OPEN, $payment->status);
        $this->assertNull($payment->failed_notified_at);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    // --------------------------------------------- the twin of a purchase

    #[Test]
    public function an_ordinary_purchase_raises_no_alarm_about_an_unknown_payment(): void
    {
        // Stripe fires `payment_intent.succeeded` alongside
        // `checkout.session.completed` for the same purchase. The row carries
        // the session id, so the intent matches nothing — and the package's
        // only alarm for a buyer who paid into thin air used to fire on every
        // single sale. An alarm that always rings is an alarm nobody reads.
        $payment = $this->stripePayment();
        $this->fakePaidSession();

        Log::spy();

        $this->deliver($this->body('evt_cs', 'checkout.session.completed', ['id' => 'cs_test_a1b2c3']))->assertOk();
        $this->deliver($this->body('evt_pi', 'payment_intent.succeeded', ['id' => 'pi_test_1']))->assertOk();

        $this->assertTrue($payment->fresh()->isPaid());

        Log::shouldNotHaveReceived('warning', [
            \Mockery::on(fn ($message) => is_string($message) && str_contains($message, 'unknown payment id')),
            \Mockery::any(),
        ]);
    }

    #[Test]
    public function a_follow_up_charge_still_gets_its_payment_intent_event(): void
    {
        // The case where a `pi_` really is the row's own id: `FollowUp::accept()`
        // stores what `chargeAgain()` returned. Ignoring every intent event
        // would leave those orders unfulfilled.
        $followUp = Payment::create([
            'provider' => 'stripe',
            'provider_id' => 'pi_test_followup',
            'product' => 'noten-paket',
            'amount_cent' => 900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_OPEN,
            'email' => 'kaeufer@example.com',
        ]);

        Http::fake(['api.stripe.com/*' => Http::response([
            'id' => 'pi_test_followup',
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'receipt_email' => 'kaeufer@example.com',
        ])]);

        $this->deliver($this->body('evt_pi_followup', 'payment_intent.succeeded', ['id' => 'pi_test_followup']))
            ->assertOk();

        $this->assertTrue($followUp->fresh()->isPaid());
    }

    // ---------------------------------------------------------- a refund

    #[Test]
    public function a_refund_is_recorded_as_an_amount_with_a_time(): void
    {
        $payment = $this->stripePayment();
        $payment->forceFill(['status' => Payment::STATUS_PAID, 'paid_at' => now(), 'fulfilled_at' => now()])->save();

        Http::fake([
            // How the charge finds its way back to the row: a refund names a
            // charge, a charge names an intent, and the row was stamped with
            // the session.
            'api.stripe.com/v1/checkout/sessions?*' => Http::response([
                'object' => 'list',
                'data' => [['id' => 'cs_test_a1b2c3', 'object' => 'checkout.session']],
            ]),
            'api.stripe.com/v1/refunds*' => Http::response(['object' => 'list', 'data' => [
                ['id' => 're_test_1', 'amount' => 500, 'status' => 'succeeded'],
            ]]),
        ]);

        $body = $this->body('evt_refund', 'charge.refunded', [
            'id' => 'ch_test_1',
            'object' => 'charge',
            'payment_intent' => 'pi_test_1',
            // The running total, deliberately different from the movement.
            // Booking this number instead of the refund's own amount is how a
            // second partial refund counts the first one again.
            'amount_refunded' => 500,
        ]);

        $this->deliver($body)->assertOk();

        $payment->refresh();

        $this->assertSame(500, $payment->refunded_cent);
        $this->assertNotNull($payment->refunded_at);
        // Still paid, and rightly: an order half repaid is still an order whose
        // money moved and whose thing was delivered.
        $this->assertTrue($payment->isPaid());
        $this->assertSame(['re_test_1'], $payment->meta['refunds']);
    }

    #[Test]
    public function a_second_partial_refund_adds_its_own_amount_and_not_the_running_total(): void
    {
        $payment = $this->stripePayment();
        $payment->forceFill(['status' => Payment::STATUS_PAID, 'paid_at' => now(), 'fulfilled_at' => now()])->save();

        Http::fake([
            'api.stripe.com/v1/checkout/sessions?*' => Http::response([
                'object' => 'list',
                'data' => [['id' => 'cs_test_a1b2c3']],
            ]),
            'api.stripe.com/v1/refunds*' => Http::sequence()
                ->push(['object' => 'list', 'data' => [
                    ['id' => 're_test_1', 'amount' => 500, 'status' => 'succeeded'],
                ]])
                ->push(['object' => 'list', 'data' => [
                    ['id' => 're_test_1', 'amount' => 500, 'status' => 'succeeded'],
                    ['id' => 're_test_2', 'amount' => 400, 'status' => 'succeeded'],
                ]]),
        ]);

        $this->deliver($this->body('evt_r1', 'charge.refunded', [
            'id' => 'ch_test_1', 'payment_intent' => 'pi_test_1', 'amount_refunded' => 500,
        ]))->assertOk();

        $this->deliver($this->body('evt_r2', 'charge.refunded', [
            'id' => 'ch_test_1', 'payment_intent' => 'pi_test_1', 'amount_refunded' => 900,
        ]))->assertOk();

        // 900, not 1400. The first refund is in the second list too, and
        // `Refunds::record()` recognises it by its own reference.
        $this->assertSame(900, $payment->fresh()->refunded_cent);
        $this->assertSame(['re_test_1', 're_test_2'], $payment->fresh()->meta['refunds']);
    }

    // ------------------------------------------------------- an abo cycle

    #[Test]
    public function a_cycle_stripe_charged_on_its_own_becomes_a_payment_against_the_agreement(): void
    {
        $subscription = Subscription::create([
            'provider' => 'stripe',
            'provider_id' => 'sub_test_1',
            'customer_reference' => 'cus_TestBuyer',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'interval' => '1 month',
            'times' => 2,
            'times_charged' => 0,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'email' => 'kaeufer@example.com',
        ]);

        Http::fake([
            'api.stripe.com/v1/invoices/in_test_1*' => Http::response([
                'id' => 'in_test_1',
                'object' => 'invoice',
                'status' => 'paid',
                'subscription' => 'sub_test_1',
                'customer_email' => 'kaeufer@example.com',
            ]),
            'api.stripe.com/v1/subscriptions/sub_test_1*' => Http::response([
                'id' => 'sub_test_1',
                'object' => 'subscription',
                'status' => 'active',
                'customer' => 'cus_TestBuyer',
                'current_period_end' => Carbon::now()->addMonth()->getTimestamp(),
            ]),
        ]);

        $this->deliver($this->body('evt_cycle', 'invoice.paid', ['id' => 'in_test_1', 'object' => 'invoice']))
            ->assertOk();

        $cycle = Payment::query()->where('provider_id', 'in_test_1')->first();

        $this->assertNotNull($cycle, 'the cycle must leave a row, or the buyer pays into a system with no record of it');
        $this->assertSame('stripe', $cycle->provider);
        $this->assertTrue($cycle->isPaid());
        $this->assertSame(1900, $cycle->amount_cent);

        $subscription->refresh();

        $this->assertSame(1, $subscription->times_charged);
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
    }

    // --------------------------------------------------------- the boundary

    #[Test]
    public function a_stripe_delivery_naming_a_mollie_payment_never_reaches_the_mollie_path(): void
    {
        // The failure this whole change exists to prevent, from the other side:
        // one endpoint, one global gateway, and a delivery from the second
        // provider looked up among the first provider's rows.
        $mollie = Payment::create([
            'provider' => 'mollie',
            'provider_id' => 'tr_WDqYK6vllg',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_OPEN,
            'email' => 'kaeufer@example.com',
        ]);

        Event::fake([PaymentPaid::class]);

        // Stripe would not know this id, so the adapter refuses it before any
        // request goes out — and the Mollie row is never a candidate anyway,
        // because the lookup is scoped to `provider = 'stripe'`.
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'No such session']], 404)]);

        $body = $this->body('evt_crossed', 'checkout.session.completed', ['id' => 'tr_WDqYK6vllg']);

        $this->deliver($body)->assertOk();

        $mollie->refresh();

        $this->assertSame(Payment::STATUS_OPEN, $mollie->status);
        $this->assertNull($mollie->fulfilled_at);
        $this->assertNull($mollie->paid_at);
        Event::assertNotDispatched(PaymentPaid::class);

        // And the fake gateway the suite binds — the one standing in for Mollie
        // — was never asked anything at all.
        $this->assertSame([], $this->gateway->fetched);
    }

    #[Test]
    public function a_mollie_delivery_naming_a_stripe_payment_never_reaches_the_stripe_path(): void
    {
        // The same boundary from the other direction, against the endpoint that
        // was there before.
        $stripe = $this->stripePayment();

        $this->postJson('/!/statamic-payments/webhook', ['id' => 'cs_test_a1b2c3'])->assertOk();

        $stripe->refresh();

        $this->assertSame(Payment::STATUS_OPEN, $stripe->status);
        $this->assertNull($stripe->fulfilled_at);
    }

    #[Test]
    public function resolution_webhook_and_multi_brand_crossed_at_once(): void
    {
        // All three axes in one case, which is where the failures sat in
        // webhook-manager v2.1.0: two providers, two brands, one delivery.
        // A Stripe payment on brand A and a Mollie payment on brand B, both
        // waiting, and a Stripe event that names the Mollie one.
        $stripe = $this->stripePayment();
        $stripe->forceFill(['brand_id' => 1])->save();

        $mollie = Payment::create([
            'provider' => 'mollie',
            'provider_id' => 'tr_WDqYK6vllg',
            'brand_id' => 2,
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_OPEN,
            'email' => 'anderer@example.com',
        ]);

        $this->fakePaidSession();

        // The delivery is genuinely Stripe's, correctly signed, and names the
        // other brand's Mollie order.
        $this->deliver($this->body('evt_crossed_brands', 'checkout.session.completed', [
            'id' => 'tr_WDqYK6vllg',
        ]))->assertOk();

        $mollie->refresh();
        $stripe->refresh();

        // Nothing about the Mollie order moved, and its brand is untouched.
        $this->assertSame(Payment::STATUS_OPEN, $mollie->status);
        $this->assertNull($mollie->fulfilled_at);
        $this->assertSame(2, $mollie->brand_id);

        // And the Stripe order the event did not name was not fulfilled either
        // — a delivery must not settle a different row just because the
        // provider matches.
        $this->assertSame(Payment::STATUS_OPEN, $stripe->status);
        $this->assertNull($stripe->fulfilled_at);
        $this->assertSame(1, $stripe->brand_id);
    }

    #[Test]
    public function a_cycle_stays_on_the_brand_that_sold_the_agreement(): void
    {
        // The agreement belongs to brand 2. The cycle is created inside a
        // webhook, where no brand is set — inherited, not stamped from the
        // request, or the buyer's own order area would not show it.
        Subscription::create([
            'provider' => 'stripe',
            'provider_id' => 'sub_brand_2',
            'brand_id' => 2,
            'customer_reference' => 'cus_TestBuyer',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'interval' => '1 month',
            'times' => 2,
            'times_charged' => 0,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'email' => 'kaeufer@example.com',
        ]);

        Http::fake([
            'api.stripe.com/v1/invoices/in_brand_2*' => Http::response([
                'id' => 'in_brand_2',
                'object' => 'invoice',
                'status' => 'paid',
                'subscription' => 'sub_brand_2',
                'customer_email' => 'kaeufer@example.com',
            ]),
            'api.stripe.com/v1/subscriptions/sub_brand_2*' => Http::response([
                'id' => 'sub_brand_2',
                'object' => 'subscription',
                'status' => 'active',
                'customer' => 'cus_TestBuyer',
                'current_period_end' => Carbon::now()->addMonth()->getTimestamp(),
            ]),
        ]);

        $this->deliver($this->body('evt_brand_cycle', 'invoice.paid', ['id' => 'in_brand_2']))->assertOk();

        $cycle = Payment::query()->where('provider_id', 'in_brand_2')->first();

        $this->assertNotNull($cycle);
        $this->assertSame(2, $cycle->brand_id);
        $this->assertSame('stripe', $cycle->provider);
    }

    // ------------------------------------------------------------- the shape

    #[Test]
    public function an_event_without_an_id_is_refused(): void
    {
        $body = json_encode(['object' => 'event', 'type' => 'checkout.session.completed', 'data' => ['object' => []]]);

        $this->deliver($body)->assertStatus(422);
    }

    #[Test]
    public function a_body_that_is_not_json_is_refused(): void
    {
        $this->deliver('not json at all')->assertStatus(400);
    }

    #[Test]
    public function an_event_type_this_package_does_not_act_on_is_answered_and_ignored(): void
    {
        $this->stripePayment();
        Http::fake();

        // Stripe sends a great deal that is none of this package's business.
        // A non-2xx would have it retry something nobody was ever going to act
        // on, for days.
        $body = $this->body('evt_noise', 'customer.updated', ['id' => 'cus_TestBuyer']);

        $this->deliver($body)->assertOk();
        Http::assertNothingSent();
    }
}
