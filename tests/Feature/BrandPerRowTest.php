<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\BrandContext\ServiceProvider as BrandContextServiceProvider;
use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Events\SubscriptionCancelled;
use Goldnead\StatamicPayments\Events\SubscriptionPaymentUpcoming;
use Goldnead\StatamicPayments\Events\SubscriptionResumed;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\SubscriptionPauses;
use Goldnead\StatamicPayments\Tests\Support\PausingFakeGateway;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Commands and webhooks run under the brand of the row they work on
 * (Gauntlet 23.09.2026). Without it every listener hears the default brand:
 * automations of brand 2 answered for brand 1.
 */
class BrandPerRowTest extends TestCase
{
    /** Brand heard by the listener, per event class. */
    protected array $heard = [];

    protected int $brand;

    protected function getPackageProviders($app): array
    {
        return array_merge([BrandContextServiceProvider::class], parent::getPackageProviders($app));
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('brand-context.multi_brand', true);
        $app['config']->set('statamic-payments.reminders.upcoming.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(
            dirname((new ReflectionClass(BrandContextServiceProvider::class))->getFileName(), 2).'/database/migrations'
        );
        $this->artisan('migrate')->run();

        $this->brand = (int) DB::table('brands')->insertGetId([
            'handle' => 'zweite', 'name' => 'Zweite', 'is_default' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Carbon::setTestNow('2026-09-23 09:00:00');

        $this->gateway = new PausingFakeGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        foreach ([PaymentPaid::class, SubscriptionCancelled::class, SubscriptionPaymentUpcoming::class, SubscriptionResumed::class] as $event) {
            Event::listen($event, fn () => $this->heard[$event] = app('brand-context')->current()->id);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function abo(array $werte = []): Subscription
    {
        $id = 'sub_'.uniqid();
        $this->gateway->subscriptions[$id] = ['customer' => 'cst_1', 'status' => 'active'];
        $this->gateway->mandates[] = 'cst_1';

        return Subscription::create(array_merge([
            'provider' => 'fake', 'provider_id' => $id, 'customer_reference' => 'cst_1',
            'product' => 'mitgliedschaft', 'amount_cent' => 1900, 'currency' => 'EUR',
            'interval' => '1 month', 'times_charged' => 1, 'status' => Subscription::STATUS_ACTIVE,
            'next_payment_at' => Carbon::parse('2026-09-28 00:00'),
            'email' => 'wer@example.com', 'brand_id' => $this->brand,
        ], $werte));
    }

    #[Test]
    public function the_webhook_fulfils_under_the_brand_of_the_payment(): void
    {
        $session = $this->gateway->createPayment(['amount_cent' => 1900]);
        Payment::create([
            'provider' => 'fake', 'provider_id' => $session->providerId, 'product' => 'mitgliedschaft',
            'amount_cent' => 1900, 'currency' => 'EUR', 'status' => Payment::STATUS_OPEN,
            'email' => 'wer@example.com', 'brand_id' => $this->brand,
        ]);
        $this->gateway->markStatus($session->providerId, Payment::STATUS_PAID);

        $this->postJson('/!/statamic-payments/webhook', ['id' => $session->providerId])->assertOk();

        $this->assertSame($this->brand, $this->heard[PaymentPaid::class] ?? null);
        $this->assertSame(1, app('brand-context')->current()->id, 'the brand is given back afterwards');
    }

    #[Test]
    public function reminders_are_sent_under_the_brand_of_the_agreement(): void
    {
        $this->abo();

        $this->artisan('payments:reminders')->assertSuccessful();

        $this->assertSame($this->brand, $this->heard[SubscriptionPaymentUpcoming::class] ?? null);
    }

    #[Test]
    public function the_schedule_resumes_under_the_brand_of_the_agreement(): void
    {
        $abo = $this->abo();
        app(SubscriptionPauses::class)->pause($abo, Carbon::parse('2026-10-01'));

        Carbon::setTestNow('2026-10-01 06:00:00');
        $this->artisan('payments:resume-paused')->assertSuccessful();

        $this->assertSame($this->brand, $this->heard[SubscriptionResumed::class] ?? null);
    }

    #[Test]
    public function the_sweep_cancels_a_noted_cancellation_under_the_brand_of_the_agreement(): void
    {
        $this->abo(['meta' => ['cancel_requested' => ['at' => '2026-09-23T08:00:00+00:00']]]);

        $this->artisan('payments:resume-paused')->assertSuccessful();

        $this->assertSame($this->brand, $this->heard[SubscriptionCancelled::class] ?? null);
    }

    #[Test]
    public function brand_zero_runs_as_before(): void
    {
        $this->abo(['brand_id' => 0]);

        $this->artisan('payments:reminders')->assertSuccessful();

        $this->assertSame(1, $this->heard[SubscriptionPaymentUpcoming::class] ?? null);
    }
}
