<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Events\SubscriptionCycleFailed;
use Goldnead\StatamicPayments\Events\SubscriptionEnded;
use Goldnead\StatamicPayments\Mail\DunningMail;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Dunning;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;

/**
 * Trying to keep a customer whose card stopped working.
 *
 * The three properties every test here goes after: each letter goes out once,
 * the provider decides when it is over rather than the calendar, and the
 * sequence actually ends instead of leaving a free customer behind.
 */
class DunningTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.dunning.enabled', true);
    }

    /**
     * Die Uhr zurueckstellen, egal wie der Test ausging.
     *
     * Am Ende jedes Tests aufgeraeumt zu haben reicht nicht: faellt eine
     * Zusicherung vorher, wird die Zeile nie erreicht und die naechste
     * Testklasse laeuft im September 2026. Das ist kein hypothetischer Fehler,
     * sondern genau die Sorte, die als „ein Test woanders ist rot, einzeln aber
     * gruen" auftaucht.
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function agreement(string $provider = 'fake'): Subscription
    {
        return Subscription::create([
            'provider' => $provider,
            'provider_id' => 'sub_1',
            'customer_reference' => 'cus_1',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'interval' => '1 month',
            'times' => null,
            'times_charged' => 3,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subMonths(3),
            'email' => 'kaeufer@example.com',
            'name' => 'Adrian',
        ]);
    }

    protected function failedCycle(string $providerId = 'tr_cycle'): Payment
    {
        return Payment::create([
            'provider' => 'fake',
            'provider_id' => $providerId,
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_FAILED,
            'email' => 'kaeufer@example.com',
        ]);
    }

    // ------------------------------------------------------ the failed cycle

    #[Test]
    public function a_failed_cycle_of_a_running_agreement_announces_itself(): void
    {
        // Until now this was invisible: an agreement that ran for months, a
        // card that expired, and the package mirrored the provider's status
        // without ever saying anything about it.
        $subscription = $this->agreement();
        $payment = $this->failedCycle();

        Event::fake([SubscriptionCycleFailed::class]);

        $this->gateway->markFailedCycle('tr_cycle', 'sub_1');

        $this->postJson('/!/statamic-payments/webhook', ['id' => 'tr_cycle'])->assertOk();

        Event::assertDispatched(
            SubscriptionCycleFailed::class,
            fn ($e) => $e->subscription->is($subscription) && $e->payment->is($payment),
        );
    }

    #[Test]
    public function a_cycle_still_in_flight_announces_nothing(): void
    {
        // `open` is a direct debit on its way. Dunning somebody whose money is
        // moving is the letter you cannot take back.
        $this->agreement();
        $this->failedCycle('tr_open');

        Event::fake([SubscriptionCycleFailed::class]);

        $this->gateway->markFailedCycle('tr_open', 'sub_1', Payment::STATUS_OPEN);

        $this->postJson('/!/statamic-payments/webhook', ['id' => 'tr_open'])->assertOk();

        Event::assertNotDispatched(SubscriptionCycleFailed::class);
    }

    #[Test]
    public function a_cancelled_agreement_is_not_dunned(): void
    {
        // Somebody who has just cancelled would otherwise get three letters
        // asking them to fix a card they meant to stop using.
        $subscription = $this->agreement();
        $subscription->forceFill(['status' => Subscription::STATUS_CANCELLED])->save();
        $this->failedCycle('tr_cancelled');

        Event::fake([SubscriptionCycleFailed::class]);

        $this->gateway->markFailedCycle('tr_cancelled', 'sub_1');

        $this->postJson('/!/statamic-payments/webhook', ['id' => 'tr_cancelled'])->assertOk();

        Event::assertNotDispatched(SubscriptionCycleFailed::class);
    }

    #[Test]
    public function the_sequence_opens_once_however_often_the_provider_says_it_again(): void
    {
        $subscription = $this->agreement();
        $this->failedCycle('tr_twice');
        $this->gateway->markFailedCycle('tr_twice', 'sub_1');

        Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00'));
        $this->postJson('/!/statamic-payments/webhook', ['id' => 'tr_twice'])->assertOk();
        $opened = $subscription->fresh()->dunning_started_at;

        Carbon::setTestNow(Carbon::parse('2026-09-03 09:00:00'));
        $this->postJson('/!/statamic-payments/webhook', ['id' => 'tr_twice'])->assertOk();

        // A restarted clock is a sequence that never reaches its end, and a
        // customer who is never let go.
        $this->assertEquals($opened, $subscription->fresh()->dunning_started_at);

        Carbon::setTestNow();
    }

    #[Test]
    public function a_failed_stripe_invoice_announces_itself_over_the_signed_webhook(): void
    {
        // The other provider, over its own signed endpoint. Without this the
        // sequence is only ever proved on one path, and "provider-neutral"
        // rests on reading the code rather than on running it.
        config([
            'statamic-payments.stripe.key' => 'sk_test_notARealKey',
            'statamic-payments.stripe.webhook_secret' => 'whsec_kJ8vN2mQ4pR7sT1uV3wX5yZ6aB8cD0eF',
        ]);

        $subscription = Subscription::create([
            'provider' => 'stripe',
            'provider_id' => 'sub_stripe_1',
            'customer_reference' => 'cus_1',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'interval' => '1 month',
            'times_charged' => 3,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subMonths(3),
            'email' => 'kaeufer@example.com',
        ]);

        $payment = Payment::create([
            'provider' => 'stripe',
            'provider_id' => 'in_stripe_failed',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_OPEN,
            'email' => 'kaeufer@example.com',
        ]);

        // Deliberately not faking the event here. Faking it would stop the
        // listener, and then the assertion below — that the sequence actually
        // opened — could never pass. The whole chain is what this test is for:
        // signed delivery, invoice fetched back, event, listener, `begin()`.
        Http::fake(['api.stripe.com/v1/invoices/in_stripe_failed*' => Http::response([
            'id' => 'in_stripe_failed',
            'object' => 'invoice',
            'status' => 'uncollectible',
            'subscription' => 'sub_stripe_1',
            'customer_email' => 'kaeufer@example.com',
        ])]);

        $body = json_encode([
            'id' => 'evt_invoice_failed',
            'object' => 'event',
            'type' => 'invoice.payment_failed',
            'data' => ['object' => ['id' => 'in_stripe_failed', 'object' => 'invoice']],
        ], JSON_UNESCAPED_SLASHES);

        $timestamp = Carbon::now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_kJ8vN2mQ4pR7sT1uV3wX5yZ6aB8cD0eF');

        $this->call('POST', '/!/statamic-payments/webhook/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $body)->assertOk();

        $this->assertNotNull(
            $subscription->fresh()->dunning_started_at,
            'a failed Stripe invoice must open the sequence, exactly as a failed Mollie cycle does',
        );
        $this->assertSame($payment->getKey(), (int) $subscription->fresh()->dunning_payment_id);
    }

    // ------------------------------------------------------------- the stages

    /** Opens a sequence at a fixed moment, without going through the webhook. */
    protected function openSequence(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00'));

        $subscription = $this->agreement();
        $payment = $this->failedCycle();

        // The provider has to know it too: every run asks whether the money
        // arrived after all, and an id the provider does not recognise is the
        // "would not answer" case, not the "still unpaid" one.
        $this->gateway->markFailedCycle('tr_cycle', 'sub_1');

        app(Dunning::class)->begin($subscription, $payment);

        return [$subscription->fresh(), $payment];
    }

    #[Test]
    public function each_stage_goes_out_on_its_own_day_and_carries_a_portal_link(): void
    {
        [$subscription] = $this->openSequence();
        Mail::fake();

        // Nothing on day one.
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        $this->artisan('payments:dunning')->assertSuccessful();
        Mail::assertNothingSent();

        foreach ([['2026-09-04', 1], ['2026-09-08', 2], ['2026-09-15', 3]] as [$day, $stage]) {
            Carbon::setTestNow(Carbon::parse($day.' 10:00:00'));
            $this->artisan('payments:dunning')->assertSuccessful();

            $this->assertSame($stage, (int) $subscription->fresh()->dunning_stage, "stage {$stage} was due on {$day}");
        }

        Mail::assertSent(DunningMail::class, 3);

        // The point of the whole letter: a signed, short-lived way back into
        // the portal, where the card can be replaced.
        Mail::assertSent(DunningMail::class, function (DunningMail $mail) {
            $url = $mail->variables['portal_url'] ?? '';

            return str_contains($url, '/konto/link/') && str_contains($url, 'signature=');
        });

        Carbon::setTestNow();
    }

    #[Test]
    public function a_second_run_of_the_same_schedule_sends_nothing_more(): void
    {
        [$subscription] = $this->openSequence();
        Mail::fake();

        Carbon::setTestNow(Carbon::parse('2026-09-04 10:00:00'));

        // Two runs of the same schedule, which is what two workers are.
        $this->artisan('payments:dunning')->assertSuccessful();
        $this->artisan('payments:dunning')->assertSuccessful();

        Mail::assertSent(DunningMail::class, 1);
        $this->assertSame(1, (int) $subscription->fresh()->dunning_stage);

        Carbon::setTestNow();
    }

    #[Test]
    public function a_missed_run_does_not_send_three_letters_at_once(): void
    {
        // The scheduler was down for a fortnight. The customer gets the letters
        // one run at a time, not the whole sequence in one inbox.
        [$subscription] = $this->openSequence();
        Mail::fake();

        Carbon::setTestNow(Carbon::parse('2026-09-16 10:00:00'));
        $this->artisan('payments:dunning')->assertSuccessful();

        Mail::assertSent(DunningMail::class, 1);
        $this->assertSame(1, (int) $subscription->fresh()->dunning_stage);

        Carbon::setTestNow();
    }

    // -------------------------------------------- the card went through

    #[Test]
    public function the_sequence_ends_silently_when_the_card_goes_through_meanwhile(): void
    {
        // The provider retries on its own rhythm. If it collects the money
        // between two letters, nothing further is sent and nobody is told
        // anything — a card that failed on Tuesday and worked on Thursday is an
        // ordinary week at a payment provider.
        [$subscription] = $this->openSequence();
        Mail::fake();

        Carbon::setTestNow(Carbon::parse('2026-09-04 10:00:00'));
        $this->artisan('payments:dunning')->assertSuccessful();
        Mail::assertSent(DunningMail::class, 1);

        // The provider now says it was paid after all.
        $this->gateway->markPaid('tr_cycle');

        Carbon::setTestNow(Carbon::parse('2026-09-08 10:00:00'));
        $this->artisan('payments:dunning')->assertSuccessful();

        // Stage two never went out, and the sequence is closed.
        Mail::assertSent(DunningMail::class, 1);
        $this->assertNull($subscription->fresh()->dunning_started_at);
        $this->assertSame(0, (int) $subscription->fresh()->dunning_stage);

        // And the agreement is untouched — this is the case where nothing
        // should have happened at all.
        $this->assertTrue($subscription->fresh()->isLive());

        Carbon::setTestNow();
    }

    #[Test]
    public function a_new_paid_cycle_stops_the_sequence_even_though_the_old_payment_stays_failed(): void
    {
        // The Mollie shape, and the one that would have cancelled a paying
        // customer. A Mollie payment that failed is failed for ever: the retry,
        // and a card the buyer replaces in the portal, produce a **new**
        // payment. Asking only about the old one answers "still not paid" until
        // the end of time.
        [$subscription] = $this->openSequence();
        Mail::fake();

        Carbon::setTestNow(Carbon::parse('2026-09-04 10:00:00'));
        $this->artisan('payments:dunning')->assertSuccessful();
        Mail::assertSent(DunningMail::class, 1);

        // A different row, fulfilled after the sequence opened. The old one
        // still says failed, and the provider still says so too.
        Payment::create([
            'provider' => 'fake',
            'provider_id' => 'tr_retry',
            'subscription_id' => $subscription->getKey(),
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'paid_at' => now(),
            'fulfilled_at' => now(),
            'email' => 'kaeufer@example.com',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-08 10:00:00'));
        $this->artisan('payments:dunning')->assertSuccessful();

        Mail::assertSent(DunningMail::class, 1);
        $this->assertNull($subscription->fresh()->dunning_started_at);
        $this->assertTrue($subscription->fresh()->isLive());

        Carbon::setTestNow();
    }

    #[Test]
    public function a_provider_outage_does_not_freeze_a_sequence_past_its_end(): void
    {
        // `dueToEnd()` is a question about dates and needs no provider. Behind
        // a successful provider call, a multi-day outage would freeze every
        // running sequence: no further letters, but no ending either, long
        // after the grace period is up.
        [$subscription] = $this->openSequence();
        Mail::fake();

        foreach (['2026-09-04', '2026-09-08', '2026-09-15'] as $day) {
            Carbon::setTestNow(Carbon::parse($day.' 10:00:00'));
            $this->artisan('payments:dunning')->assertSuccessful();
        }

        // The provider goes dark right before the end is due.
        $this->gateway->throwOnFetch = true;

        Carbon::setTestNow(Carbon::parse('2026-09-22 10:00:00'));
        $this->artisan('payments:dunning')->assertSuccessful();

        $this->assertSame(Subscription::STATUS_CANCELLED, $subscription->fresh()->status);
        $this->assertNotNull($subscription->fresh()->ended_at);

        Carbon::setTestNow();
    }

    #[Test]
    public function a_provider_that_will_not_answer_sends_nothing_on_a_guess(): void
    {
        [$subscription] = $this->openSequence();
        Mail::fake();

        $this->gateway->throwOnFetch = true;

        Carbon::setTestNow(Carbon::parse('2026-09-04 10:00:00'));
        $this->artisan('payments:dunning')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(0, (int) $subscription->fresh()->dunning_stage);

        Carbon::setTestNow();
    }

    // --------------------------------------------------------------- the end

    #[Test]
    public function after_the_last_stage_plus_the_grace_period_the_agreement_ends(): void
    {
        [$subscription] = $this->openSequence();
        Mail::fake();
        Event::fake([SubscriptionEnded::class]);

        foreach (['2026-09-04', '2026-09-08', '2026-09-15'] as $day) {
            Carbon::setTestNow(Carbon::parse($day.' 10:00:00'));
            $this->artisan('payments:dunning')->assertSuccessful();
        }

        // Day 14 plus seven days of grace.
        Carbon::setTestNow(Carbon::parse('2026-09-22 10:00:00'));
        $this->artisan('payments:dunning')->assertSuccessful();

        $subscription = $subscription->fresh();

        $this->assertSame(Subscription::STATUS_CANCELLED, $subscription->status);
        $this->assertNotNull($subscription->ended_at);
        $this->assertNull($subscription->next_payment_at);
        $this->assertNull($subscription->dunning_started_at);

        // The event is what takes the access away, through the listener that
        // already exists for an agreement running out.
        Event::assertDispatched(SubscriptionEnded::class);

        Carbon::setTestNow();
    }

    #[Test]
    public function the_agreement_is_not_ended_twice(): void
    {
        [$subscription] = $this->openSequence();
        Mail::fake();

        foreach (['2026-09-04', '2026-09-08', '2026-09-15'] as $day) {
            Carbon::setTestNow(Carbon::parse($day.' 10:00:00'));
            $this->artisan('payments:dunning')->assertSuccessful();
        }

        Event::fake([SubscriptionEnded::class]);

        Carbon::setTestNow(Carbon::parse('2026-09-22 10:00:00'));
        $this->artisan('payments:dunning')->assertSuccessful();
        $this->artisan('payments:dunning')->assertSuccessful();

        Event::assertDispatchedTimes(SubscriptionEnded::class, 1);

        Carbon::setTestNow();
    }

    #[Test]
    public function nothing_happens_at_all_while_the_sequence_is_switched_off(): void
    {
        config(['statamic-payments.dunning.enabled' => false]);

        $subscription = $this->agreement();
        $payment = $this->failedCycle();

        $this->assertFalse(app(Dunning::class)->begin($subscription, $payment));
        $this->assertNull($subscription->fresh()->dunning_started_at);
    }

    #[Test]
    public function a_nonsense_schedule_falls_back_to_the_default(): void
    {
        // An empty or negative schedule is a site that has not configured this,
        // not an instruction to write to everybody today.
        config(['statamic-payments.dunning.stages' => []]);
        $this->assertSame([1 => 3, 2 => 7, 3 => 14], app(Dunning::class)->stages());

        config(['statamic-payments.dunning.stages' => 'nonsense']);
        $this->assertSame([1 => 3, 2 => 7, 3 => 14], app(Dunning::class)->stages());

        // A configured one is sorted and deduplicated rather than trusted.
        config(['statamic-payments.dunning.stages' => [10, 2, 2, 5]]);
        $this->assertSame([1 => 2, 2 => 5, 3 => 10], app(Dunning::class)->stages());
    }
}
