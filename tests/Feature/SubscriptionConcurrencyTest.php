<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\SubscriptionPauses;
use Goldnead\StatamicPayments\Support\SubscriptionSwitches;
use Goldnead\StatamicPayments\Tests\Support\PausingFakeGateway;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * Two requests about the same agreement at the same moment (Gauntlet 2, point 1).
 *
 * Two copies of one row are loaded before either acts, which is what two
 * browser tabs, a double click or the scheduler next to a portal visit look
 * like to this code. Only one of them may reach the provider.
 */
class SubscriptionConcurrencyTest extends TestCase
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

    protected function abo(): Subscription
    {
        $this->gateway->subscriptions['sub_1'] = ['customer' => 'cst_1', 'status' => 'active'];
        $this->gateway->mandates[] = 'cst_1';

        return Subscription::create([
            'provider' => 'fake', 'provider_id' => 'sub_1', 'customer_reference' => 'cst_1',
            'product' => 'basis', 'amount_cent' => 1900, 'currency' => 'EUR',
            'interval' => '1 month', 'times_charged' => 1, 'status' => Subscription::STATUS_ACTIVE,
            'next_payment_at' => Carbon::parse('2026-10-05'),
            'email' => 'wer@example.com',
        ]);
    }

    /** @return array{0: Subscription, 1: Subscription} */
    protected function zweiKopien(Subscription $abo): array
    {
        return [Subscription::findOrFail($abo->getKey()), Subscription::findOrFail($abo->getKey())];
    }

    #[Test]
    public function two_resumes_at_once_start_one_agreement(): void
    {
        $pauses = app(SubscriptionPauses::class);
        $this->gateway->subscriptionsCreated = 1;
        $abo = $this->abo();
        $pauses->pause($abo);

        [$a, $b] = $this->zweiKopien($abo);

        $this->assertTrue($pauses->resume($a));
        $this->assertFalse($pauses->resume($b), 'the second copy reached the provider too');
        $this->assertSame(2, $this->gateway->subscriptionsCreated, 'two agreements at Mollie charge the buyer twice a month');
        // And the provider is told it is one request, should it arrive twice.
        $this->assertStringStartsWith('statamic-payments-resume-'.$abo->getKey().'-', $this->gateway->lastSubscriptionPayload['idempotencyKey'] ?? '');
    }

    #[Test]
    public function two_pauses_at_once_pause_once(): void
    {
        $pauses = app(SubscriptionPauses::class);
        [$a, $b] = $this->zweiKopien($this->abo());

        $this->assertTrue($pauses->pause($a));
        $this->assertFalse($pauses->pause($b));
        $this->assertSame(['sub_1'], $this->gateway->cancelled);
    }

    #[Test]
    public function a_resume_the_provider_refuses_stays_paused_and_can_be_tried_again(): void
    {
        $pauses = app(SubscriptionPauses::class);
        $abo = $this->abo();
        $pauses->pause($abo);

        $this->gateway->refuseThisSubscription = true;
        $this->assertFalse($pauses->resume($abo->fresh()));
        $this->assertSame(Subscription::STATUS_PAUSED, $abo->fresh()->status, 'the claim was not given back');

        $this->gateway->refuseThisSubscription = false;
        $this->assertTrue($pauses->resume($abo->fresh()));
    }

    #[Test]
    public function two_switches_at_once_charge_the_difference_once(): void
    {
        $gateway = new PausingFakeGateway;
        $this->gateway = $gateway;
        $this->app->instance(PaymentGateway::class, $gateway);

        $switches = app(SubscriptionSwitches::class);
        [$a, $b] = $this->zweiKopien($this->abo());

        $this->assertTrue($switches->switch($a, 'plus'));
        $this->assertFalse($switches->switch($b, 'plus'));

        $this->assertSame(1, Payment::query()->whereNotNull('meta->subscription_change')->count());
        $this->assertCount(1, $gateway->updated);
    }

    #[Test]
    public function a_refused_difference_gives_the_claim_back(): void
    {
        $gateway = new PausingFakeGateway;
        $this->gateway = $gateway;
        $this->app->instance(PaymentGateway::class, $gateway);
        $abo = $this->abo();

        $gateway->refuseFollowUp = true;
        $this->assertFalse(app(SubscriptionSwitches::class)->switch($abo, 'plus'));
        $this->assertSame('basis', $abo->fresh()->product);
        $this->assertSame(1900, $abo->fresh()->amount_cent);

        $gateway->refuseFollowUp = false;
        $this->assertTrue(app(SubscriptionSwitches::class)->switch($abo->fresh(), 'plus'));
    }

    #[Test]
    public function every_documented_schedule_line_says_without_overlapping(): void
    {
        $quellen = [
            file_get_contents(__DIR__.'/../../config/statamic-payments.php'),
            file_get_contents(__DIR__.'/../../README.md'),
            ...array_map('file_get_contents', glob(__DIR__.'/../../src/Console/Commands/*.php')),
        ];

        foreach ($quellen as $text) {
            preg_match_all('/Schedule::command\([^\n]*/', (string) $text, $treffer);

            foreach ($treffer[0] as $zeile) {
                $this->assertStringContainsString('withoutOverlapping()', $zeile, $zeile);
            }
        }
    }
}
