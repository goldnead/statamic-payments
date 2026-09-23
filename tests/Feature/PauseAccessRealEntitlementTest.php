<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\IdentityContracts\ServiceProvider;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\SubscriptionPauses;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * What a pause does to the access, against the real entitlements addon (P1).
 *
 * The default the brief sets: the paid period stays, then the access rests.
 * The other two settings exist for sites that decide otherwise.
 */
class PauseAccessRealEntitlementTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), array_values(array_filter([
            class_exists(ServiceProvider::class) ? ServiceProvider::class : null,
            class_exists(\Goldnead\BrandContext\ServiceProvider::class) ? \Goldnead\BrandContext\ServiceProvider::class : null,
            class_exists(\Goldnead\Entitlements\ServiceProvider::class) ? \Goldnead\Entitlements\ServiceProvider::class : null,
        ])));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-entitlements/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-brand-context/database/migrations');

        if (! class_exists(Entitlements::class)) {
            $this->markTestSkipped('the sibling has to be installed for this to mean anything');
        }

        Carbon::setTestNow('2026-09-23 10:00:00');

        config([
            'statamic-payments.entitlements.enabled' => true,
            'statamic-payments.products.mitgliedschaft' => [
                'name' => 'Mitgliedschaft', 'amount_cent' => 1900, 'interval' => '1 month', 'grants' => 'chor',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function abo(): Subscription
    {
        $this->gateway->subscriptions['sub_1'] = ['customer' => 'cst_1', 'status' => 'active'];

        return Subscription::create([
            'provider' => 'fake', 'provider_id' => 'sub_1', 'customer_reference' => 'cst_1',
            'product' => 'mitgliedschaft', 'amount_cent' => 1900, 'currency' => 'EUR',
            'interval' => '1 month', 'times_charged' => 1, 'status' => 'active',
            'next_payment_at' => Carbon::parse('2026-10-05 00:00'),
            'email' => 'wer@example.com',
        ]);
    }

    private function zugang(?string $bis = null): void
    {
        Entitlements::grant(new SubjectReference('email', 'wer@example.com'), 'chor', 'statamic-payments', 'sub_1',
            expiresAt: $bis ? Carbon::parse($bis) : null);
    }

    #[Test]
    public function by_default_the_paid_period_stays_and_then_the_access_rests(): void
    {
        $this->zugang();

        app(SubscriptionPauses::class)->pause($this->abo());

        $this->assertSame('2026-10-05', Entitlement::first()->expires_at->format('Y-m-d'),
            'an open-ended access outlived the pause, or ended before the paid period did');
        $this->assertNull(Entitlement::first()->revoked_at);
    }

    #[Test]
    public function immediate_ends_the_access_now_and_resuming_brings_it_back(): void
    {
        config(['statamic-payments.pause.access' => 'immediate']);
        $this->zugang('2026-10-05');

        $pauses = app(SubscriptionPauses::class);
        $abo = $this->abo();
        $pauses->pause($abo);

        $this->assertSame('2026-09-23', Entitlement::first()->expires_at->format('Y-m-d'));

        Carbon::setTestNow('2026-11-02 08:00:00');
        $pauses->resume($abo->fresh());

        $this->assertSame(1, Entitlement::count(), 'resuming wrote a second access instead of renewing the first');
        $this->assertSame('2026-11-05', Entitlement::first()->expires_at->format('Y-m-d'));
    }

    #[Test]
    public function a_late_debit_during_the_pause_renews_the_one_access_and_writes_no_second(): void
    {
        $this->zugang('2026-10-05');
        $abo = $this->abo();
        app(SubscriptionPauses::class)->pause($abo);

        $id = $this->gateway->arrive('mitgliedschaft', 1900, 'sub_1');
        $this->postJson(route('statamic-payments.webhook'), ['id' => $id])->assertOk();

        $this->assertSame(1, Entitlement::count(), 'the cycle wrote a second, open-ended access');
        $this->assertSame('2026-11-05', Entitlement::first()->expires_at->format('Y-m-d'));
    }

    #[Test]
    public function keep_carries_the_access_to_the_day_the_pause_ends(): void
    {
        config(['statamic-payments.pause.access' => 'keep']);
        $this->zugang('2026-10-05');

        app(SubscriptionPauses::class)->pause($this->abo(), Carbon::parse('2026-12-01'));

        $this->assertSame('2026-12-01', Entitlement::first()->expires_at->format('Y-m-d'));
    }
}
