<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\BrandContext\ServiceProvider as BrandContextServiceProvider;
use Goldnead\StatamicPayments\Events;
use Goldnead\StatamicPayments\Events\CheckoutBlocked;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Events\PaymentRefunded;
use Goldnead\StatamicPayments\Events\SubscriptionChanged;
use Goldnead\StatamicPayments\Events\SubscriptionPaused;
use Goldnead\StatamicPayments\Integrations\WebhookManager\PaymentsTrigger;
use Goldnead\StatamicPayments\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\StatamicPayments\Integrations\WebhookManager\WebhookPayload;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Tests\TestCase;
use Goldnead\WebhookManager\Domain\OutboundWebhook\Models\OutboundWebhook;
use Goldnead\WebhookManager\Events\TriggerDetected;
use Goldnead\WebhookManager\Facades\WebhookManager;
use Goldnead\WebhookManager\Jobs\ProcessOutboundDeliveryJob;
use Goldnead\WebhookManager\WebhookManagerServiceProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;

/**
 * The payment moments as triggers of the real webhook manager.
 *
 * The manager is a dev dependency and booted here for real, with its own
 * migrations and its own dispatch pipeline: a stand-in would prove only that
 * the bridge calls what the stand-in offers. The install without it is proved
 * in a separate process, {@see BootWithoutWebhookManagerTest}.
 */
class WebhookManagerBridgeTest extends TestCase
{
    /** The handles of the automations addon for the same moments. */
    private const AUTOMATIONS_HANDLES = [
        'payments.checkout_abandoned', 'payments.checkout_blocked', 'payments.charged_back',
        'payments.failed', 'payments.paid', 'payments.refunded',
        'payments.subscription_attempt_failed', 'payments.subscription_cancelled',
        'payments.subscription_card_expired', 'payments.subscription_card_expiring',
        'payments.subscription_changed', 'payments.subscription_ended',
        'payments.subscription_paused', 'payments.subscription_payment_upcoming',
        'payments.subscription_plan_completed', 'payments.subscription_renewed',
        'payments.subscription_replaced', 'payments.subscription_resumed',
        'payments.subscription_started', 'payments.subscription_start_failed',
    ];

    protected function getPackageProviders($app): array
    {
        return array_merge(
            [BrandContextServiceProvider::class, WebhookManagerServiceProvider::class],
            parent::getPackageProviders($app),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([BrandContextServiceProvider::class, WebhookManagerServiceProvider::class] as $provider) {
            $this->loadMigrationsFrom(dirname((new ReflectionClass($provider))->getFileName(), 2).'/database/migrations');
        }
        $this->artisan('migrate')->run();

        // Testbench has no booted phase in which Statamic runs the addons'
        // bootAddon(), so the manager's registries and its TriggerDetected
        // listener are wired by hand, and the bridge gets the retry that
        // production gives it at the end of the booted queue.
        // Navigation and permissions are left out: the CP facades are mocked in
        // an addon test case, and neither has anything to do with dispatch.
        $manager = $this->app->getProvider(WebhookManagerServiceProvider::class);
        foreach (['bootWebhookConfig', 'bootBindings', 'bootRegistries', 'bootEvents'] as $method) {
            (new ReflectionMethod($manager, $method))->invoke($manager);
        }

        $this->app->make(WebhookManagerBridge::class)->boot($this->app->make('events'));

        WebhookPayload::forgetBrands();
        Carbon::setTestNow('2026-09-24 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function every_moment_is_a_trigger_under_the_automations_handle(): void
    {
        $this->assertTrue($this->app->make(WebhookManagerBridge::class)->booted());

        $registered = array_values(array_filter(
            array_keys(WebhookManager::triggers()->all()),
            fn (string $handle) => str_starts_with($handle, 'payments.'),
        ));
        sort($registered);
        $expected = self::AUTOMATIONS_HANDLES;
        sort($expected);

        $this->assertSame($expected, $registered);

        foreach ($registered as $handle) {
            $trigger = WebhookManager::triggers()->get($handle);
            $this->assertInstanceOf(PaymentsTrigger::class, $trigger);
            $this->assertSame('payments', $trigger->sourceType());
        }
    }

    #[Test]
    public function labels_follow_the_language_of_the_person_looking(): void
    {
        $this->app->setLocale('de');
        $this->assertSame('Zahlungen: Abo pausiert', WebhookManager::triggers()->options()['payments.subscription_paused']);

        $this->app->setLocale('en');
        $this->assertSame('Payments: subscription paused', WebhookManager::triggers()->options()['payments.subscription_paused']);
    }

    #[Test]
    public function a_second_boot_registers_no_second_listener(): void
    {
        $this->app->make(WebhookManagerBridge::class)->boot($this->app->make('events'));

        $heard = [];
        Event::listen(TriggerDetected::class, function (TriggerDetected $detected) use (&$heard) {
            $heard[] = $detected->trigger->triggerHandle;
        });

        PaymentPaid::dispatch($this->payment());

        $this->assertSame(['payments.paid'], $heard);
    }

    #[Test]
    public function a_paid_payment_says_what_a_receiver_needs_and_nothing_it_must_not_have(): void
    {
        Event::fake([TriggerDetected::class]);

        $payment = $this->payment();
        $payment->items()->create([
            'product' => 'noten-paket', 'name' => 'Notenpaket', 'amount_cent' => 1900,
            'quantity' => 1, 'kind' => 'primary', 'discount_cent' => 0,
        ]);

        PaymentPaid::dispatch($payment);

        $detected = $this->detected('payments.paid');
        $body = $detected->trigger->payload;

        $this->assertSame('payments', $detected->trigger->sourceType);
        $this->assertSame((string) $payment->id, $detected->trigger->sourceReference);
        $this->assertSame(['event', 'event_id', 'occurred_at', 'brand', 'subject_type', 'subject_id', 'payment'], array_keys($body));
        $this->assertSame(['payment', $payment->id], [$body['subject_type'], $body['subject_id']]);
        $this->assertSame('payments.paid', $body['event']);
        // The moment's time is when it was paid, not when the listener ran.
        $this->assertSame('2026-09-24T09:58:00+00:00', $body['occurred_at']);
        $this->assertSame('2026-09-24T09:58:00+00:00', $detected->trigger->eventAt?->format(\DATE_ATOM));
        $this->assertSame(['id' => 1, 'handle' => 'default'], $body['brand']);

        $this->assertSame([
            'id', 'provider', 'provider_id', 'status', 'product', 'amount_cent', 'currency',
            'discount_code', 'discount_cent', 'refunded_cent', 'email', 'name', 'country',
            'subscription_id', 'parent_payment_id', 'items', 'attribution', 'created_at',
            'paid_at', 'refunded_at', 'charged_back_at',
        ], array_keys($body['payment']));
        $this->assertSame(1900, $body['payment']['amount_cent']);
        $this->assertSame('EUR', $body['payment']['currency']);
        $this->assertSame('kundin@example.com', $body['payment']['email']);
        $this->assertSame('tr_echt', $body['payment']['provider_id']);
        $this->assertSame('2026-09-24T09:58:00+00:00', $body['payment']['paid_at']);
        $this->assertSame('herbst', $body['payment']['attribution']['utm_campaign']);
        $this->assertSame([[
            'product' => 'noten-paket', 'offer' => null, 'name' => 'Notenpaket', 'kind' => 'primary',
            'quantity' => 1, 'amount_cent' => 1900, 'discount_cent' => 0,
        ]], $body['payment']['items']);

        $json = json_encode($body);
        foreach (['danke-token-geheim', '4242', 'Visa', 'mdt_mandat', 'cst_kunde', 'Ich stimme zu', '?portal=', 'meta'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, "The body carries [{$secret}].");
        }
    }

    #[Test]
    public function a_placeholder_provider_id_is_not_passed_on(): void
    {
        Event::fake([TriggerDetected::class]);

        PaymentPaid::dispatch($this->payment(['provider_id' => Payment::PLACEHOLDER_PROVIDER_PREFIX.'abc']));

        $this->assertNull($this->detected('payments.paid')->trigger->payload['payment']['provider_id']);
    }

    #[Test]
    public function a_blocked_checkout_carries_the_network_never_the_address(): void
    {
        Event::fake([TriggerDetected::class]);

        CheckoutBlocked::dispatch('rate_limit', 'wer@example.com', '203.0.113.77', 'Zu viele Versuche.');
        CheckoutBlocked::dispatch('blocklist', null, '2001:db8:abcd:12::1');

        $bodies = collect(Event::dispatched(TriggerDetected::class))->map(fn ($call) => $call[0]->trigger->payload)->all();

        $this->assertSame(['reason' => 'rate_limit', 'email' => 'wer@example.com', 'ip_prefix' => '203.0.113.0/24'], $bodies[0]['blocked']);
        $this->assertSame('2001:db8:abcd::/48', $bodies[1]['blocked']['ip_prefix']);
        $this->assertStringNotContainsString('203.0.113.77', json_encode($bodies));
        $this->assertStringNotContainsString('12::1', json_encode($bodies));
    }

    #[Test]
    public function a_refund_and_a_change_carry_their_own_amounts(): void
    {
        Event::fake([TriggerDetected::class]);

        PaymentRefunded::dispatch($this->payment(), 500, false);
        $abo = $this->subscription();
        SubscriptionChanged::dispatch($abo, 'basis', 'plus', 1900, 2900, 420, null, true, 'portal');

        $refund = $this->detected('payments.refunded')->trigger->payload;
        $this->assertSame(['amount_cent' => 500, 'currency' => 'EUR', 'full' => false], $refund['refund']);

        $change = $this->detected('payments.subscription_changed')->trigger->payload;
        $this->assertSame('up', $change['change']['direction']);
        $this->assertSame(420, $change['change']['proration_cent']);
        $this->assertNull($change['proration_payment']);
        $this->assertSame((string) $abo->id, $this->detected('payments.subscription_changed')->trigger->sourceReference);
    }

    #[Test]
    public function a_paused_subscription_reaches_an_outbound_hook_and_is_queued_for_delivery(): void
    {
        Queue::fake();

        $this->hook('payments.subscription_paused');
        $abo = $this->subscription();

        SubscriptionPaused::dispatch($abo, Carbon::parse('2026-10-24 00:00:00'), 'portal');

        Queue::assertPushed(ProcessOutboundDeliveryJob::class);
        $delivery = DB::table('webhook_deliveries')->where('trigger_type', 'payments.subscription_paused')->first();
        $this->assertNotNull($delivery);
        $this->assertSame((string) $abo->id, $delivery->trigger_reference);
        $this->assertSame(['subscription', (string) $abo->id], [$delivery->subject_type, $delivery->subject_id]);

        $snapshot = json_decode((string) ($delivery->request_body ?? $delivery->payload_snapshot ?? '{}'), true) ?: [];
        $flat = json_encode($snapshot);
        $this->assertStringContainsString('2026-10-24T00:00:00+00:00', $flat);
        $this->assertStringContainsString('"by":"portal"', $flat);
        $this->assertStringNotContainsString('cst_kunde', $flat);
    }

    #[Test]
    public function a_row_of_another_brand_is_delivered_through_that_brands_hook_without_a_current_brand(): void
    {
        config(['brand-context.multi_brand' => true]);
        Queue::fake();

        $zweite = (int) DB::table('brands')->insertGetId([
            'handle' => 'zweite', 'name' => 'Zweite', 'is_default' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $default = (int) DB::table('brands')->where('is_default', true)->value('id');

        app('brand-context')->runFor($default, fn () => $this->hook('payments.paid', 'default-hook'));
        app('brand-context')->runFor($zweite, fn () => $this->hook('payments.paid', 'zweite-hook'));
        app('brand-context')->forget();

        Event::listen(TriggerDetected::class, function (TriggerDetected $detected) use (&$body) {
            $body = $detected->trigger->payload;
        });

        // A provider webhook: no brand is current.
        PaymentPaid::dispatch($this->payment(['brand_id' => $zweite]));

        $this->assertSame(['id' => $zweite, 'handle' => 'zweite'], $body['brand']);

        $hooks = DB::table('webhook_deliveries')
            ->join('webhook_outbounds', 'webhook_outbounds.id', '=', 'webhook_deliveries.outbound_webhook_id')
            ->pluck('webhook_outbounds.handle')->all();

        $this->assertSame(['zweite-hook'], $hooks);
        $this->assertFalse(app('brand-context')->hasCurrent(), 'The brand of the row leaked into the rest of the request.');
    }

    #[Test]
    public function every_moment_builds_its_documented_body(): void
    {
        $payment = $this->payment();
        $abo = $this->subscription();
        $at = now();

        $events = [
            'paid' => [new PaymentPaid($payment), ['payment']],
            'failed' => [new Events\PaymentFailed($payment), ['payment']],
            'refunded' => [new PaymentRefunded($payment, 100, false), ['payment', 'refund']],
            'charged_back' => [new Events\PaymentChargedBack($payment, 'chb_1', 1900, 'fraud'), ['payment', 'chargeback']],
            'checkout_abandoned' => [new Events\CheckoutAbandoned($payment), ['payment']],
            'checkout_blocked' => [new CheckoutBlocked('captcha', null, null), ['blocked']],
            'subscription_started' => [new Events\SubscriptionStarted($abo, $payment), ['subscription', 'payment']],
            'subscription_start_failed' => [new Events\SubscriptionStartFailed($payment, 'no-mandate'), ['payment', 'reason']],
            'subscription_renewed' => [new Events\SubscriptionRenewed($abo, $payment), ['subscription', 'payment']],
            'subscription_attempt_failed' => [new Events\SubscriptionAttemptFailed($abo, $payment, 2), ['subscription', 'payment', 'attempt']],
            'subscription_payment_upcoming' => [new Events\SubscriptionPaymentUpcoming($abo, $at, 3), ['subscription', 'due_at', 'days_before']],
            'subscription_card_expiring' => [new Events\SubscriptionCardExpiring($abo, $at), ['subscription', 'expires_at']],
            'subscription_card_expired' => [new Events\SubscriptionCardExpired($abo, $at), ['subscription', 'expired_at']],
            'subscription_paused' => [new SubscriptionPaused($abo, null, 'cp'), ['subscription', 'resumes_at', 'by']],
            'subscription_resumed' => [new Events\SubscriptionResumed($abo, 'schedule'), ['subscription', 'by']],
            'subscription_changed' => [new SubscriptionChanged($abo, 'plus', 'basis', 2900, 1900, 0, $payment, false, 'cp'), ['subscription', 'change', 'proration_payment']],
            'subscription_replaced' => [new Events\SubscriptionReplaced($abo, $payment, null, 300, 5), ['subscription', 'purchase', 'replacement', 'credit']],
            'subscription_plan_completed' => [new Events\SubscriptionPlanCompleted($abo, $payment), ['subscription', 'payment']],
            'subscription_cancelled' => [new Events\SubscriptionCancelled($abo), ['subscription']],
            'subscription_ended' => [new Events\SubscriptionEnded($abo), ['subscription']],
        ];

        $this->assertSame(array_keys(WebhookPayload::MOMENTS), array_keys($events));

        foreach ($events as $moment => [$event, $keys]) {
            $this->assertInstanceOf(WebhookPayload::MOMENTS[$moment], $event);
            $body = WebhookPayload::build($moment, $event);

            $this->assertSame(
                array_merge(['event', 'event_id', 'occurred_at', 'brand', 'subject_type', 'subject_id'], $keys),
                array_keys($body),
                "Body of [{$moment}]",
            );
            $this->assertStringNotContainsString('danke-token-geheim', json_encode($body), "Body of [{$moment}]");
            $this->assertStringNotContainsString('cst_kunde', json_encode($body), "Body of [{$moment}]");
        }
    }

    #[Test]
    public function a_row_naming_a_brand_that_cannot_be_set_is_not_delivered_through_the_current_one(): void
    {
        config(['brand-context.multi_brand' => true]);
        Queue::fake();

        $default = (int) DB::table('brands')->where('is_default', true)->value('id');
        app('brand-context')->runFor($default, fn () => $this->hook('payments.paid', 'current-hook'));

        $heard = [];
        Event::listen(TriggerDetected::class, function (TriggerDetected $d) use (&$heard) {
            $heard[] = $d->trigger->triggerHandle;
        });

        // The request runs as the default brand; the row names brand 99,
        // which does not exist.
        app('brand-context')->runFor($default, fn () => PaymentPaid::dispatch($this->payment(['brand_id' => 99])));

        $this->assertSame([], $heard);
        $this->assertSame(0, DB::table('webhook_deliveries')->count());
        Queue::assertNotPushed(ProcessOutboundDeliveryJob::class);
    }

    #[Test]
    public function a_moment_inside_a_transaction_goes_out_after_the_commit_and_never_after_a_rollback(): void
    {
        $heard = [];
        Event::listen(TriggerDetected::class, function (TriggerDetected $d) use (&$heard) {
            $heard[] = $d->trigger->triggerHandle;
        });

        try {
            DB::transaction(function () {
                PaymentPaid::dispatch($this->payment());

                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $heard, 'A rolled back moment reached the manager.');

        DB::transaction(function () use (&$heard) {
            PaymentPaid::dispatch($this->payment(['provider_id' => 'tr_zwei']));

            $this->assertSame([], $heard, 'Handed over while the transaction was still open.');
        });

        $this->assertSame(['payments.paid'], $heard);
    }

    #[Test]
    public function the_same_moment_told_twice_carries_the_same_event_id(): void
    {
        Event::fake([TriggerDetected::class]);

        $payment = $this->payment();
        PaymentPaid::dispatch($payment);
        Carbon::setTestNow('2026-09-24 10:05:00');
        PaymentPaid::dispatch($payment->fresh());
        PaymentRefunded::dispatch($payment->fresh(), 500, false);
        $payment->forceFill(['refunded_cent' => 500, 'refunded_at' => now()])->save();
        PaymentRefunded::dispatch($payment->fresh(), 500, false);
        $payment->forceFill(['refunded_cent' => 1000, 'refunded_at' => now()->addMinute()])->save();
        PaymentRefunded::dispatch($payment->fresh(), 500, false);

        $ids = collect(Event::dispatched(TriggerDetected::class))
            ->map(fn ($call) => [$call[0]->trigger->triggerHandle, $call[0]->trigger->payload['event_id']]);
        $paid = $ids->where(0, 'payments.paid')->pluck(1)->all();
        $refunds = $ids->where(0, 'payments.refunded')->pluck(1)->all();

        $this->assertCount(2, $paid);
        $this->assertSame($paid[0], $paid[1]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $paid[0]);
        $this->assertCount(3, array_unique($refunds), 'Two partial refunds and the moment before must stay apart.');
        $this->assertNotContains($paid[0], $refunds);
    }

    #[Test]
    public function a_failing_receiver_side_never_breaks_the_payment(): void
    {
        Event::listen(TriggerDetected::class, function () {
            throw new \RuntimeException('manager down');
        });

        PaymentPaid::dispatch($this->payment());

        $this->assertTrue(true, 'PaymentPaid did not throw.');
    }

    #[Test]
    public function switched_off_it_offers_nothing(): void
    {
        config(['statamic-payments.webhook_manager.enabled' => false]);

        $this->assertFalse(WebhookManagerBridge::available());
    }

    private function detected(string $handle): TriggerDetected
    {
        $found = collect(Event::dispatched(TriggerDetected::class))
            ->map(fn ($call) => $call[0])
            ->first(fn (TriggerDetected $d) => $d->trigger->triggerHandle === $handle);

        $this->assertNotNull($found, "No TriggerDetected for [{$handle}].");

        return $found;
    }

    private function hook(string $trigger, string $handle = 'hook'): OutboundWebhook
    {
        return OutboundWebhook::create([
            'uuid' => (string) Str::uuid(),
            'name' => $handle,
            'handle' => $handle,
            'enabled' => true,
            'trigger_type' => $trigger,
            'url' => 'https://example.test/hook',
            'method' => 'POST',
            'payload_type' => 'raw_json',
            'queue_enabled' => true,
        ]);
    }

    private function payment(array $werte = []): Payment
    {
        return Payment::create(array_merge([
            'provider' => 'fake', 'provider_id' => 'tr_echt', 'customer_reference' => 'cst_kunde',
            'mandate_id' => 'mdt_mandat', 'product' => 'noten-paket', 'amount_cent' => 1900,
            'currency' => 'EUR', 'status' => Payment::STATUS_PAID, 'email' => 'kundin@example.com',
            'name' => 'Kundin', 'card_last4' => '4242', 'card_label' => 'Visa',
            'consent_at' => now(), 'consent_text' => 'Ich stimme zu',
            'landing_page' => 'https://example.test/?portal=abc', 'utm_campaign' => 'herbst',
            'meta' => ['thanks_token' => 'danke-token-geheim'],
            'paid_at' => now()->subMinutes(2),
        ], $werte));
    }

    private function subscription(array $werte = []): Subscription
    {
        return Subscription::create(array_merge([
            'provider' => 'fake', 'provider_id' => 'sub_1', 'customer_reference' => 'cst_kunde',
            'product' => 'basis', 'amount_cent' => 1900, 'currency' => 'EUR',
            'interval' => '1 month', 'times_charged' => 1, 'status' => Subscription::STATUS_ACTIVE,
            'next_payment_at' => now()->addDays(3), 'email' => 'kundin@example.com', 'name' => 'Kundin',
            'card_expires_at' => now()->addMonth(), 'meta' => ['portal_token' => 'danke-token-geheim'],
        ], $werte));
    }
}
