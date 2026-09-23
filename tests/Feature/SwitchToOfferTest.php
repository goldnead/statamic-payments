<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Support\SubscriptionSwitches;
use Goldnead\StatamicPayments\Tests\Support\PausingFakeGateway;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * Offers as switch targets (adg staging, 1.25.0-rc.1).
 *
 * `targetsFor()` listed only configured products, so a subscription bought
 * through an offer never had a target in the CP, and `switch()` returned false
 * without a word.
 */
class SwitchToOfferTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'abo' => ['name' => 'Abo', 'amount_cent' => 1900, 'interval' => '1 month'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(Offer::class)) {
            require_once __DIR__.'/../Support/OfferModelStandIn.php';
        }

        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->string('handle')->unique();
            $table->string('product');
            $table->unsignedBigInteger('brand_id')->default(0);
            $table->boolean('active')->default(true);
            $table->json('pricing_options')->nullable();
            $table->timestamps();
        });

        // What statamic-offers' resolver answers: the offer's own plan and price.
        $offers = [
            'offer:klein' => ['name' => 'Klein', 'amount_cent' => 1900, 'interval' => '1 month', 'brand' => 0],
            'offer:gross' => ['name' => 'Gross', 'amount_cent' => 2900, 'interval' => '1 month', 'brand' => 0],
            'offer:gross:jahr' => ['name' => 'Gross jährlich', 'amount_cent' => 29000, 'interval' => '1 year', 'brand' => 0],
            'offer:fremd' => ['name' => 'Fremd', 'amount_cent' => 3900, 'interval' => '1 month', 'brand' => 7],
            'offer:aus' => ['name' => 'Aus', 'amount_cent' => 3900, 'interval' => '1 month', 'brand' => 0],
        ];
        Catalogue::extend(fn (string $handle) => $offers[$handle] ?? null);

        $model = Offer::class;
        $model::query()->create(['handle' => 'klein', 'product' => 'abo', 'brand_id' => 0]);
        $model::query()->create(['handle' => 'gross', 'product' => 'abo', 'brand_id' => 0,
            'pricing_options' => [['key' => 'jahr', 'interval' => '1 year']]]);
        $model::query()->create(['handle' => 'fremd', 'product' => 'abo', 'brand_id' => 7]);
        $model::query()->create(['handle' => 'aus', 'product' => 'abo', 'brand_id' => 0, 'active' => false]);

        Carbon::setTestNow('2026-09-23 10:00:00');
        $this->gateway = new PausingFakeGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Catalogue::forgetResolvers();

        parent::tearDown();
    }

    protected function abo(): Subscription
    {
        $this->gateway->subscriptions['sub_1'] = ['customer' => 'cst_1', 'status' => 'active'];
        $this->gateway->mandates[] = 'cst_1';

        return Subscription::create([
            'provider' => 'fake', 'provider_id' => 'sub_1', 'customer_reference' => 'cst_1',
            'product' => 'offer:klein', 'amount_cent' => 1900, 'currency' => 'EUR',
            'interval' => '1 month', 'times_charged' => 1, 'status' => Subscription::STATUS_ACTIVE,
            'next_payment_at' => Carbon::parse('2026-10-05'), 'email' => 'wer@example.com',
        ]);
    }

    #[Test]
    public function an_offer_of_the_same_brand_rhythm_and_currency_is_a_target(): void
    {
        $this->assertSame(
            ['abo' => 'Abo', 'offer:gross' => 'Gross'],
            app(SubscriptionSwitches::class)->targetsFor($this->abo()),
        );
    }

    #[Test]
    public function a_subscription_bought_through_an_offer_switches_to_another_offer(): void
    {
        $abo = $this->abo();

        $this->assertTrue(app(SubscriptionSwitches::class)->switch($abo, 'offer:gross'));
        $this->assertSame('offer:gross', $abo->fresh()->product);
    }

    #[Test]
    public function a_switch_that_does_not_happen_says_why(): void
    {
        Log::spy();

        $this->assertFalse(app(SubscriptionSwitches::class)->switch($this->abo(), 'offer:fremd'));

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => str_contains($message, 'switch')
            && ($context['reason'] ?? null) === 'not a target');
    }
}
