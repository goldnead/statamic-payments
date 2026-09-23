<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Actions\ReleaseSubscription;
use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Events\SubscriptionCancelled;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\RemotePayment;
use Goldnead\StatamicPayments\Support\SubscriptionPauses;
use Goldnead\StatamicPayments\Support\Subscriptions;
use Goldnead\StatamicPayments\Support\SubscriptionSwitches;
use Goldnead\StatamicPayments\Tests\Support\PausingFakeGateway;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

/**
 * Claims against each other, and claims left behind (Gauntlet 3, points 1, 2, 5).
 *
 * Round 2 made two requests of the same kind safe. These are the mixed ones: a
 * cancellation while a resume runs, a pause while a switch runs. And the ones a
 * dying process leaves: a row stuck in `resuming` while the provider already
 * charges a new agreement.
 */
class SubscriptionClaimRaceTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'basis' => ['name' => 'Basis', 'amount_cent' => 1900, 'interval' => '1 month', 'switch_to' => ['plus']],
            'plus' => ['name' => 'Plus', 'amount_cent' => 2900, 'interval' => '1 month'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-23 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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
            'email' => 'wer@example.com',
        ], $werte));
    }

    /** A row left in a claim, `$minutes` ago. */
    protected function haengt(Subscription $abo, string $status, int $minutes = 20, array $meta = []): Subscription
    {
        Subscription::query()->whereKey($abo->getKey())->update([
            'status' => $status,
            'updated_at' => Carbon::now()->subMinutes($minutes),
            'meta' => json_encode(array_merge($abo->fresh()->meta ?? [], $meta)),
        ]);

        return $abo->fresh();
    }

    protected function pauses(): SubscriptionPauses
    {
        return app(SubscriptionPauses::class);
    }

    // ------------------------------------------------ 1: claims of other kinds

    #[Test]
    public function a_cancellation_while_a_resume_runs_is_refused_and_not_reported_as_done(): void
    {
        $abo = $this->abo();
        $this->pauses()->pause($abo);
        $laufend = $this->haengt($abo, Subscription::STATUS_RESUMING, 0);
        $vorher = $this->gateway->cancelled;

        $this->assertFalse(app(Subscriptions::class)->cancel($laufend), 'a cancel reported success while a resume was starting a new agreement');
        $this->assertSame($vorher, $this->gateway->cancelled);
    }

    #[Test]
    public function a_resume_while_a_cancellation_runs_is_refused(): void
    {
        $abo = $this->abo();
        $this->pauses()->pause($abo);
        $this->haengt($abo, Subscription::STATUS_CANCELLING, 0);

        $this->assertFalse($this->pauses()->resume($abo));
        $this->assertSame(0, $this->gateway->subscriptionsCreated);
    }

    #[Test]
    public function a_pause_while_a_switch_runs_is_refused(): void
    {
        $gateway = new PausingFakeGateway;
        $this->gateway = $gateway;
        $this->app->instance(PaymentGateway::class, $gateway);
        $abo = $this->abo();

        $pauseWaehrendDessen = null;
        $gateway->whileCalling = function () use ($abo, &$pauseWaehrendDessen) {
            // The difference is being charged; somebody presses Pause.
            $pauseWaehrendDessen = $this->pauses()->pause(Subscription::findOrFail($abo->getKey()));
        };

        $this->assertTrue(app(SubscriptionSwitches::class)->switch($abo, 'plus'));
        $this->assertFalse($pauseWaehrendDessen, 'a pause ran inside a switch');
        $this->assertSame([], $gateway->paused);
        $this->assertSame(Subscription::STATUS_ACTIVE, $abo->fresh()->status);
    }

    #[Test]
    public function a_switch_keeps_what_was_written_to_the_row_meanwhile(): void
    {
        $gateway = new PausingFakeGateway;
        $this->gateway = $gateway;
        $this->app->instance(PaymentGateway::class, $gateway);
        $abo = $this->abo();

        $gateway->whileCalling = function () use ($abo) {
            $zeile = Subscription::findOrFail($abo->getKey());
            $zeile->forceFill(['meta' => array_merge($zeile->meta ?? [], ['nebenbei' => 'ja'])])->save();
        };

        app(SubscriptionSwitches::class)->switch($abo, 'plus');

        $this->assertSame('ja', $abo->fresh()->meta['nebenbei'] ?? null, 'the switch wrote back the meta it had read before');
    }

    // ------------------------------------------- 2: claims a process left behind

    #[Test]
    public function money_on_a_row_in_a_claim_is_counted(): void
    {
        $abo = $this->haengt($this->abo(), Subscription::STATUS_SWITCHING);

        $id = $this->gateway->arrive('basis', 1900, 'sub_1');
        $this->postJson(route('statamic-payments.webhook'), ['id' => $id])->assertOk();

        $this->assertSame(2, $abo->fresh()->times_charged, 'a paid charge was thrown away as "already over"');
    }

    #[Test]
    public function money_on_the_new_agreement_a_dead_resume_started_finds_its_row(): void
    {
        $abo = $this->abo();
        $this->pauses()->pause($abo);
        $abo = $this->haengt($abo, Subscription::STATUS_RESUMING);

        // The provider started `sub_9` and the process died before the row
        // learned the id. Mollie copies the agreement's metadata onto its
        // payments.
        $this->gateway->created++;
        $id = 'tr_neu';
        $this->gateway->remote[$id] = new RemotePayment($id, Payment::STATUS_PAID, [
            'product' => 'basis', 'resumed_subscription_id' => $abo->getKey(),
        ], subscriptionId: 'sub_9');

        Log::spy();
        $this->postJson(route('statamic-payments.webhook'), ['id' => $id])->assertOk();

        $this->assertSame(2, $abo->fresh()->times_charged);
        Log::shouldNotHaveReceived('error', [\Mockery::pattern('/no record of/'), \Mockery::any()]);
    }

    #[Test]
    public function a_refresh_never_turns_a_pausing_row_into_a_cancelled_one(): void
    {
        $abo = $this->haengt($this->abo(), Subscription::STATUS_PAUSING);
        $this->gateway->subscriptions['sub_1']['status'] = Subscription::STATUS_CANCELLED;

        app(Subscriptions::class)->refresh($abo);

        $this->assertSame(Subscription::STATUS_PAUSING, $abo->fresh()->status);
        $this->assertNull($abo->fresh()->ended_at);
    }

    #[Test]
    public function the_sweep_adopts_the_agreement_a_dead_resume_started(): void
    {
        $abo = $this->abo();
        $this->pauses()->pause($abo);
        $abo = $this->haengt($abo, Subscription::STATUS_RESUMING);
        $this->gateway->subscriptions['sub_2'] = ['customer' => 'cst_1', 'status' => 'active', 'meta' => ['resumed_subscription_id' => $abo->getKey()]];

        $this->artisan('payments:resume-paused')->assertSuccessful();

        $abo->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $abo->status);
        $this->assertSame('sub_2', $abo->provider_id);
        $this->assertContains('sub_1', $abo->meta['previous_provider_ids']);
        $this->assertSame(0, $this->gateway->subscriptionsCreated, 'a second agreement was started next to the first');
    }

    #[Test]
    public function the_sweep_finishes_a_dead_resume_that_never_reached_the_provider(): void
    {
        $abo = $this->abo();
        $this->pauses()->pause($abo);
        $this->haengt($abo, Subscription::STATUS_RESUMING);

        $this->artisan('payments:resume-paused')->assertSuccessful();

        $this->assertSame(Subscription::STATUS_ACTIVE, $abo->fresh()->status);
        $this->assertSame(1, $this->gateway->subscriptionsCreated);
    }

    #[Test]
    public function the_sweep_settles_a_dead_pause_by_what_the_provider_says(): void
    {
        $beendet = $this->haengt($this->abo(), Subscription::STATUS_PAUSING);
        $this->gateway->subscriptions['sub_1']['status'] = Subscription::STATUS_CANCELLED;

        $this->artisan('payments:resume-paused')->assertSuccessful();

        $this->assertSame(Subscription::STATUS_PAUSED, $beendet->fresh()->status);
        $this->assertSame('recreate', $beendet->fresh()->meta['pause']['mode']);
    }

    #[Test]
    public function a_dead_pause_the_provider_never_saw_goes_back_to_running(): void
    {
        $abo = $this->haengt($this->abo(), Subscription::STATUS_PAUSING);

        $this->artisan('payments:resume-paused')->assertSuccessful();

        $this->assertSame(Subscription::STATUS_ACTIVE, $abo->fresh()->status);
    }

    #[Test]
    public function a_fresh_claim_is_left_alone(): void
    {
        $abo = $this->haengt($this->abo(), Subscription::STATUS_PAUSING, 2);

        $this->artisan('payments:resume-paused')->assertSuccessful();

        $this->assertSame(Subscription::STATUS_PAUSING, $abo->fresh()->status);
    }

    #[Test]
    public function a_dead_switch_is_reported_loudly(): void
    {
        $gateway = new PausingFakeGateway;
        $gateway->subscriptions['sub_1'] = ['customer' => 'cst_1', 'status' => 'active', 'meta' => []];
        $this->gateway = $gateway;
        $this->app->instance(PaymentGateway::class, $gateway);
        $abo = $this->haengt($this->abo(), Subscription::STATUS_SWITCHING);

        Log::spy();
        $this->artisan('payments:resume-paused');

        Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains((string) $m, 'switch'))->atLeast()->once();
        $this->assertSame(Subscription::STATUS_SWITCHING, $abo->fresh()->status);
    }

    #[Test]
    public function cancelling_a_stuck_row_ends_the_agreement_it_left_behind_too(): void
    {
        $abo = $this->abo();
        $this->pauses()->pause($abo);
        $abo = $this->haengt($abo, Subscription::STATUS_RESUMING);
        $this->gateway->subscriptions['sub_2'] = ['customer' => 'cst_1', 'status' => 'active', 'meta' => ['resumed_subscription_id' => $abo->getKey()]];

        $this->assertTrue(app(Subscriptions::class)->cancel($abo));

        $this->assertContains('sub_2', $this->gateway->cancelled, 'the new agreement kept charging after the cancellation');
        $this->assertSame(Subscription::STATUS_CANCELLED, $abo->fresh()->status);
    }

    #[Test]
    public function the_claims_have_german_names_on_the_screen(): void
    {
        app()->setLocale('de');

        foreach ([Subscription::STATUS_PAUSING, Subscription::STATUS_RESUMING, Subscription::STATUS_SWITCHING, Subscription::STATUS_CANCELLING] as $status) {
            $key = 'statamic-payments::messages.subscription_status_'.$status;
            $this->assertNotSame($key, __($key), $status);
        }
    }

    // ------------------------------- Gauntlet 4: the answer that never came back

    #[Test]
    public function a_resume_whose_answer_is_lost_stays_claimed_and_is_adopted_not_repeated(): void
    {
        $this->gateway->subscriptionsCreated = 1;
        $abo = $this->abo();
        $this->pauses()->pause($abo);

        // Mollie takes the request and starts sub_2; the answer times out.
        $this->gateway->loseTheAnswer = true;
        $this->assertFalse($this->pauses()->resume($abo->fresh()));

        $this->assertSame(Subscription::STATUS_RESUMING, $abo->fresh()->status, 'a timeout was treated as a refusal');
        $this->assertSame(0, (int) data_get($abo->fresh()->meta, 'pause.attempt', 0), 'the next try would carry a new key and start a second agreement');

        // A second click finds the claim and does nothing.
        $this->assertFalse($this->pauses()->resume($abo->fresh()));
        $this->assertSame(2, $this->gateway->subscriptionsCreated);

        // The sweep adopts what the provider started.
        Carbon::setTestNow(Carbon::now()->addMinutes(20));
        $this->artisan('payments:resume-paused');

        $this->assertSame(Subscription::STATUS_ACTIVE, $abo->fresh()->status);
        $this->assertSame('sub_2', $abo->fresh()->provider_id);
        $this->assertSame(2, $this->gateway->subscriptionsCreated, 'the buyer is now charged twice a month');
    }

    #[Test]
    public function a_resume_looks_for_an_agreement_already_started_before_starting_one(): void
    {
        $this->gateway->subscriptionsCreated = 1;
        $abo = $this->abo();
        $this->pauses()->pause($abo);
        $this->gateway->subscriptions['sub_7'] = ['customer' => 'cst_1', 'status' => 'active', 'meta' => ['resumed_subscription_id' => $abo->getKey()]];

        $this->assertTrue($this->pauses()->resume($abo->fresh()));

        $this->assertSame('sub_7', $abo->fresh()->provider_id);
        $this->assertSame(1, $this->gateway->subscriptionsCreated);
    }

    #[Test]
    public function cancelling_a_paused_row_ends_an_agreement_a_lost_resume_left_running(): void
    {
        $abo = $this->abo();
        $this->pauses()->pause($abo);
        $this->gateway->subscriptions['sub_7'] = ['customer' => 'cst_1', 'status' => 'active', 'meta' => ['resumed_subscription_id' => $abo->getKey()]];

        $this->assertTrue(app(Subscriptions::class)->cancel($abo->fresh()));

        $this->assertContains('sub_7', $this->gateway->cancelled);
    }

    #[Test]
    public function the_sweep_keeps_the_claim_when_it_cannot_look_at_the_provider(): void
    {
        $abo = $this->abo();
        $this->pauses()->pause($abo);
        $abo = $this->haengt($abo, Subscription::STATUS_RESUMING);
        $this->gateway->subscriptions['sub_2'] = ['customer' => 'cst_1', 'status' => 'active', 'meta' => ['resumed_subscription_id' => $abo->getKey()]];
        $this->gateway->listingUnavailable = true;

        $this->artisan('payments:resume-paused');

        $this->assertSame(Subscription::STATUS_RESUMING, $abo->fresh()->status, 'the orphan went unseen and the row was given back');
        $this->assertSame(0, $this->gateway->subscriptionsCreated, 'a second agreement was started next to the unseen one');
    }

    // ---------------------------------------------- Gauntlet 4, the small ones

    #[Test]
    public function a_charge_on_a_claimed_row_takes_the_next_date_from_the_provider(): void
    {
        $gateway = new PausingFakeGateway;
        $gateway->subscriptions['sub_1'] = ['customer' => 'cst_1', 'status' => 'active', 'meta' => []];
        $gateway->nextPaymentDates['sub_1'] = '2026-11-05';
        $this->gateway = $gateway;
        $this->app->instance(PaymentGateway::class, $gateway);
        $abo = $this->haengt($this->abo(), Subscription::STATUS_SWITCHING);

        $id = $gateway->arrive('basis', 1900, 'sub_1');
        $this->postJson(route('statamic-payments.webhook'), ['id' => $id])->assertOk();

        $this->assertSame('2026-11-05', $abo->fresh()->next_payment_at?->toDateString());
    }

    #[Test]
    public function a_stuck_switch_can_be_released_from_the_control_panel(): void
    {
        $abo = $this->abo();
        Subscription::query()->whereKey($abo->getKey())->update([
            'status' => Subscription::STATUS_SWITCHING,
            'product' => 'plus',
            'amount_cent' => 2900,
            'updated_at' => Carbon::now()->subMinutes(30),
            'meta' => json_encode(['switching' => ['from' => 'basis', 'from_amount_cent' => 1900, 'to' => 'plus']]),
        ]);

        $action = new ReleaseSubscription;
        $this->assertTrue($action->visibleTo($abo->fresh()));
        $this->assertFalse($action->visibleTo($this->abo(['provider_id' => 'sub_x'])));

        $action->run(collect([$abo->fresh()]), ['keep' => 'old']);

        $abo->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $abo->status);
        $this->assertSame('basis', $abo->product);
        $this->assertSame(1900, $abo->amount_cent);
    }

    #[Test]
    public function a_cancellation_finished_by_the_sweep_is_announced_once(): void
    {
        Event::fake([SubscriptionCancelled::class]);

        // Dunning ended it locally and died while telling the provider.
        $abo = $this->haengt($this->abo(), Subscription::STATUS_CANCELLING, 20, ['cancelling_from' => Subscription::STATUS_CANCELLED]);
        Subscription::query()->whereKey($abo->getKey())->update(['ended_at' => Carbon::now()->subMinutes(20), 'updated_at' => Carbon::now()->subMinutes(20)]);

        $this->artisan('payments:resume-paused');

        $this->assertSame(Subscription::STATUS_CANCELLED, $abo->fresh()->status);
        Event::assertNotDispatched(SubscriptionCancelled::class);
    }

    // --------------------------------------------- 5: a new key after a refusal

    #[Test]
    public function a_resume_tried_again_after_a_refusal_uses_a_new_key(): void
    {
        $abo = $this->abo();
        $this->pauses()->pause($abo);

        $this->gateway->refuseThisSubscription = true;
        $this->assertFalse($this->pauses()->resume($abo->fresh()));
        $this->gateway->refuseThisSubscription = false;
        $this->assertTrue($this->pauses()->resume($abo->fresh()));

        [$erster, $zweiter] = array_column($this->gateway->subscriptionAttempts, 'idempotencyKey');
        $this->assertNotSame($erster, $zweiter, 'the provider would replay the refusal for the same key');
    }
}
