<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Events\SubscriptionCancelled;
use Goldnead\StatamicPayments\Events\SubscriptionReplaced;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Subscriptions;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * A purchase that ends another agreement of the same buyer (P4).
 *
 * The old monthly membership runs 5 September to 5 October at 19 euro; on
 * 23 September, 10:00, 11.583 of its 30 days are left: 734 cents unused. The
 * yearly one costs 190 euro for 365 days, 52 cents a day: 14 days of credit.
 */
class SubscriptionReplaceTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.follow_up.collect_mandate', true);
        $app['config']->set('statamic-payments.products', [
            'monatsabo' => ['name' => 'Monatsabo', 'amount_cent' => 1900, 'interval' => '1 month'],
            'jahresabo' => [
                'name' => 'Jahresabo', 'amount_cent' => 19000, 'interval' => '1 year',
                'replaces' => ['monatsabo'],
            ],
            'ohne-anrechnung' => [
                'name' => 'Jahresabo', 'amount_cent' => 19000, 'interval' => '1 year',
                'replaces' => 'monatsabo', 'replaces_credit' => false,
            ],
            'lebenslang' => ['name' => 'Lebenslang', 'amount_cent' => 49000, 'replaces' => ['monatsabo']],
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

    protected function altesAbo(string $email = 'k@example.com'): Subscription
    {
        $this->gateway->subscriptions['sub_alt'] = ['customer' => 'cst_alt', 'status' => 'active'];

        $abo = Subscription::create([
            'provider' => 'fake', 'provider_id' => 'sub_alt', 'customer_reference' => 'cst_alt',
            'product' => 'monatsabo', 'amount_cent' => 1900, 'currency' => 'EUR',
            'interval' => '1 month', 'times_charged' => 1, 'status' => Subscription::STATUS_ACTIVE,
            'next_payment_at' => Carbon::parse('2026-10-05 00:00'),
            'email' => $email,
        ]);

        // Paid for the current period: that is what makes the rest worth something.
        Payment::create([
            'provider' => 'fake', 'provider_id' => 'tr_alt', 'product' => 'monatsabo',
            'amount_cent' => 1900, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
            'paid_at' => Carbon::parse('2026-09-05 00:00'), 'subscription_id' => $abo->getKey(),
            'email' => $email,
        ]);

        return $abo;
    }

    protected function kaufe(string $product, string $email = 'K@Example.com'): Payment
    {
        $result = str_contains($product, 'abo') || $product === 'ohne-anrechnung'
            ? app(Subscriptions::class)->start($product, ['email' => $email])
            : app(Checkout::class)->start($product, ['email' => $email]);

        $this->assertNotNull($result);
        $this->gateway->markPaid($result->payment->provider_id);
        $this->postJson(route('statamic-payments.webhook'), ['id' => $result->payment->provider_id])->assertOk();

        return $result->payment->fresh();
    }

    #[Test]
    public function the_new_agreement_ends_the_old_one_and_starts_later_by_what_was_left(): void
    {
        Event::fake([SubscriptionReplaced::class, SubscriptionCancelled::class]);
        $alt = $this->altesAbo();

        $kauf = $this->kaufe('jahresabo');

        $this->assertSame(Subscription::STATUS_CANCELLED, $alt->fresh()->status);
        $this->assertContains('sub_alt', $this->gateway->cancelled);

        $neu = Subscription::query()->where('product', 'jahresabo')->sole();
        // One year from today, plus 14 days of credit.
        $this->assertSame('2027-10-07', $this->gateway->lastSubscriptionPayload['startDate']);
        $this->assertSame(['cent' => 734, 'days' => 14, 'from' => [$alt->getKey()]], $neu->meta['credit']);

        Event::assertDispatched(SubscriptionReplaced::class, fn ($e) => $e->replaced->is($alt)
            && $e->purchase->is($kauf)
            && $e->replacement?->is($neu)
            && $e->creditCent === 734
            && $e->creditDays === 14);
        Event::assertDispatched(SubscriptionCancelled::class);
    }

    #[Test]
    public function without_credit_the_new_one_starts_as_usual(): void
    {
        $alt = $this->altesAbo();

        $this->kaufe('ohne-anrechnung');

        $this->assertSame(Subscription::STATUS_CANCELLED, $alt->fresh()->status);
        $this->assertSame('2027-09-23', $this->gateway->lastSubscriptionPayload['startDate']);
    }

    #[Test]
    public function a_one_off_purchase_ends_the_old_one_without_credit(): void
    {
        Event::fake([SubscriptionReplaced::class]);
        $alt = $this->altesAbo();

        $this->kaufe('lebenslang');

        $this->assertSame(Subscription::STATUS_CANCELLED, $alt->fresh()->status);
        Event::assertDispatched(SubscriptionReplaced::class, fn ($e) => $e->replacement === null && $e->creditDays === 0);
    }

    #[Test]
    public function somebody_else_keeps_their_agreement(): void
    {
        $fremd = $this->altesAbo('andere@example.com');

        $this->kaufe('jahresabo');

        $this->assertSame(Subscription::STATUS_ACTIVE, $fremd->fresh()->status);
        $this->assertSame([], $this->gateway->cancelled);
    }

    #[Test]
    public function a_product_that_replaces_nothing_ends_nothing(): void
    {
        $alt = $this->altesAbo();

        $this->kaufe('monatsabo');

        $this->assertSame(Subscription::STATUS_ACTIVE, $alt->fresh()->status);
    }
}
