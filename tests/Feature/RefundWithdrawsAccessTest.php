<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\IdentityContracts\ServiceProvider;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Refunds;
use Goldnead\StatamicPayments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Somebody who was repaid does not keep the course.
 *
 * This was the actual state of things until now, not a future concern: the
 * refund happened in the provider's dashboard, nothing here heard about it, and
 * the grant stayed active forever. The sibling has had `revoke()` with a
 * mandatory reason all along — it was simply never called.
 *
 * Against the real addon, not a stand-in. A stand-in would prove the call and
 * say nothing about whether it was accepted, which is exactly how this bridge
 * once shipped broken for months.
 */
class RefundWithdrawsAccessTest extends TestCase
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

        config([
            'statamic-payments.entitlements.enabled' => true,
            'statamic-payments.products.kurs' => [
                'name' => 'Kurs', 'amount_cent' => 10000, 'grants' => 'kurs',
            ],
        ]);
    }

    private function bezahlteZahlung(): Payment
    {
        return Payment::create([
            'provider' => 'fake', 'provider_id' => 'tr_1', 'product' => 'kurs',
            'amount_cent' => 10000, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
            'email' => 'wer@example.com', 'fulfilled_at' => now(),
        ]);
    }

    private function zugangVergeben(): void
    {
        Entitlements::grant(
            new SubjectReference('email', 'wer@example.com'),
            'kurs', 'statamic-payments', 'tr_1',
        );
    }

    #[Test]
    public function a_full_refund_takes_the_access_away(): void
    {
        $this->zugangVergeben();
        $this->assertSame(EntitlementState::Active, Entitlement::first()->state());

        app(Refunds::class)->record($this->bezahlteZahlung(), 10000, 're_1');

        $zugang = Entitlement::first();

        $this->assertSame(EntitlementState::Revoked, $zugang->state());
        // Der Grund ist im Geschwister Pflicht, aus gutem Grund: ein Widerruf,
        // den später niemand erklären kann, ist einer, den jemand rückgängig macht.
        $this->assertNotEmpty($zugang->revoked_reason);
    }

    #[Test]
    public function a_partial_refund_leaves_the_access_alone(): void
    {
        // Das halbe Geld zurück heißt nicht der halbe Kurs, und einen Zugang
        // halb zu entziehen gibt es nicht. Also entscheidet ein Mensch.
        $this->zugangVergeben();

        app(Refunds::class)->record($this->bezahlteZahlung(), 4000, 're_1');

        $this->assertSame(EntitlementState::Active, Entitlement::first()->state());
    }

    #[Test]
    public function it_withdraws_every_line_of_the_order_and_not_only_the_first(): void
    {
        // Ein mitgekaufter Order Bump ist genauso bezahlt — und genauso
        // erstattet.
        config(['statamic-payments.products.noten' => [
            'name' => 'Noten', 'amount_cent' => 1500, 'grants' => 'noten',
        ]]);

        $subject = new SubjectReference('email', 'wer@example.com');
        Entitlements::grant($subject, 'kurs', 'statamic-payments', 'tr_1');
        Entitlements::grant($subject, 'noten', 'statamic-payments', 'tr_1');

        $zahlung = $this->bezahlteZahlung();
        $zahlung->items()->createMany([
            ['product' => 'kurs', 'name' => 'Kurs', 'amount_cent' => 10000, 'quantity' => 1, 'kind' => 'primary'],
            ['product' => 'noten', 'name' => 'Noten', 'amount_cent' => 1500, 'quantity' => 1, 'kind' => 'bump'],
        ]);

        app(Refunds::class)->record($zahlung->fresh(), 10000, 're_1');

        $this->assertSame(2, Entitlement::where('status', EntitlementState::Revoked->value)->count());
    }

    #[Test]
    public function a_refund_without_the_bridge_switched_on_changes_no_access(): void
    {
        config(['statamic-payments.entitlements.enabled' => false]);

        $this->zugangVergeben();

        app(Refunds::class)->record($this->bezahlteZahlung(), 10000, 're_1');

        $this->assertSame(EntitlementState::Active, Entitlement::first()->state());
    }
}
