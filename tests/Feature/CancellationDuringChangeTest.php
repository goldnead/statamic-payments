<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Closure;
use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Legal\Cancellations;
use Goldnead\StatamicPayments\Models\Cancellation;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\ProviderUnavailable;
use Goldnead\StatamicPayments\Support\RemoteSubscription;
use Goldnead\StatamicPayments\Support\SubscriptionPauses;
use Goldnead\StatamicPayments\Support\Subscriptions;
use Goldnead\StatamicPayments\Tests\Support\FakeGateway;
use Goldnead\StatamicPayments\Tests\Support\PausingFakeGateway;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;

class G4HookFake extends FakeGateway
{
    public ?Closure $onCreate = null;

    public ?Closure $onCancel = null;

    public bool $timeoutBeforeCreate = false;

    public function createSubscription(string $customerReference, array $payload): RemoteSubscription
    {
        if ($this->timeoutBeforeCreate) {
            $this->timeoutBeforeCreate = false;
            throw new ProviderUnavailable('connect timeout, never arrived');
        }
        if ($f = $this->onCreate) {
            $this->onCreate = null;
            $f();
        }

        return parent::createSubscription($customerReference, $payload);
    }

    public function cancelSubscription(string $customerReference, string $subscriptionId): RemoteSubscription
    {
        if ($f = $this->onCancel) {
            $this->onCancel = null;
            $f();
        }

        return parent::cancelSubscription($customerReference, $subscriptionId);
    }
}

class G4HookPausingFake extends PausingFakeGateway
{
    public ?Closure $onResume = null;

    public bool $fetch503 = false;

    public function resumeSubscription(string $customerReference, string $subscriptionId): RemoteSubscription
    {
        if ($f = $this->onResume) {
            $this->onResume = null;
            $f();
        }

        return parent::resumeSubscription($customerReference, $subscriptionId);
    }

    public function fetchSubscription(string $customerReference, string $subscriptionId): RemoteSubscription
    {
        if ($this->fetch503) {
            throw new ProviderUnavailable('503');
        }

        return parent::fetchSubscription($customerReference, $subscriptionId);
    }
}

class CancellationDuringChangeTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('statamic-payments.cancellation.notify', 'shop@example.com');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Carbon::setTestNow('2026-09-23 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function use(FakeGateway $g): void
    {
        $this->gateway = $g;
        $this->app->instance(PaymentGateway::class, $g);
    }

    protected function abo(array $werte = []): Subscription
    {
        $this->gateway->subscriptions['sub_1'] = ['customer' => 'cst_1', 'status' => 'active', 'meta' => []];
        $this->gateway->mandates[] = 'cst_1';

        return Subscription::create(array_merge([
            'provider' => 'fake', 'provider_id' => 'sub_1', 'customer_reference' => 'cst_1',
            'product' => 'basis', 'amount_cent' => 1900, 'currency' => 'EUR',
            'interval' => '1 month', 'times_charged' => 1, 'status' => Subscription::STATUS_ACTIVE,
            'next_payment_at' => Carbon::parse('2026-10-05'),
            'email' => 'anna@example.de',
        ], $werte));
    }

    protected function kuendigeGesetzlich(string $ident): Cancellation
    {
        $c = app(Cancellations::class)->declare([
            'name' => 'Anna', 'email' => 'anna@example.de', 'identification' => $ident, 'kind' => 'ordinary',
        ], '127.0.0.1');

        return app(Cancellations::class)->confirm($c);
    }

    #[Test]
    public function g4_312k_waehrend_fortsetzen_mollie_geht_nicht_verloren(): void
    {
        $g = new G4HookFake;
        $this->use($g);
        $g->subscriptionsCreated = 1;
        $abo = $this->abo();
        $this->assertTrue(app(SubscriptionPauses::class)->pause($abo));

        $cancellation = null;
        $g->onCreate = function () use (&$cancellation) {
            // Row is `resuming` right now; the buyer presses the statutory button.
            $cancellation = $this->kuendigeGesetzlich('sub_1');
        };

        $this->assertTrue(app(SubscriptionPauses::class)->resume($abo->fresh()));

        Carbon::setTestNow(Carbon::now()->addMinutes(30));
        $this->artisan('payments:resume-paused');

        $this->assertSame(Subscription::STATUS_CANCELLED, $abo->fresh()->status, 'die § 312k-Kündigung ist verloren, das Abo bucht weiter');
        $this->assertNotNull($cancellation->fresh()->provider_cancelled_at);
    }

    #[Test]
    public function g4_312k_waehrend_fortsetzen_stripe_geht_nicht_verloren(): void
    {
        $g = new G4HookPausingFake;
        $this->use($g);
        $abo = $this->abo();
        $this->assertTrue(app(SubscriptionPauses::class)->pause($abo));

        $cancellation = null;
        $g->onResume = function () use (&$cancellation) {
            $cancellation = $this->kuendigeGesetzlich('sub_1');
        };

        $this->assertTrue(app(SubscriptionPauses::class)->resume($abo->fresh()));
        Carbon::setTestNow(Carbon::now()->addMinutes(30));
        $this->artisan('payments:resume-paused');

        $this->assertSame(Subscription::STATUS_CANCELLED, $abo->fresh()->status, 'die § 312k-Kündigung ist verloren (Stripe)');
    }

    #[Test]
    public function g4_312k_waehrend_pausieren_mollie(): void
    {
        $g = new G4HookFake;
        $this->use($g);
        $abo = $this->abo();
        $cancellation = null;
        $g->onCancel = function () use (&$cancellation) {
            $cancellation = $this->kuendigeGesetzlich('sub_1');
        };

        $this->assertTrue(app(SubscriptionPauses::class)->pause($abo));
        Carbon::setTestNow(Carbon::now()->addMinutes(30));
        $this->artisan('payments:resume-paused');

        $this->assertSame(Subscription::STATUS_CANCELLED, $abo->fresh()->status);
        $this->assertNotNull($cancellation->fresh()->provider_cancelled_at);
    }

    #[Test]
    public function g4_312k_auf_nie_aufgeloestem_wechsel(): void
    {
        $g = new G4HookFake;
        $this->use($g);
        $abo = $this->abo();
        // A switch claimed 2 min ago; its process then dies and never comes back.
        Subscription::query()->whereKey($abo->getKey())->update(['status' => Subscription::STATUS_SWITCHING, 'updated_at' => Carbon::now()->subMinutes(2)]);

        $c = $this->kuendigeGesetzlich('sub_1');
        $this->assertNotNull(data_get($abo->fresh()->meta, 'cancel_requested'));

        Carbon::setTestNow(Carbon::now()->addMinutes(9));
        $this->artisan('payments:resume-paused');

        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $this->artisan('payments:resume-paused');

        $this->assertSame(Subscription::STATUS_CANCELLED, $abo->fresh()->status);
        $this->assertNotNull($c->fresh()->provider_cancelled_at);
        $this->assertContains('sub_1', $g->cancelled);
    }

    #[Test]
    public function g4_timeout_vor_ankunft_dann_aufraeumlauf_legt_genau_eine_an(): void
    {
        $g = new G4HookFake;
        $this->use($g);
        $g->subscriptionsCreated = 1;
        $abo = $this->abo();
        app(SubscriptionPauses::class)->pause($abo);
        $g->timeoutBeforeCreate = true;

        $this->assertFalse(app(SubscriptionPauses::class)->resume($abo->fresh()));
        $this->assertSame(Subscription::STATUS_RESUMING, $abo->fresh()->status);

        Carbon::setTestNow(Carbon::now()->addMinutes(20));
        $this->artisan('payments:resume-paused');
        $this->artisan('payments:resume-paused');

        $this->assertSame(Subscription::STATUS_ACTIVE, $abo->fresh()->status);
        $this->assertSame(2, $g->subscriptionsCreated);
        $live = array_filter($g->subscriptions, fn ($s) => ($s['status'] ?? null) === 'active');
        $this->assertCount(1, $live, 'zwei laufende Vereinbarungen');
    }

    #[Test]
    public function g4_timeout_mit_anlage_dann_klick_nach_aufraeumen_und_erneut_pausieren(): void
    {
        // Timeout on create (agreement exists), sweep adopts, then pause+resume again: still one live.
        $g = new G4HookFake;
        $this->use($g);
        $g->subscriptionsCreated = 1;
        $abo = $this->abo();
        app(SubscriptionPauses::class)->pause($abo);
        $g->loseTheAnswer = true;
        app(SubscriptionPauses::class)->resume($abo->fresh());
        Carbon::setTestNow(Carbon::now()->addMinutes(20));
        $this->artisan('payments:resume-paused');
        $this->assertSame('sub_2', $abo->fresh()->provider_id);

        $this->assertTrue(app(SubscriptionPauses::class)->pause($abo->fresh()));
        $this->assertTrue(app(SubscriptionPauses::class)->resume($abo->fresh()));
        $live = array_keys(array_filter($g->subscriptions, fn ($s) => ($s['status'] ?? null) === 'active'));
        $this->assertCount(1, $live, 'zwei laufende: '.implode(',', $live));
    }

    #[Test]
    public function g4_kuendigen_pausiert_mit_waise_und_stuck_resuming(): void
    {
        $g = new G4HookFake;
        $this->use($g);
        $g->subscriptionsCreated = 1;
        $abo = $this->abo();
        app(SubscriptionPauses::class)->pause($abo);
        $g->loseTheAnswer = true;
        app(SubscriptionPauses::class)->resume($abo->fresh());
        // Now: row resuming (fresh claim), sub_2 live orphan. Portal cancel -> busy. Wait 20 min: stuck -> cancel.
        $this->assertFalse(app(Subscriptions::class)->cancel($abo->fresh()));
        Carbon::setTestNow(Carbon::now()->addMinutes(20));
        $this->assertTrue(app(Subscriptions::class)->cancel($abo->fresh()));
        $this->assertContains('sub_2', $g->cancelled);
        $this->assertSame(Subscription::STATUS_CANCELLED, $abo->fresh()->status);
    }

    #[Test]
    public function g4_aufraeumlauf_503_bei_haengender_pause_nativ(): void
    {
        $g = new G4HookPausingFake;
        $this->use($g);
        $abo = $this->abo();
        Subscription::query()->whereKey($abo->getKey())->update(['status' => Subscription::STATUS_PAUSING, 'updated_at' => Carbon::now()->subMinutes(20)]);
        $g->fetch503 = true;
        $this->artisan('payments:resume-paused');
        $this->assertSame(Subscription::STATUS_PAUSING, $abo->fresh()->status);

        // Stuck native resume and 503
        Subscription::query()->whereKey($abo->getKey())->update(['status' => Subscription::STATUS_RESUMING, 'meta' => json_encode(['pause' => ['mode' => 'native']]), 'updated_at' => Carbon::now()->subMinutes(20)]);
        $this->artisan('payments:resume-paused');
        $this->assertSame(Subscription::STATUS_RESUMING, $abo->fresh()->status);
    }

    #[Test]
    public function g4_aufraeumlauf_503_bei_haengender_kuendigung(): void
    {
        $g = new G4HookFake;
        $this->use($g);
        $abo = $this->abo();
        Subscription::query()->whereKey($abo->getKey())->update([
            'status' => Subscription::STATUS_CANCELLING, 'updated_at' => Carbon::now()->subMinutes(20),
            'meta' => json_encode(['cancelling_from' => 'active']),
        ]);
        $g->refuseToCancel = true;
        $this->artisan('payments:resume-paused');

        $fresh = $abo->fresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $fresh->status);
        $this->assertArrayNotHasKey('cancelling_from', $fresh->meta ?? [], 'a stale cancelling_from outlives the claim it belonged to');
    }

    #[Test]
    public function g4_noting_a_cancellation_does_not_reset_the_stuck_clock(): void
    {
        $this->use(new G4HookFake);
        $abo = $this->abo();
        Subscription::query()->whereKey($abo->getKey())->update(['status' => Subscription::STATUS_SWITCHING, 'updated_at' => Carbon::now()->subMinutes(8)]);

        app(Subscriptions::class)->requestCancellation($abo->fresh(), null);

        $this->assertSame(
            Carbon::now()->subMinutes(8)->toDateTimeString(),
            $abo->fresh()->updated_at->toDateTimeString(),
        );
        $this->assertIsArray($abo->fresh()->meta['cancel_requested'] ?? null);
    }
}
