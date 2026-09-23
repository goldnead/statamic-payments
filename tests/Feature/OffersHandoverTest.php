<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicOffers\Offers;
use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Integrations\EntitlementsBridge;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Discount;
use Goldnead\StatamicPayments\Support\DiscountSplit;
use Goldnead\StatamicPayments\Support\SubscriptionPauses;
use Goldnead\StatamicPayments\Support\Subscriptions;
use Goldnead\StatamicPayments\Tests\Support\FakeGateway;
use Goldnead\StatamicPayments\Tests\Support\PausingFakeGateway;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * What statamic-offers 3d6d85b handed over (report-offers.md, „Übergabe payments"):
 *
 * - O6: a coupon that also covers the charges after the first
 * - O3: the country rule, checked a second time at the checkout
 * - O2: a setup fee carries no share of a coupon
 */
class OffersHandoverTest extends TestCase
{
    protected PausingFakeGateway $nativ;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.follow_up.collect_mandate', true);
        $app['config']->set('statamic-payments.products', [
            'mitgliedschaft' => ['name' => 'Mitgliedschaft', 'amount_cent' => 2000, 'interval' => '1 month'],
            'kurs' => ['name' => 'Kurs', 'amount_cent' => 10000],
            'einrichtung' => ['name' => 'Einrichtung', 'amount_cent' => 5000, 'setup_fee' => true],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__.'/../Fakes/offers-facade.php';
        Offers::$notIn = [];

        $this->gateway = $this->nativ = new PausingFakeGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    protected function tearDown(): void
    {
        Offers::$notIn = [];
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------- O6: later charges

    /** @param array<string, mixed> $coupon */
    protected function abonniereMit(array $coupon): Subscription
    {
        $result = app(Subscriptions::class)->start(
            'mitgliedschaft',
            ['email' => 'k@example.com'],
            null,
            ['meta' => ['coupon' => $coupon]],
            new Discount($coupon['code'], 400),
        );

        $this->assertNotNull($result);
        $this->gateway->markPaid($result->payment->provider_id);
        $this->postJson(route('statamic-payments.webhook'), ['id' => $result->payment->provider_id])->assertOk();

        return Subscription::sole();
    }

    protected function zyklus(Subscription $abo): Payment
    {
        $id = $this->gateway->arrive('mitgliedschaft', 2000, $abo->provider_id);
        $this->postJson(route('statamic-payments.webhook'), ['id' => $id])->assertOk();

        return Payment::query()->where('provider_id', $id)->sole();
    }

    #[Test]
    public function a_repeating_coupon_lowers_the_charges_it_covers_and_then_ends(): void
    {
        $abo = $this->abonniereMit(['code' => 'CHOR20', 'percent' => 20, 'amount_cent' => null, 'currency' => null, 'duration' => 'repeating', 'cycles' => 3]);

        // The agreement is created at the lowered amount: charge 2 is covered.
        $this->assertSame('16.00', $this->gateway->lastSubscriptionPayload['amount']['value']);
        $this->assertSame(2000, $abo->amount_cent, 'the price of the agreement itself changed');
        $this->assertSame('CHOR20', $abo->meta['coupon']['code']);
        $this->assertSame(400, $abo->meta['coupon']['current_discount_cent']);

        $zwei = $this->zyklus($abo);
        $this->assertSame(1600, $zwei->amount_cent);
        $this->assertSame('CHOR20', $zwei->discount_code);
        $this->assertSame(400, $zwei->discount_cent);
        $this->assertSame([], $this->nativ->updated, 'charge 3 is still covered; nothing to change');

        $drei = $this->zyklus($abo->fresh());
        $this->assertSame(1600, $drei->amount_cent);

        // Charge 4 is not covered any more: the agreement goes back to full price.
        $this->assertCount(1, $this->nativ->updated);
        $this->assertSame('20.00', $this->nativ->updated[0]['payload']['amount']['value']);
        $this->assertSame(0, $abo->fresh()->meta['coupon']['current_discount_cent']);

        $vier = $this->zyklus($abo->fresh());
        $this->assertSame(2000, $vier->amount_cent);
        $this->assertNull($vier->discount_code);
    }

    #[Test]
    public function a_once_coupon_leaves_the_later_charges_alone(): void
    {
        $abo = $this->abonniereMit(['code' => 'EINMAL', 'percent' => 20, 'duration' => 'once', 'cycles' => null]);

        $this->assertSame('20.00', $this->gateway->lastSubscriptionPayload['amount']['value']);
        $this->assertSame(2000, $this->zyklus($abo)->amount_cent);
    }

    #[Test]
    public function a_forever_coupon_stays_and_is_never_repriced(): void
    {
        $abo = $this->abonniereMit(['code' => 'IMMER', 'percent' => null, 'amount_cent' => 500, 'currency' => 'EUR', 'duration' => 'forever', 'cycles' => null]);

        $this->assertSame('15.00', $this->gateway->lastSubscriptionPayload['amount']['value']);
        $this->zyklus($abo);
        $this->zyklus($abo->fresh());

        $this->assertSame([], $this->nativ->updated);
    }

    #[Test]
    public function a_pause_on_a_provider_without_one_keeps_the_coupon(): void
    {
        $abo = $this->abonniereMit(['code' => 'CHOR20', 'percent' => 20, 'duration' => 'repeating', 'cycles' => 3]);

        // The plain fake has no native pause, like Mollie: the agreement is
        // created again on resume, and must be created at the lowered price.
        $plain = new FakeGateway;
        $plain->subscriptions = $this->gateway->subscriptions;
        $this->gateway = $plain;
        $this->app->instance(PaymentGateway::class, $plain);

        $pauses = app(SubscriptionPauses::class);
        $this->assertTrue($pauses->pause($abo->fresh()));
        $this->assertTrue($pauses->resume($abo->fresh()));

        $this->assertSame('16.00', $plain->lastSubscriptionPayload['amount']['value']);
    }

    #[Test]
    public function the_invoice_line_of_a_discounted_charge_says_so(): void
    {
        $abo = $this->abonniereMit(['code' => 'CHOR20', 'percent' => 20, 'duration' => 'repeating', 'cycles' => 3]);

        $item = PaymentItem::query()->where('payment_id', $this->zyklus($abo)->getKey())->sole();

        $this->assertSame(2000, $item->amount_cent);
        $this->assertSame(400, $item->discount_cent);
    }

    // -------------------------------------------------- O3: country rule

    #[Test]
    public function the_checkout_refuses_an_offer_that_is_not_sold_in_the_buyers_country(): void
    {
        Offers::$notIn = ['kurs' => ['CH']];

        $this->assertNull(app(Checkout::class)->start('kurs', ['email' => 'k@example.com', 'country' => 'CH']));
        $this->assertSame(0, Payment::count());

        $this->assertNotNull(app(Checkout::class)->start('kurs', ['email' => 'k@example.com', 'country' => 'DE']));
    }

    #[Test]
    public function a_subscription_is_refused_the_same_way(): void
    {
        Offers::$notIn = ['mitgliedschaft' => ['AT']];

        $this->assertNull(app(Subscriptions::class)->start('mitgliedschaft', ['email' => 'k@example.com', 'country' => 'AT']));
        $this->assertSame(0, Payment::count());
    }

    // --------------------------------------------------- O2: setup fee

    #[Test]
    public function a_setup_fee_carries_no_share_of_a_coupon(): void
    {
        $lines = [
            ['amount_cent' => 10000, 'quantity' => 1],
            ['amount_cent' => 5000, 'quantity' => 1, 'setup_fee' => true],
        ];

        $this->assertSame([1500, 0], DiscountSplit::across($lines, 1500));
    }

    #[Test]
    public function at_the_checkout_the_coupon_is_measured_against_what_it_may_reduce(): void
    {
        $result = app(Checkout::class)->start(['kurs', 'einrichtung'], ['email' => 'k@example.com'], null, new Discount('ALLES', 20000));

        // The coupon may take the course to zero, never the fee.
        $this->assertSame(5000, $result->payment->amount_cent);
        $this->assertSame(10000, $result->payment->discount_cent);

        $items = $result->payment->items()->orderBy('id')->get();
        $this->assertSame([10000, 0], $items->pluck('discount_cent')->all());
    }

    // ------------------------------------- the last piece of a limited offer

    #[Test]
    public function the_buyer_of_the_last_piece_still_gets_access(): void
    {
        Catalogue::forgetResolvers();
        $ausverkauft = false;

        // An offer with a quantity limit: resolvable until the last piece is
        // paid, not afterwards. That is what statamic-offers does.
        Catalogue::extend(function (string $handle) use (&$ausverkauft) {
            return $handle === 'offer:letzter' && ! $ausverkauft
                ? ['name' => 'Letzter Platz', 'amount_cent' => 9900, 'grants' => 'workshop', 'offer' => 'letzter']
                : null;
        });

        $bruecke = new class extends EntitlementsBridge
        {
            public array $vergeben = [];

            public function available(): bool
            {
                return true;
            }

            protected function grantSlug(Payment $payment, string $slug, string $subject, ?Carbon $startsAt, ?Carbon $expiresAt): void
            {
                $this->vergeben[] = $slug;
            }
        };
        $this->app->instance(EntitlementsBridge::class, $bruecke);

        $result = app(Checkout::class)->start('offer:letzter', ['email' => 'k@example.com']);
        $ausverkauft = true;

        $this->gateway->markPaid($result->payment->provider_id);
        $this->postJson(route('statamic-payments.webhook'), ['id' => $result->payment->provider_id])->assertOk();

        $this->assertSame(['workshop'], $bruecke->vergeben, 'the last buyer paid and got nothing');

        Catalogue::forgetResolvers();
    }
}
