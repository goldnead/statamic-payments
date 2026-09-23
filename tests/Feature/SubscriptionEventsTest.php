<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Events\SubscriptionAttemptFailed;
use Goldnead\StatamicPayments\Events\SubscriptionEnded;
use Goldnead\StatamicPayments\Events\SubscriptionPlanCompleted;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * The two new events that come out of the webhook path (P6): the n-th failed
 * charge in a row, and a plan paid to its last instalment. The other new events
 * are covered where they are fired (pause, switch, reminders, replace).
 */
class SubscriptionEventsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'mitgliedschaft' => ['name' => 'Mitgliedschaft', 'amount_cent' => 1900, 'interval' => '1 month'],
            'raten' => ['name' => 'Raten', 'amount_cent' => 5000, 'interval' => '1 month', 'times' => 3],
        ]);
    }

    protected function abo(array $werte = []): Subscription
    {
        $this->gateway->subscriptions['sub_1'] = ['customer' => 'cst_1', 'status' => 'active'];

        return Subscription::create(array_merge([
            'provider' => 'fake', 'provider_id' => 'sub_1', 'customer_reference' => 'cst_1',
            'product' => 'mitgliedschaft', 'amount_cent' => 1900, 'currency' => 'EUR',
            'interval' => '1 month', 'times_charged' => 1, 'status' => Subscription::STATUS_ACTIVE,
            'next_payment_at' => Carbon::now()->addDays(3),
            'email' => 'wer@example.com',
        ], $werte));
    }

    protected function failedCycle(): string
    {
        $id = $this->gateway->arrive('mitgliedschaft', 1900, 'sub_1');
        $this->gateway->markFailedCycle($id, 'sub_1');
        $this->postJson(route('statamic-payments.webhook'), ['id' => $id])->assertOk();

        return $id;
    }

    protected function paidCycle(string $product = 'mitgliedschaft', int $cent = 1900): string
    {
        $id = $this->gateway->arrive($product, $cent, 'sub_1');
        $this->postJson(route('statamic-payments.webhook'), ['id' => $id])->assertOk();

        return $id;
    }

    #[Test]
    public function each_failed_charge_counts_once_and_a_paid_one_starts_again(): void
    {
        Event::fake([SubscriptionAttemptFailed::class]);
        $this->abo();

        $erste = $this->failedCycle();
        // The provider says the same thing about the same payment again.
        $this->postJson(route('statamic-payments.webhook'), ['id' => $erste])->assertOk();
        $this->travel(1)->days();
        $this->failedCycle();

        $attempts = [];
        Event::assertDispatched(SubscriptionAttemptFailed::class, function ($e) use (&$attempts) {
            $attempts[] = $e->attempt;

            return true;
        });
        $this->assertSame([1, 2], $attempts);

        $this->travel(1)->days();
        $this->paidCycle();
        $this->travel(1)->days();
        $this->failedCycle();

        $this->assertSame(1, Event::dispatched(SubscriptionAttemptFailed::class)->last()[0]->attempt,
            'a paid charge in between did not start the count again');
    }

    #[Test]
    public function the_last_instalment_announces_a_completed_plan_once(): void
    {
        Event::fake([SubscriptionPlanCompleted::class, SubscriptionEnded::class]);
        $this->abo(['product' => 'raten', 'amount_cent' => 5000, 'times' => 2, 'times_charged' => 1]);

        $id = $this->paidCycle('raten', 5000);
        $this->postJson(route('statamic-payments.webhook'), ['id' => $id])->assertOk();

        Event::assertDispatchedTimes(SubscriptionPlanCompleted::class, 1);
        Event::assertDispatched(SubscriptionPlanCompleted::class, fn ($e) => $e->subscription->status === Subscription::STATUS_COMPLETED
            && $e->payment->provider_id === $id);
        Event::assertDispatchedTimes(SubscriptionEnded::class, 1);
    }

    #[Test]
    public function a_cycle_that_is_not_the_last_completes_nothing(): void
    {
        Event::fake([SubscriptionPlanCompleted::class]);
        $this->abo(['product' => 'raten', 'amount_cent' => 5000, 'times' => 2, 'times_charged' => 0]);

        $this->paidCycle('raten', 5000);

        Event::assertNotDispatched(SubscriptionPlanCompleted::class);
    }
}
