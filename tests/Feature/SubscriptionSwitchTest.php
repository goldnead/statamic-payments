<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Events\SubscriptionChanged;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\SubscriptionSwitches;
use Goldnead\StatamicPayments\Tests\Support\PausingFakeGateway;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * Switching between products with the difference charged pro rata (P2).
 *
 * The period in these tests runs from 5 September to 5 October, 30 days, and
 * "now" is 23 September, 10:00. What is left is 11 days and 14 hours:
 * 1000 cents × (11.583 / 30) = 386 cents for an upgrade from 19 to 29 euro.
 */
class SubscriptionSwitchTest extends TestCase
{
    protected PausingFakeGateway $nativ;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'basis' => ['name' => 'Basis', 'amount_cent' => 1900, 'interval' => '1 month', 'switch_to' => ['plus']],
            'plus' => ['name' => 'Plus', 'amount_cent' => 2900, 'interval' => '1 month', 'switch_to' => ['basis']],
            'klein' => ['name' => 'Klein', 'amount_cent' => 900, 'interval' => '1 month'],
            'jahr' => ['name' => 'Jahr', 'amount_cent' => 19000, 'interval' => '1 year'],
            'raten' => ['name' => 'Raten', 'amount_cent' => 5000, 'interval' => '1 month', 'times' => 3],
            'einmal' => ['name' => 'Einmal', 'amount_cent' => 900],
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

    protected function nativ(): PausingFakeGateway
    {
        $this->gateway = $this->nativ = new PausingFakeGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        return $this->nativ;
    }

    protected function abo(array $werte = []): Subscription
    {
        $this->gateway->subscriptions['sub_1'] = ['customer' => 'cst_1', 'status' => 'active'];
        // The stored card the difference is charged against.
        $this->gateway->mandates[] = 'cst_1';

        return Subscription::create(array_merge([
            'provider' => 'fake', 'provider_id' => 'sub_1', 'customer_reference' => 'cst_1',
            'product' => 'basis', 'amount_cent' => 1900, 'currency' => 'EUR',
            'interval' => '1 month', 'times_charged' => 1, 'status' => Subscription::STATUS_ACTIVE,
            'next_payment_at' => Carbon::parse('2026-10-05 00:00'),
            'email' => 'wer@example.com', 'name' => 'Wer',
        ], $werte));
    }

    protected function switches(): SubscriptionSwitches
    {
        return app(SubscriptionSwitches::class);
    }

    #[Test]
    public function an_upgrade_charges_the_rest_of_the_period_and_applies_at_once(): void
    {
        Event::fake([SubscriptionChanged::class]);
        $gateway = $this->nativ();
        $abo = $this->abo();

        $this->assertTrue($this->switches()->switch($abo, 'plus', 'portal'));

        $differenz = Payment::query()->whereNotNull('meta->subscription_change')->sole();
        $this->assertSame(386, $differenz->amount_cent);
        $this->assertTrue($differenz->meta['proration'], 'insights would read the difference as a new price');
        $this->assertSame('plus', $differenz->product);
        $this->assertSame('cst_1', $differenz->customer_reference);
        $this->assertSame(Payment::STATUS_OPEN, $differenz->status, 'the difference is settled by the webhook, not assumed');

        $this->assertSame('29.00', $gateway->updated[0]['payload']['amount']['value']);

        $abo->refresh();
        $this->assertSame('plus', $abo->product);
        $this->assertSame(2900, $abo->amount_cent);
        $this->assertSame('2026-10-05', $abo->next_payment_at->toDateString(), 'the billing day moved');

        Event::assertDispatched(SubscriptionChanged::class, fn ($e) => $e->immediate
            && $e->prorationCent === 386
            && $e->prorationPayment?->is($differenz)
            && $e->fromProduct === 'basis' && $e->toProduct === 'plus'
            && $e->by === 'portal');
    }

    #[Test]
    public function a_downgrade_charges_nothing_and_applies_from_the_next_charge(): void
    {
        Event::fake([SubscriptionChanged::class]);
        $gateway = $this->nativ();
        $abo = $this->abo(['product' => 'plus', 'amount_cent' => 2900]);

        $this->assertTrue($this->switches()->switch($abo, 'basis'));

        $this->assertSame(0, Payment::count());
        $this->assertSame('19.00', $gateway->updated[0]['payload']['amount']['value']);
        $this->assertSame(1900, $abo->fresh()->amount_cent);

        Event::assertDispatched(SubscriptionChanged::class, fn ($e) => ! $e->immediate && $e->prorationCent === 0);
    }

    #[Test]
    public function without_a_change_in_place_the_agreement_is_restarted_on_the_same_day(): void
    {
        $this->gateway->subscriptionsCreated = 1;
        $abo = $this->abo();

        $this->assertTrue($this->switches()->switch($abo, 'plus'));

        $this->assertSame(['sub_1'], $this->gateway->cancelled);
        $this->assertSame('2026-10-05', $this->gateway->lastSubscriptionPayload['startDate']);
        $this->assertSame('29.00', $this->gateway->lastSubscriptionPayload['amount']['value']);
        $this->assertSame('sub_2', $abo->fresh()->provider_id);
    }

    #[Test]
    public function a_refused_difference_changes_nothing(): void
    {
        $gateway = $this->nativ();
        $abo = $this->abo();
        $gateway->refuseFollowUp = true;

        $this->assertFalse($this->switches()->switch($abo, 'plus'));

        $this->assertSame('basis', $abo->fresh()->product);
        $this->assertSame([], $gateway->updated, 'the new amount was agreed while its difference was never paid');
        $this->assertSame(Payment::STATUS_FAILED, Payment::sole()->status);
    }

    #[Test]
    public function a_difference_below_the_floor_is_not_charged(): void
    {
        $this->nativ();
        Carbon::setTestNow('2026-10-04 20:00:00');

        // 1000 × (4 hours / 30 days) = 5 cents.
        $this->assertTrue($this->switches()->switch($this->abo(), 'plus'));
        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function only_products_with_the_same_rhythm_are_targets(): void
    {
        $abo = $this->abo();

        $this->assertSame(['plus' => 'Plus', 'klein' => 'Klein'], $this->switches()->targetsFor($abo));
        $this->assertFalse($this->switches()->switch($abo, 'jahr'));
        $this->assertFalse($this->switches()->switch($abo, 'raten'));
        $this->assertFalse($this->switches()->switch($abo, 'einmal'));
        $this->assertFalse($this->switches()->switch($abo, 'basis'));
    }

    #[Test]
    public function the_portal_offers_only_what_the_product_lists_and_only_when_allowed(): void
    {
        $abo = $this->abo();

        $this->assertSame([], $this->switches()->targetsFor($abo, portal: true));

        config(['statamic-payments.portal.allow_switch' => true]);

        $this->assertSame(['plus' => 'Plus'], $this->switches()->targetsFor($abo, portal: true));
    }

    #[Test]
    public function a_plan_is_not_switched(): void
    {
        $abo = $this->abo(['product' => 'raten', 'times' => 2, 'amount_cent' => 5000]);

        $this->assertSame([], $this->switches()->targetsFor($abo));
    }
}
