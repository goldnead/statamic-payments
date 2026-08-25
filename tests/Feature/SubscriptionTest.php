<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Events\SubscriptionCancelled;
use Goldnead\StatamicPayments\Events\SubscriptionEnded;
use Goldnead\StatamicPayments\Events\SubscriptionRenewed;
use Goldnead\StatamicPayments\Events\SubscriptionStarted;
use Goldnead\StatamicPayments\Events\SubscriptionStartFailed;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Subscriptions;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Being charged again, on a rhythm.
 *
 * One mechanism wearing three hats: a subscription runs until somebody stops it,
 * a payment plan stops counting, and a trial starts late. The tests are grouped
 * that way on purpose — most of them are about the mechanism, and only a few
 * about each hat.
 *
 * The properties that matter, and each has a test that *tries* to break it:
 *
 * 1. No agreement without a mandate. A provider cannot charge a card nobody put
 *    on file, and creating the row anyway produces a subscription that fails
 *    silently forever.
 * 2. The agreement is created only after the first payment is **confirmed by
 *    the provider**, never when the browser comes back.
 * 3. A cycle is counted once, however often the webhook arrives.
 * 4. A cancellation follows the provider, not the intent.
 */
class SubscriptionTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.follow_up.collect_mandate', true);
        $app['config']->set('statamic-payments.products', [
            'mitgliedschaft' => [
                'name' => 'Mitgliedschaft',
                'amount_cent' => 1900,
                'interval' => '1 month',
            ],
            'ratenzahlung' => [
                'name' => 'Kurs in Raten',
                'amount_cent' => 5000,
                'interval' => '1 month',
                'times' => 3,
            ],
            'mit-testphase' => [
                'name' => 'Mitgliedschaft mit Testphase',
                'amount_cent' => 1900,
                'interval' => '1 month',
                'trial_days' => 14,
                'trial_amount_cent' => 100,
            ],
            'einmalig' => ['name' => 'Einmalig', 'amount_cent' => 900],
        ]);
    }

    protected function subs(): Subscriptions
    {
        return app(Subscriptions::class);
    }

    /** Walk a first payment all the way to paid, as the provider would. */
    protected function payFirst(string $product = 'mitgliedschaft'): Payment
    {
        $result = $this->subs()->start($product, ['email' => 'k@example.com', 'name' => 'Kim']);

        $this->assertNotNull($result, 'the checkout for the first payment was refused');

        $this->gateway->markPaid($result->payment->provider_id);
        $this->postJson(route('statamic-payments.webhook'), ['id' => $result->payment->provider_id]);

        return $result->payment->fresh();
    }

    #[Test]
    public function a_product_without_a_rhythm_is_not_a_subscription(): void
    {
        $this->assertNull($this->subs()->planFor('einmalig'));
        $this->assertNull($this->subs()->start('einmalig'));
        $this->assertSame(0, Subscription::count());
    }

    #[Test]
    public function nothing_starts_while_the_site_refuses_to_collect_a_mandate(): void
    {
        config(['statamic-payments.follow_up.collect_mandate' => false]);

        // Without a stored card there is nothing for the provider to charge on
        // the second month. Starting anyway would create an agreement that
        // fails quietly, on a rhythm, for as long as nobody looks.
        $this->assertNull($this->subs()->start('mitgliedschaft'));
        $this->assertSame(0, Subscription::count());
    }

    #[Test]
    public function nothing_starts_while_the_provider_cannot_do_it(): void
    {
        $this->gateway->refuseSubscriptions = true;

        $this->assertNull($this->subs()->start('mitgliedschaft'));
    }

    #[Test]
    public function the_first_payment_is_an_ordinary_checkout(): void
    {
        $result = $this->subs()->start('mitgliedschaft', ['email' => 'k@example.com']);

        $this->assertNotNull($result);
        $this->assertSame(1900, $result->payment->amount_cent);
        $this->assertNotNull($result->checkoutUrl);
        // Nothing agreed yet. The buyer is looking at a payment page.
        $this->assertSame(0, Subscription::count());
        $this->assertSame(0, $this->gateway->subscriptionsCreated);
    }

    #[Test]
    public function the_agreement_appears_only_after_the_provider_says_paid(): void
    {
        Event::fake([SubscriptionStarted::class]);

        $result = $this->subs()->start('mitgliedschaft', ['email' => 'k@example.com']);

        // A webhook while the provider still says open must not create it.
        $this->postJson(route('statamic-payments.webhook'), ['id' => $result->payment->provider_id]);
        $this->assertSame(0, Subscription::count());

        $this->gateway->markPaid($result->payment->provider_id);
        $this->postJson(route('statamic-payments.webhook'), ['id' => $result->payment->provider_id]);

        $this->assertSame(1, Subscription::count());
        Event::assertDispatched(SubscriptionStarted::class);
    }

    #[Test]
    public function an_agreement_without_a_mandate_is_refused(): void
    {
        $result = $this->subs()->start('mitgliedschaft', ['email' => 'k@example.com']);

        // The mandate is gone: the provider forgot the buyer, or the site
        // switched mandate collection off between the two steps.
        $result->payment->forceFill(['customer_reference' => null])->save();

        $this->gateway->markPaid($result->payment->provider_id);
        $this->postJson(route('statamic-payments.webhook'), ['id' => $result->payment->provider_id]);

        // No row, and — the part that matters — the payment is still fulfilled.
        $this->assertSame(0, Subscription::count());
        $this->assertNotNull($result->payment->fresh()->fulfilled_at);
    }

    #[Test]
    public function the_first_payment_belongs_to_the_agreement_it_created(): void
    {
        $payment = $this->payFirst();

        $subscription = Subscription::first();

        $this->assertSame($subscription->getKey(), $payment->subscription_id);
        $this->assertSame('mitgliedschaft', $subscription->product);
        $this->assertSame(1900, $subscription->amount_cent);
        $this->assertSame('1 month', $subscription->interval);
    }

    #[Test]
    public function a_subscription_has_no_end(): void
    {
        $this->payFirst('mitgliedschaft');

        $subscription = Subscription::first();

        $this->assertNull($subscription->times);
        $this->assertNull($subscription->remaining());
        $this->assertFalse($subscription->isPlan());
        $this->assertNull($subscription->totalCent());
    }

    #[Test]
    public function a_plan_asks_the_provider_for_one_fewer_than_it_sells(): void
    {
        $this->payFirst('ratenzahlung');

        $subscription = Subscription::first();

        // Three instalments were sold, one is already paid. Asking the provider
        // for three more would charge four, which is the arithmetic that ends
        // up in a chargeback.
        $this->assertSame(2, $subscription->times);
        $this->assertSame(2, $this->gateway->lastSubscriptionPayload['times']);
        $this->assertTrue($subscription->isPlan());
    }

    #[Test]
    public function a_plan_of_one_instalment_is_just_a_payment(): void
    {
        config(['statamic-payments.products.ratenzahlung.times' => 1]);

        $this->payFirst('ratenzahlung');

        $this->assertSame(0, Subscription::count());
        $this->assertSame(0, $this->gateway->subscriptionsCreated);
    }

    #[Test]
    public function a_trial_charges_what_the_site_said_and_starts_later(): void
    {
        $result = $this->subs()->start('mit-testphase', ['email' => 'k@example.com']);

        // The trade this package refuses to hide: a card cannot be stored
        // without charging something, so a trial charges the trial amount and
        // the payment says why.
        $this->assertSame(100, $result->payment->amount_cent);
        $this->assertSame('trial', $result->payment->discount_code);
        $this->assertSame(1800, $result->payment->discount_cent);

        $this->gateway->markPaid($result->payment->provider_id);
        $this->postJson(route('statamic-payments.webhook'), ['id' => $result->payment->provider_id]);

        $subscription = Subscription::first();

        $this->assertNotNull($subscription);
        $this->assertTrue($subscription->starts_at->isAfter(now()->addDays(13)));
        // The full price from the second charge onwards, not the trial price.
        $this->assertSame(1900, $subscription->amount_cent);
    }

    #[Test]
    public function a_trial_without_an_amount_charges_the_ordinary_price(): void
    {
        config(['statamic-payments.products.mit-testphase.trial_amount_cent' => null]);

        $result = $this->subs()->start('mit-testphase', ['email' => 'k@example.com']);

        $this->assertSame(1900, $result->payment->amount_cent);
        $this->assertNull($result->payment->discount_code);
    }

    #[Test]
    public function a_cycle_is_counted_once_however_often_it_arrives(): void
    {
        Event::fake([SubscriptionRenewed::class]);

        $this->payFirst('ratenzahlung');
        $subscription = Subscription::first();

        $cycle = $this->gateway->arrive('ratenzahlung', 5000, $subscription->provider_id);

        foreach (range(1, 3) as $ignored) {
            $this->postJson(route('statamic-payments.webhook'), ['id' => $cycle]);
        }

        $this->assertSame(1, $subscription->fresh()->times_charged);
        Event::assertDispatchedTimes(SubscriptionRenewed::class, 1);
    }

    #[Test]
    public function a_plan_that_has_paid_its_last_instalment_is_over(): void
    {
        Event::fake([SubscriptionEnded::class]);

        $this->payFirst('ratenzahlung');
        $subscription = Subscription::first();

        foreach (range(1, 2) as $ignored) {
            $cycle = $this->gateway->arrive('ratenzahlung', 5000, $subscription->provider_id);
            $this->postJson(route('statamic-payments.webhook'), ['id' => $cycle]);
        }

        $subscription = $subscription->fresh();

        $this->assertSame(2, $subscription->times_charged);
        $this->assertSame(Subscription::STATUS_COMPLETED, $subscription->status);
        $this->assertSame(0, $subscription->remaining());
        $this->assertNotNull($subscription->ended_at);
        Event::assertDispatched(SubscriptionEnded::class);
    }

    #[Test]
    public function a_cycle_for_an_agreement_this_site_does_not_know_changes_nothing(): void
    {
        $this->payFirst('mitgliedschaft');

        $cycle = $this->gateway->arrive('mitgliedschaft', 1900, 'sub_von_woanders');

        $this->postJson(route('statamic-payments.webhook'), ['id' => $cycle])->assertOk();

        $this->assertSame(0, Subscription::first()->times_charged);
    }

    #[Test]
    public function a_cycle_is_still_fulfilled_like_any_other_payment(): void
    {
        $this->payFirst('mitgliedschaft');
        $subscription = Subscription::first();

        $cycle = $this->gateway->arrive('mitgliedschaft', 1900, $subscription->provider_id);
        $this->postJson(route('statamic-payments.webhook'), ['id' => $cycle]);

        // This is what makes a subscription grant access every month without a
        // single listener knowing that subscriptions exist.
        $payment = Payment::where('provider_id', $cycle)->first();

        $this->assertNotNull($payment->fulfilled_at);
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
    }

    #[Test]
    public function cancelling_follows_the_provider_and_not_the_intent(): void
    {
        Event::fake([SubscriptionCancelled::class]);

        $this->payFirst('mitgliedschaft');
        $subscription = Subscription::first();

        // The provider accepts the call and keeps the thing running. Writing
        // "cancelled" here would be the worst outcome this package can produce:
        // an account that says stopped while the money keeps going out.
        $this->gateway->cancelLies = true;

        $this->assertFalse($this->subs()->cancel($subscription));
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->fresh()->status);
        Event::assertNotDispatched(SubscriptionCancelled::class);

        $this->gateway->cancelLies = false;

        $this->assertTrue($this->subs()->cancel($subscription->fresh()));
        $this->assertSame(Subscription::STATUS_CANCELLED, $subscription->fresh()->status);
        $this->assertNotNull($subscription->fresh()->cancelled_at);
        Event::assertDispatched(SubscriptionCancelled::class);
    }

    #[Test]
    public function the_price_is_frozen_when_the_agreement_starts(): void
    {
        $this->payFirst('mitgliedschaft');

        config(['statamic-payments.products.mitgliedschaft.amount_cent' => 2900]);

        // A price rise next year must not silently re-price somebody's running
        // agreement.
        $this->assertSame(1900, Subscription::first()->amount_cent);
    }

    #[Test]
    public function a_listener_that_throws_cannot_undo_an_agreement_the_provider_accepted(): void
    {
        // The blocker a reviewer proved before this test existed: the remote
        // call used to sit inside a transaction, so anything failing after it
        // rolled the row back while the provider kept a running subscription —
        // charging somebody every month with nothing on this site to show it.
        Event::listen(SubscriptionStarted::class, function () {
            throw new RuntimeException('ein Zuhoerer, der wirft');
        });

        $this->payFirst('mitgliedschaft');

        $this->assertSame(1, $this->gateway->subscriptionsCreated);
        $this->assertSame(1, Subscription::count(), 'the provider has one and this site does not');
        $this->assertSame(Subscription::STATUS_ACTIVE, Subscription::first()->status);
    }

    #[Test]
    public function a_provider_that_refuses_leaves_no_half_agreement_and_says_so(): void
    {
        Event::fake([SubscriptionStartFailed::class]);

        $result = $this->subs()->start('mitgliedschaft', ['email' => 'k@example.com']);

        // The provider takes the money and then refuses *this* agreement. Not
        // the same as a provider that cannot do subscriptions at all, which is
        // its own test above.
        $this->gateway->refuseThisSubscription = true;
        $this->gateway->markPaid($result->payment->provider_id);
        $this->postJson(route('statamic-payments.webhook'), ['id' => $result->payment->provider_id]);

        $this->assertSame(0, Subscription::count());

        $payment = $result->payment->fresh();

        // The customer paid, so that stands. What must not stand is silence.
        $this->assertNotNull($payment->fulfilled_at);
        $this->assertNotNull($payment->meta['subscription_start_failed_at'] ?? null);
        Event::assertDispatched(SubscriptionStartFailed::class);
    }

    #[Test]
    public function a_cycle_the_provider_does_not_confirm_counts_for_nothing(): void
    {
        $this->payFirst('ratenzahlung');
        $subscription = Subscription::first();

        // A webhook naming a real agreement, for a payment that is merely open.
        $cycle = $this->gateway->arrive('ratenzahlung', 5000, $subscription->provider_id);
        $this->gateway->markStatus($cycle, Payment::STATUS_OPEN);

        $this->postJson(route('statamic-payments.webhook'), ['id' => $cycle])->assertOk();

        // The whole rule in one assertion: a cycle counts against money, not
        // against a caller saying there was some.
        $this->assertSame(0, $subscription->fresh()->times_charged);
    }

    #[Test]
    public function a_month_from_the_thirty_first_does_not_skip_february(): void
    {
        $this->travelTo('2027-01-31 10:00');

        $this->payFirst('mitgliedschaft');

        // `add('1 month')` lands on 3 March: February is skipped and the
        // provider then bills on the 3rd for ever. Measured, not assumed.
        $this->assertSame('2027-02-28', Subscription::first()->starts_at->toDateString());
    }

    #[Test]
    public function a_cycle_carries_a_line_like_every_other_payment(): void
    {
        $this->payFirst('mitgliedschaft');
        $subscription = Subscription::first();

        $cycle = $this->gateway->arrive('mitgliedschaft', 1900, $subscription->provider_id);
        $this->postJson(route('statamic-payments.webhook'), ['id' => $cycle]);

        $payment = Payment::where('provider_id', $cycle)->first();

        // Without a line, any report built over lines leaves out every
        // recurring sale and says nothing about it.
        $this->assertSame(1, $payment->items()->count());
        $this->assertSame(1900, $payment->items()->first()->amount_cent);
    }

    #[Test]
    public function a_straggler_does_not_end_a_finished_plan_twice(): void
    {
        Event::fake([SubscriptionEnded::class]);

        $this->payFirst('ratenzahlung');
        $subscription = Subscription::first();

        foreach (range(1, 2) as $ignored) {
            $cycle = $this->gateway->arrive('ratenzahlung', 5000, $subscription->provider_id);
            $this->postJson(route('statamic-payments.webhook'), ['id' => $cycle]);
        }

        $endedAt = $subscription->fresh()->ended_at;

        $this->travel(1)->hour();

        $late = $this->gateway->arrive('ratenzahlung', 5000, $subscription->provider_id);
        $this->postJson(route('statamic-payments.webhook'), ['id' => $late]);

        Event::assertDispatchedTimes(SubscriptionEnded::class, 1);
        $this->assertSame(2, $subscription->fresh()->times_charged);
        $this->assertEquals($endedAt->toIso8601String(), $subscription->fresh()->ended_at->toIso8601String());
    }

    #[Test]
    public function the_provider_is_asked_how_the_agreement_is_doing(): void
    {
        $this->payFirst('mitgliedschaft');
        $subscription = Subscription::first();

        // The provider suspends it after a failed charge. Nothing tells this
        // site; the row would otherwise keep saying "active" for somebody whose
        // card stopped working.
        $this->gateway->subscriptions[$subscription->provider_id]['status'] = Subscription::STATUS_SUSPENDED;

        $cycle = $this->gateway->arrive('mitgliedschaft', 1900, $subscription->provider_id);
        $this->postJson(route('statamic-payments.webhook'), ['id' => $cycle]);

        $this->assertSame(Subscription::STATUS_SUSPENDED, $subscription->fresh()->status);
    }

    #[Test]
    public function the_checkout_never_learns_the_amount_from_the_caller(): void
    {
        // The whole family rests on this, and a subscription is where it would
        // be most tempting to bend: the first payment still prices itself from
        // the catalogue, and a plan's amount comes from there too.
        $result = app(Checkout::class)->start('mitgliedschaft', ['email' => 'k@example.com']);

        $this->assertSame(1900, $result->payment->amount_cent);
    }
}
