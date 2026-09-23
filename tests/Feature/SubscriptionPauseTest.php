<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Events\SubscriptionCancelled;
use Goldnead\StatamicPayments\Events\SubscriptionPaused;
use Goldnead\StatamicPayments\Events\SubscriptionResumed;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\SubscriptionPauses;
use Goldnead\StatamicPayments\Support\Subscriptions;
use Goldnead\StatamicPayments\Tests\Support\PausingFakeGateway;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * Pausing a subscription, and taking it up again (P1).
 *
 * Two mechanisms behind one promise. The plain fake has no pause, like Mollie:
 * the package ends the agreement and starts a new one on resume. The pausing
 * fake has one, like Stripe: the agreement stays. Both must end in the same
 * place — nothing charged during the pause, nothing charged at the moment of
 * resuming, the next charge on the old billing day.
 */
class SubscriptionPauseTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'mitgliedschaft' => ['name' => 'Mitgliedschaft', 'amount_cent' => 1900, 'interval' => '1 month'],
            'raten' => ['name' => 'Raten', 'amount_cent' => 5000, 'interval' => '1 month', 'times' => 3],
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

    protected function pauses(): SubscriptionPauses
    {
        return app(SubscriptionPauses::class);
    }

    protected function abo(array $werte = []): Subscription
    {
        $id = $werte['provider_id'] ?? 'sub_1';
        $this->gateway->subscriptions[$id] = ['customer' => 'cst_1', 'status' => $werte['status'] ?? 'active'];

        return Subscription::create(array_merge([
            'provider' => 'fake', 'provider_id' => $id, 'customer_reference' => 'cst_1',
            'product' => 'mitgliedschaft', 'amount_cent' => 1900, 'currency' => 'EUR',
            'interval' => '1 month', 'times_charged' => 2, 'status' => Subscription::STATUS_ACTIVE,
            'next_payment_at' => Carbon::parse('2026-10-05 00:00'),
            'email' => 'wer@example.com', 'name' => 'Wer',
        ], $werte));
    }

    protected function nativ(): PausingFakeGateway
    {
        $gateway = new PausingFakeGateway;
        $this->gateway = $gateway;
        $this->app->instance(PaymentGateway::class, $gateway);

        return $gateway;
    }

    // ------------------------------------------------ without a native pause

    #[Test]
    public function without_a_native_pause_the_agreement_is_ended_and_the_row_paused(): void
    {
        Event::fake([SubscriptionPaused::class, SubscriptionCancelled::class]);
        $abo = $this->abo();

        $this->assertTrue($this->pauses()->pause($abo, Carbon::parse('2026-12-01'), 'portal'));

        $abo->refresh();
        $this->assertSame(Subscription::STATUS_PAUSED, $abo->status);
        $this->assertSame(['sub_1'], $this->gateway->cancelled, 'nothing at the provider stopped the charges');
        $this->assertNull($abo->next_payment_at);
        $this->assertSame('2026-12-01', $abo->resumes_at->toDateString());
        $this->assertSame('recreate', $abo->meta['pause']['mode']);
        $this->assertSame('2026-10-05', substr($abo->meta['pause']['next_payment_at'], 0, 10));

        Event::assertDispatched(SubscriptionPaused::class, fn ($e) => $e->by === 'portal'
            && $e->resumesAt?->toDateString() === '2026-12-01');
        // A pause is not a cancellation, even where it is built from one.
        Event::assertNotDispatched(SubscriptionCancelled::class);
    }

    #[Test]
    public function resuming_starts_a_new_agreement_on_the_old_billing_day_without_charging_now(): void
    {
        Event::fake([SubscriptionResumed::class]);
        // The fake numbers what it creates; this one exists already.
        $this->gateway->subscriptionsCreated = 1;
        $abo = $this->abo();
        $this->pauses()->pause($abo);

        // Two months later. The old billing day was the 5th; the 5th of
        // December has passed, so the next one is the 5th of January.
        Carbon::setTestNow('2026-12-10 09:00:00');

        $this->assertTrue($this->pauses()->resume($abo->fresh(), 'cp'));

        $abo->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $abo->status);
        $this->assertSame($abo->getKey(), $this->gateway->lastSubscriptionPayload['metadata']['resumed_subscription_id']);
        $this->assertSame('2027-01-05', $this->gateway->lastSubscriptionPayload['startDate']);
        $this->assertSame('19.00', $this->gateway->lastSubscriptionPayload['amount']['value']);
        $this->assertSame('sub_2', $abo->provider_id, 'the row still names the agreement that was ended');
        $this->assertSame('2027-01-05', $abo->next_payment_at->toDateString());
        $this->assertNull($abo->paused_at);
        $this->assertArrayNotHasKey('pause', $abo->meta);
        $this->assertCount(1, $abo->meta['pauses']);

        Event::assertDispatched(SubscriptionResumed::class, fn ($e) => $e->by === 'cp');
    }

    #[Test]
    public function a_pause_shorter_than_the_period_resumes_on_the_same_day_it_would_have_charged(): void
    {
        $abo = $this->abo();
        $this->pauses()->pause($abo);

        Carbon::setTestNow('2026-09-30 09:00:00');
        $this->pauses()->resume($abo->fresh());

        $this->assertSame('2026-10-05', $this->gateway->lastSubscriptionPayload['startDate']);
    }

    // --------------------------------------------------- with a native pause

    #[Test]
    public function a_provider_that_can_pause_is_asked_to_and_keeps_its_agreement(): void
    {
        $gateway = $this->nativ();
        $abo = $this->abo();

        $this->assertTrue($this->pauses()->pause($abo, Carbon::parse('2026-11-15')));

        $this->assertSame([['id' => 'sub_1', 'resumes_at' => '2026-11-15']], $gateway->paused);
        $this->assertSame([], $gateway->cancelled);
        $this->assertSame('native', $abo->fresh()->meta['pause']['mode']);

        $this->assertTrue($this->pauses()->resume($abo->fresh()));

        $this->assertSame(['sub_1'], $gateway->resumed);
        $this->assertSame(0, $gateway->subscriptionsCreated, 'a native resume must not create a second agreement');
        $this->assertSame('sub_1', $abo->fresh()->provider_id);
        $this->assertSame(Subscription::STATUS_ACTIVE, $abo->fresh()->status);
    }

    #[Test]
    public function a_refusal_leaves_the_row_as_it_was(): void
    {
        $gateway = $this->nativ();
        $gateway->refuseToPause = true;
        $abo = $this->abo();

        $this->assertFalse($this->pauses()->pause($abo));
        $this->assertSame(Subscription::STATUS_ACTIVE, $abo->fresh()->status);
        $this->assertNotNull($abo->fresh()->next_payment_at);
    }

    // ------------------------------------------------------------- the edges

    #[Test]
    public function plans_trials_and_agreements_in_dunning_are_not_paused(): void
    {
        $plan = $this->abo(['provider_id' => 'sub_p', 'product' => 'raten', 'times' => 2]);
        $trial = $this->abo(['provider_id' => 'sub_t', 'status' => Subscription::STATUS_PENDING]);
        $mahnung = $this->abo(['provider_id' => 'sub_m', 'dunning_started_at' => Carbon::now()]);

        foreach ([$plan, $trial, $mahnung] as $abo) {
            $this->assertFalse($this->pauses()->pause($abo), $abo->provider_id.' was paused');
        }

        $this->assertSame([], $this->gateway->cancelled);
    }

    #[Test]
    public function a_resume_date_in_the_past_is_refused(): void
    {
        $this->assertFalse($this->pauses()->pause($this->abo(), Carbon::parse('2026-09-23')));
    }

    #[Test]
    public function the_schedule_resumes_what_is_due_and_nothing_else(): void
    {
        $faellig = $this->abo(['provider_id' => 'sub_a']);
        $spaeter = $this->abo(['provider_id' => 'sub_b', 'email' => 'b@example.com']);

        $this->pauses()->pause($faellig, Carbon::parse('2026-10-01'));
        $this->pauses()->pause($spaeter, Carbon::parse('2026-12-01'));

        Carbon::setTestNow('2026-10-01 06:00:00');
        $this->artisan('payments:resume-paused')->assertSuccessful();

        $this->assertSame(Subscription::STATUS_ACTIVE, $faellig->fresh()->status);
        $this->assertSame(Subscription::STATUS_PAUSED, $spaeter->fresh()->status);
    }

    #[Test]
    public function a_refresh_does_not_read_the_ended_agreement_as_a_cancellation(): void
    {
        $abo = $this->abo();
        $this->pauses()->pause($abo);

        app(Subscriptions::class)->refresh($abo->fresh());

        $this->assertSame(Subscription::STATUS_PAUSED, $abo->fresh()->status);
        $this->assertNull($abo->fresh()->ended_at);
    }

    #[Test]
    public function a_running_agreement_carries_no_end_date(): void
    {
        // Suspended after a failed charge, then running again with a new card.
        $abo = $this->abo(['status' => Subscription::STATUS_SUSPENDED, 'ended_at' => Carbon::now()->subDay()]);
        $this->gateway->subscriptions['sub_1']['status'] = Subscription::STATUS_ACTIVE;

        app(Subscriptions::class)->refresh($abo);

        $this->assertSame(Subscription::STATUS_ACTIVE, $abo->fresh()->status);
        $this->assertNull($abo->fresh()->ended_at, 'a report reading ended_at counts a charged agreement as churned');
    }

    #[Test]
    public function a_paused_agreement_can_still_be_cancelled_for_good(): void
    {
        Event::fake([SubscriptionCancelled::class]);
        $abo = $this->abo();
        $this->pauses()->pause($abo, Carbon::parse('2026-12-01'));

        $this->assertTrue($abo->fresh()->isRunning());
        $this->assertTrue(app(Subscriptions::class)->cancel($abo->fresh()));

        $abo->refresh();
        $this->assertSame(Subscription::STATUS_CANCELLED, $abo->status);
        $this->assertNull($abo->resumes_at, 'a cancelled agreement would have been resumed by the schedule');
        Event::assertDispatched(SubscriptionCancelled::class);
    }
}
