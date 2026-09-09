<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Events\SubscriptionCancelled;
use Goldnead\StatamicPayments\Events\SubscriptionCycleFailed;
use Goldnead\StatamicPayments\Events\SubscriptionEnded;
use Goldnead\StatamicPayments\Mail\DunningMail;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Portal\LinkTokenizer;
use Goldnead\StatamicPayments\Support\Dunning;
use Goldnead\StatamicPayments\Support\DunningNotice;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Log\Events\MessageLogged;
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
    public function a_redelivered_old_failure_does_not_reopen_a_sequence_the_money_closed(): void
    {
        // The same trap as before, through the other door. `begin()` guarding
        // only against a *running* sequence leaves the *stopped* one open: on
        // Mollie a failed payment is failed for ever, so a redelivery of its
        // webhook — after a 5xx, or from the dashboard — would open a fresh
        // sequence with a later start date, and the replacement payment sits
        // before it and is never seen again. Three letters and a cancellation,
        // to somebody who paid.
        // Die Uhr **vor** den Zeilen, sonst traegt die gescheiterte Zahlung das
        // echte Heute und die Ersatzzahlung liegt davor.
        Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00'));

        $subscription = $this->agreement();
        $this->failedCycle('tr_reopen');
        $this->gateway->markFailedCycle('tr_reopen', 'sub_1');

        $this->postJson('/!/statamic-payments/webhook', ['id' => 'tr_reopen'])->assertOk();
        $this->assertNotNull($subscription->fresh()->dunning_started_at);

        // Day two: the customer fixes their card, a new cycle is paid, and the
        // sequence closes.
        Carbon::setTestNow(Carbon::parse('2026-09-03 09:00:00'));
        Payment::create([
            'provider' => 'fake',
            'provider_id' => 'tr_replacement',
            'subscription_id' => $subscription->getKey(),
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'paid_at' => now(),
            'fulfilled_at' => now(),
            'email' => 'kaeufer@example.com',
        ]);
        app(Dunning::class)->stop($subscription->fresh());
        $this->assertNull($subscription->fresh()->dunning_started_at);

        // Day four: the old, still-failed webhook is delivered again.
        Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00'));
        $this->postJson('/!/statamic-payments/webhook', ['id' => 'tr_reopen'])->assertOk();

        $this->assertNull(
            $subscription->fresh()->dunning_started_at,
            'a paid agreement must not be dunned again by a redelivery of the old failure',
        );

        Carbon::setTestNow();
    }

    #[Test]
    public function a_voided_cycle_does_not_start_a_sequence(): void
    {
        // Voiding a Stripe invoice is somebody's hand in the dashboard, not a
        // failed collection. Three dunning letters would be the wrong answer to
        // a deliberate cancellation.
        $subscription = $this->agreement();
        $this->failedCycle('tr_void');

        $this->gateway->markFailedCycle('tr_void', 'sub_1', Payment::STATUS_CANCELED);

        $this->postJson('/!/statamic-payments/webhook', ['id' => 'tr_void'])->assertOk();

        $this->assertNull($subscription->fresh()->dunning_started_at);
    }

    #[Test]
    public function a_suppressed_address_gets_no_letter_and_the_sequence_runs_on(): void
    {
        // The gate the ticket names. Somebody who asked never to be written to
        // meant it, even about their own money — and the sequence still has to
        // reach its end rather than stalling on a stage nobody may send.
        [$subscription] = $this->openSequence();
        Mail::fake();

        $notice = new class(app(LinkTokenizer::class)) extends DunningNotice
        {
            public function suppressed(string $email, int $brandId): ?bool
            {
                return true;
            }
        };

        $this->app->instance(DunningNotice::class, $notice);

        Carbon::setTestNow(Carbon::parse('2026-09-04 10:00:00'));
        $this->artisan('payments:dunning')->assertSuccessful();

        Mail::assertNothingSent();
        // The stage still counts as done, or a suppressed customer would hold a
        // sequence open for ever.
        $this->assertSame(1, (int) $subscription->fresh()->dunning_stage);

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
            // Offen, und das ist der Punkt: eine Rechnung, deren Abbuchung
            // scheiterte, bleibt bei Stripe waehrend des ganzen
            // Wiederholungsfensters offen und wird erst danach uneinbringlich,
            // wenn das Konto so eingestellt ist. Mit dem spaeteren Zustand
            // gestellt haette dieser Test die eine Form geprueft, die es in der
            // Praxis selten gibt — und die Mahnstrecke waere auf Stripe nie
            // angelaufen, ohne dass es je aufgefallen waere.
            'status' => 'open',
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
    public function a_stage_can_only_be_claimed_once(): void
    {
        // The property the ticket names as the trap, asked of the claim
        // directly. Two sequential command runs do not prove it: the second one
        // never reaches `claimStage()`, because no stage is due yet. This test
        // would go red if the conditional UPDATE were rewritten as an
        // unconditional one, which is exactly what a test for it must do.
        [$subscription] = $this->openSequence();

        $dunning = app(Dunning::class);

        $this->assertTrue($dunning->claimStage($subscription->fresh(), 1));
        $this->assertFalse($dunning->claimStage($subscription->fresh(), 1), 'a second worker must not get the same stage');

        $this->assertSame(1, (int) $subscription->fresh()->dunning_stage);

        // And giving it back makes it available again — otherwise a letter lost
        // to a broken relay would cost the customer a stage of their sequence.
        $dunning->releaseStage($subscription->fresh(), 1);
        $this->assertTrue($dunning->claimStage($subscription->fresh(), 1));

        Carbon::setTestNow();
    }

    #[Test]
    public function a_stage_out_of_order_is_refused(): void
    {
        // Stage three cannot be taken while stage one is unsent. Without the
        // `dunning_stage = n-1` condition the counter could jump and the
        // customer would get the final notice first.
        [$subscription] = $this->openSequence();

        $this->assertFalse(app(Dunning::class)->claimStage($subscription->fresh(), 3));
        $this->assertSame(0, (int) $subscription->fresh()->dunning_stage);

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

    // --------------------------------------- a sequence whose letters stall

    #[Test]
    public function a_sequence_whose_letters_never_leave_still_ends_on_time(): void
    {
        // Der teuerste der Funde, und er kam aus der eigenen Mechanik. `send()`
        // gibt bei einer Marke ohne verifizierten Absender `FAILED` zurueck,
        // der Anspruch geht zurueck, `dunning_stage` bleibt auf 0 stehen — und
        // solange `dueToEnd()` „alle Stufen raus" verlangte, wurde es damit nie
        // wahr. Kein Brief, kein Ende, kein Zugangsentzug: ein Kunde, dessen
        // Geld nie ankam, behielt den bezahlten Zugang unbegrenzt.
        [$subscription] = $this->openSequence();
        Mail::fake();
        Event::fake([SubscriptionEnded::class]);

        $notice = new class(app(LinkTokenizer::class)) extends DunningNotice
        {
            public function send(Subscription $subscription, int $stage): string
            {
                return self::FAILED;
            }
        };

        $this->app->instance(DunningNotice::class, $notice);

        // Jeden Tag laufen lassen, vom Beginn bis eine Woche nach der letzten
        // Stufe. Kein einziger Brief geht raus.
        foreach (range(1, 22) as $tag) {
            Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00')->addDays($tag));
            $this->artisan('payments:dunning')->assertSuccessful();
        }

        Mail::assertNothingSent();

        $frisch = $subscription->fresh();
        $this->assertSame(0, (int) $frisch->dunning_stage, 'no stage was ever claimed');
        $this->assertSame(Subscription::STATUS_CANCELLED, $frisch->status);
        $this->assertNull($frisch->dunning_started_at, 'the sequence was closed out');
        Event::assertDispatched(SubscriptionEnded::class);
    }

    #[Test]
    public function a_provider_that_never_answers_does_not_hold_the_sequence_open_for_ever(): void
    {
        // Dieselbe Form, anderer Ausloeser: rotierter Schluessel, 401 auf jedem
        // `fetch`. `settledMeanwhile()` gibt fuer immer `null`, also geht nie
        // ein Brief raus, also stieg der Zaehler nie, also endete die Strecke
        // nie. Der bestehende Ausfall-Test setzte den Ausfall erst ein, als
        // alle Stufen schon draussen waren — genau daran ging der Fund vorbei.
        [$subscription] = $this->openSequence();
        Mail::fake();

        $this->gateway->throwOnFetch = true;

        foreach (range(1, 22) as $tag) {
            Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00')->addDays($tag));
            $this->artisan('payments:dunning')->assertSuccessful();
        }

        Mail::assertNothingSent();
        $this->assertSame(Subscription::STATUS_CANCELLED, $subscription->fresh()->status);
    }

    #[Test]
    public function an_unreadable_suppression_list_does_not_cost_the_customer_a_stage(): void
    {
        // „Sperrliste nicht lesbar" ist nicht „steht auf der Sperrliste". Als
        // die Stoerung noch `true` hiess, lief die ganze Strecke ohne einen
        // Brief durch, das Abo endete am Tag 21, und im Protokoll stand die
        // Behauptung, die Adresse habe auf der Sperrliste gestanden.
        [$subscription] = $this->openSequence();
        Mail::fake();

        $notice = new class(app(LinkTokenizer::class)) extends DunningNotice
        {
            public function suppressed(string $email, int $brandId): ?bool
            {
                return null;
            }
        };

        $this->app->instance(DunningNotice::class, $notice);

        Carbon::setTestNow(Carbon::parse('2026-09-04 10:00:00'));
        $this->artisan('payments:dunning')->assertSuccessful();

        Mail::assertNothingSent();
        // Der Anspruch ging zurueck: die Stufe steht wieder offen und der
        // naechste Lauf versucht sie erneut, sobald die Liste antwortet.
        $this->assertSame(0, (int) $subscription->fresh()->dunning_stage);
        $this->assertDatabaseMissing('payment_communications', ['kind' => 'dunning_suppressed']);
    }

    #[Test]
    public function cancelling_in_the_middle_of_a_sequence_closes_it(): void
    {
        // `begin()` haelt gekuendigte Abos heraus, aber nur beim Oeffnen. Wer
        // am Tag 4 kuendigt, statt die Karte zu reparieren, bekam bisher Brief
        // zwei und drei — beide mit einem Link zum Kartenwechsel — und am Tag
        // 21 ein zweites `SubscriptionEnded` samt Kuendigungsversuch gegen
        // einen Anbieter, der die Vereinbarung laengst beendet hat.
        [$subscription] = $this->openSequence();
        Mail::fake();

        // Tag 3: der erste Brief geht raus.
        Carbon::setTestNow(Carbon::parse('2026-09-04 10:00:00'));
        $this->artisan('payments:dunning')->assertSuccessful();
        Mail::assertSent(DunningMail::class, 1);
        $this->assertSame(1, (int) $subscription->fresh()->dunning_stage);

        // Tag 4: der Kunde kuendigt im Portal.
        Carbon::setTestNow(Carbon::parse('2026-09-05 10:00:00'));
        Subscription::query()->whereKey($subscription->getKey())->update([
            'status' => Subscription::STATUS_CANCELLED,
            'cancelled_at' => Carbon::now(),
            'ended_at' => Carbon::now(),
        ]);

        Event::fake([SubscriptionEnded::class]);

        // Der Rest der Strecke, Tag fuer Tag bis hinter das Ende.
        foreach (range(5, 22) as $tag) {
            Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00')->addDays($tag));
            $this->artisan('payments:dunning')->assertSuccessful();
        }

        // Kein zweiter Brief, kein zweites Ende.
        Mail::assertSent(DunningMail::class, 1);
        Event::assertNotDispatched(SubscriptionEnded::class);

        // Und die Strecke steht nicht als Leiche in der Tabelle: sonst haelt
        // sie fuer immer `payments:prune-unpaid` von ihrer Zyklus-Zeile ab.
        $frisch = $subscription->fresh();
        $this->assertNull($frisch->dunning_started_at);
        $this->assertNull($frisch->dunning_payment_id);
    }

    #[Test]
    public function one_broken_sequence_does_not_stop_the_run(): void
    {
        // `running()` sortiert nach dem Beginn der Strecke. Riss eine Zeile das
        // Kommando ab, stand dasselbe kaputte Abo am naechsten Tag wieder vorn
        // und alles dahinter bekam nie wieder einen Brief.
        [$kaputt] = $this->openSequence();

        Carbon::setTestNow(Carbon::parse('2026-09-01 09:30:00'));
        $zweite = Subscription::create([
            'provider' => 'fake',
            'provider_id' => 'sub_2',
            'customer_reference' => 'cus_2',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'interval' => '1 month',
            'times_charged' => 3,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subMonths(3),
            'email' => 'zweiter@example.com',
            'name' => 'Zweite',
        ]);
        $zyklus = $this->failedCycle('tr_cycle_2');
        $this->gateway->markFailedCycle('tr_cycle_2', 'sub_2');
        app(Dunning::class)->begin($zweite, $zyklus);

        Mail::fake();

        $kaputteId = $kaputt->getKey();
        $notice = new class(app(LinkTokenizer::class)) extends DunningNotice
        {
            public int $kaputteId = 0;

            public function send(Subscription $subscription, int $stage): string
            {
                if ((int) $subscription->getKey() === $this->kaputteId) {
                    throw new \RuntimeException('diese Zeile ist kaputt');
                }

                return parent::send($subscription, $stage);
            }
        };
        $notice->kaputteId = (int) $kaputteId;

        $this->app->instance(DunningNotice::class, $notice);

        Carbon::setTestNow(Carbon::parse('2026-09-04 10:00:00'));
        // Weitergelaufen ist nicht gelungen: der Rueckgabewert meldet den Bruch,
        // damit der Planer nicht darueber schweigt.
        $this->artisan('payments:dunning')->assertFailed();

        // Die zweite Zeile wurde trotzdem bedient.
        $this->assertSame(1, (int) $zweite->fresh()->dunning_stage);
        Mail::assertSent(DunningMail::class, 1);
    }

    // ------------------------------------- the ending and the access it takes

    #[Test]
    public function a_listener_that_throws_never_costs_both_the_access_and_the_sequence(): void
    {
        // Der teuerste Ausgang, den es hier gibt: `SubscriptionEnded` ist das,
        // was den Zugang entzieht. Wurde die Strecke abgeraeumt und das Abo
        // gekuendigt, *bevor* das Ereignis ohne Wurf durch war, und warf dann
        // ein Zuhoerer, so war beides weg — der Zugang blieb bestehen und keine
        // Zeile fand die Strecke je wieder. Erlaubt sind genau zwei Ausgaenge:
        // der Zugang ist entzogen, oder die Strecke steht noch da.
        [$subscription] = $this->openSequence();
        Mail::fake();

        Event::listen(SubscriptionEnded::class, function (): void {
            throw new \RuntimeException('der Zugangsentzug ist gescheitert');
        });

        Carbon::setTestNow(Carbon::parse('2026-09-22 10:00:00'));
        // Und der Lauf sagt es: ein gescheiterter Zugangsentzug ist der
        // teuerste Ausgang, den es hier gibt, und darf im Cron nicht als
        // ruhiger Abend ankommen.
        $this->artisan('payments:dunning')
            ->expectsOutputToContain('1 sequence(s) threw')
            ->assertFailed();

        $frisch = $subscription->fresh();

        $this->assertNotNull(
            $frisch->dunning_started_at,
            'the sequence stays findable when the access could not be withdrawn'
        );
        $this->assertSame(
            Subscription::STATUS_ACTIVE,
            $frisch->status,
            'a subscription is not cancelled while its access is still standing'
        );
    }

    #[Test]
    public function the_next_run_ends_what_the_throwing_listener_left_open(): void
    {
        // Die andere Haelfte derselben Zusicherung: „auffindbar" ist nur dann
        // etwas wert, wenn der naechste Lauf es auch wirklich zu Ende bringt.
        [$subscription] = $this->openSequence();
        Mail::fake();

        $kaputt = true;

        Event::listen(SubscriptionEnded::class, function () use (&$kaputt): void {
            if ($kaputt) {
                throw new \RuntimeException('der Zugangsentzug ist gescheitert');
            }
        });

        Carbon::setTestNow(Carbon::parse('2026-09-22 10:00:00'));
        // Mit Zusicherung, nicht nackt: eine `PendingCommand` ohne Zusicherung
        // laeuft erst beim Aufraeumen, also nach der naechsten Zeile — und der
        // Wurf faende dann keinen werfenden Zuhoerer mehr vor.
        $this->artisan('payments:dunning')->assertFailed();

        $kaputt = false;
        $this->artisan('payments:dunning')->expectsOutputToContain('1 agreement(s) ended')->assertSuccessful();

        $frisch = $subscription->fresh();
        $this->assertSame(Subscription::STATUS_CANCELLED, $frisch->status);
        $this->assertNull($frisch->dunning_started_at);
    }

    #[Test]
    public function ending_a_sequence_announces_one_thing_once(): void
    {
        // `Dunning::end()` beendet lokal und laesst den Anbieter danach
        // nachziehen — und `Subscriptions::cancel()` feuerte daraufhin sein
        // eigenes `SubscriptionCancelled` obendrauf. Fuer eine Kuendigung liefen
        // zwei ausdruecklich verschieden gemeinte Ereignisse durch alle
        // Zuhoerer: wer an `SubscriptionCancelled` eine Kuendigungsmail oder
        // einen Churn-Zaehler haengt, bekam sie doppelt, und nichts sagte es.
        [$subscription] = $this->openSequence();
        Mail::fake();
        Event::fake([SubscriptionEnded::class, SubscriptionCancelled::class]);

        Carbon::setTestNow(Carbon::parse('2026-09-22 10:00:00'));
        $this->artisan('payments:dunning')->assertSuccessful();

        Event::assertDispatchedTimes(SubscriptionEnded::class, 1);
        Event::assertNotDispatched(SubscriptionCancelled::class);

        $this->assertSame(Subscription::STATUS_CANCELLED, $subscription->fresh()->status);
    }

    #[Test]
    public function a_cycle_for_an_agreement_this_site_already_ended_is_said_out_loud(): void
    {
        // Der Anbieter bucht weiter ab, obwohl die Kuendigung dort scheiterte:
        // die Zeile steht lokal auf gekuendigt, `announceFailedCycle()` kehrt
        // zurueck, und ab da war jeder weitere Zyklus unsichtbar. Genau der
        // Zustand, der Geld kostet, war der einzige ohne Meldung.
        $subscription = $this->agreement();
        $subscription->forceFill([
            'status' => Subscription::STATUS_CANCELLED,
            'ended_at' => now(),
        ])->save();

        $this->failedCycle('tr_zombie');
        $this->gateway->markFailedCycle('tr_zombie', 'sub_1');

        $meldungen = [];

        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$meldungen): void {
            $meldungen[] = $m;
        });

        $this->postJson('/!/statamic-payments/webhook', ['id' => 'tr_zombie'])->assertOk();

        $passende = array_values(array_filter(
            $meldungen,
            fn (MessageLogged $m) => str_contains($m->message, 'already ended'),
        ));

        $this->assertNotEmpty($passende, 'the zombie cycle was reported');
        $this->assertSame('warning', $passende[0]->level);
    }

    #[Test]
    public function closing_the_sequences_after_the_switch_survives_a_broken_row(): void
    {
        // Derselbe Schutz wie im Hauptlauf: `running()` sortiert immer gleich,
        // also stuende eine werfende Zeile morgen wieder vorn und alles
        // dahinter bliebe eingefroren — der Zustand, den dieser Pfad gerade
        // aufloesen soll.
        [$erste] = $this->openSequence();

        $zweite = Subscription::create([
            'provider' => 'fake',
            'provider_id' => 'sub_2',
            'customer_reference' => 'cus_2',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'interval' => '1 month',
            'times_charged' => 3,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subMonths(3),
            'email' => 'zweiter@example.com',
        ]);
        $zyklus = $this->failedCycle('tr_cycle_2');
        app(Dunning::class)->begin($zweite, $zyklus);

        config(['statamic-payments.dunning.enabled' => false]);

        $kaputteId = (int) $erste->getKey();
        $dunning = new class extends Dunning
        {
            public int $kaputteId = 0;

            public function stop(Subscription $subscription): void
            {
                if ((int) $subscription->getKey() === $this->kaputteId) {
                    throw new \RuntimeException('diese Zeile ist kaputt');
                }

                parent::stop($subscription);
            }
        };
        $dunning->kaputteId = $kaputteId;
        $this->app->instance(Dunning::class, $dunning);

        $this->artisan('payments:dunning')
            ->expectsOutputToContain('1 sequence(s) threw')
            ->assertFailed();

        // Die zweite Zeile wurde trotzdem geschlossen.
        $this->assertNull($zweite->fresh()->dunning_started_at);
        $this->assertNotNull($erste->fresh()->dunning_started_at);
    }

    #[Test]
    public function a_run_with_a_broken_sequence_ends_with_a_failing_exit_code(): void
    {
        // Im Cron ist der Rueckgabewert das Einzige, was gelesen wird. Ein Lauf,
        // in dem jede einzelne Zeile geworfen hat, meldete `0` — und damit
        // schwieg der Planer ueber eine Mahnstrecke, die niemanden mehr mahnt.
        [$subscription] = $this->openSequence();
        Mail::fake();

        $notice = new class(app(LinkTokenizer::class)) extends DunningNotice
        {
            public function send(Subscription $subscription, int $stage): string
            {
                throw new \RuntimeException('diese Zeile ist kaputt');
            }
        };

        $this->app->instance(DunningNotice::class, $notice);

        Carbon::setTestNow(Carbon::parse('2026-09-04 10:00:00'));
        $this->artisan('payments:dunning')
            ->expectsOutputToContain('1 sequence(s) threw')
            ->assertFailed();
    }

    // ------------------------------------------- the switch and the schedule

    #[Test]
    public function a_grace_period_of_zero_does_not_eat_the_last_letter(): void
    {
        // Mit `grace_days => 0` fallen die letzte Stufe und die Frist auf
        // denselben Tag, und der Lauf fragte zuerst nach der Frist. Der Kunde
        // bekam zwei von drei versprochenen Briefen und war gekuendigt, bevor
        // der dritte je geschrieben wurde.
        config(['statamic-payments.dunning.grace_days' => 0]);

        [$subscription] = $this->openSequence();
        Mail::fake();

        foreach (range(1, 16) as $tag) {
            Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00')->addDays($tag));
            $this->artisan('payments:dunning')->assertSuccessful();
        }

        Mail::assertSent(DunningMail::class, 3);
        $this->assertSame(Subscription::STATUS_CANCELLED, $subscription->fresh()->status);
    }

    #[Test]
    public function switching_the_sequence_off_does_not_freeze_the_running_ones(): void
    {
        // Ein Schalter, der laufende Strecken einfriert, ist die stille
        // Variante des Lochs, das diese Klasse schliessen soll: `running()`
        // haelt sie fuer immer, kein Brief geht mehr raus, kein Ende kommt, und
        // `prune-unpaid` raeumt die Zyklus-Zeile nicht weg, weil sie unter einer
        // Mahnstrecke haengt. Aus heisst deshalb: die Strecken werden
        // geschlossen, das Abo bleibt, wie der Anbieter es gesetzt hat.
        [$subscription, $payment] = $this->openSequence();
        Mail::fake();

        config(['statamic-payments.dunning.enabled' => false]);

        Carbon::setTestNow(Carbon::parse('2026-09-04 10:00:00'));
        $this->artisan('payments:dunning')->assertSuccessful();

        $frisch = $subscription->fresh();
        $this->assertNull($frisch->dunning_started_at, 'the sequence was closed rather than frozen');
        $this->assertNull($frisch->dunning_payment_id, 'and the cycle row is prunable again');
        $this->assertSame(Subscription::STATUS_ACTIVE, $frisch->status, 'a switch is not a cancellation');
        Mail::assertNothingSent();

        $this->assertSame(0, Subscription::query()
            ->whereNotNull('dunning_payment_id')
            ->where('dunning_payment_id', $payment->getKey())
            ->count());
    }

    #[Test]
    public function a_brand_mailer_that_cannot_be_built_says_so_out_loud(): void
    {
        // `Log::debug` war die falsche Lautstaerke. Der markenbewusste Versand
        // ist installiert, aber nicht gebunden: jeder Brief geht danach unter
        // dem Absender der Grundeinstellung raus, also unter dem Namen der
        // falschen Marke — bei einem Brief ueber das Geld eines Kunden. In den
        // Standard-Kanaelen steht `debug` nicht, das sah niemand je.
        [$subscription] = $this->openSequence();
        Mail::fake();

        if (! class_exists(DunningNotice::BRAND_MAILER)) {
            $this->markTestSkipped('statamic-brand-context is not installed.');
        }

        $meldungen = [];

        Event::listen(MessageLogged::class, function (MessageLogged $meldung) use (&$meldungen): void {
            $meldungen[] = $meldung;
        });

        Carbon::setTestNow(Carbon::parse('2026-09-04 10:00:00'));
        $this->artisan('payments:dunning')->assertSuccessful();

        Mail::assertSent(DunningMail::class, 1);

        $passende = array_values(array_filter(
            $meldungen,
            fn (MessageLogged $m) => str_contains($m->message, 'brand-aware mailer'),
        ));

        $this->assertNotEmpty($passende, 'the fallback to the ordinary mailer was logged');
        $this->assertSame('warning', $passende[0]->level, 'and loud enough to appear in the ordinary channels');
        $this->assertArrayHasKey('sender', $passende[0]->context, 'naming the sender the letter actually went out under');
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
