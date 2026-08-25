<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\IdentityContracts\ServiceProvider;
use Goldnead\StatamicPayments\Integrations\EntitlementsBridge;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * The same thing again, against the sibling itself.
 *
 * The fake next door is as strict as the real class and still only proves that
 * this side calls correctly. Whether the call is *accepted* is a question only
 * the real addon answers — and the last time this bridge was tested only
 * against a stand-in, it had never worked on a single real installation.
 */
class SubscriptionRenewsRealEntitlementTest extends TestCase
{
    /**
     * Die Geschwister als echte Provider, nicht als Attrappen.
     *
     * entitlements haengt an brand-context und identity-contracts; ohne die
     * beiden loest seine Facade gar nicht auf. Nur in dieser Datei, damit die
     * uebrige Suite weiter ohne die Nachbarschaft laeuft.
     */
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

        config([
            'statamic-payments.entitlements.enabled' => true,
            'statamic-payments.products.mitgliedschaft' => [
                'name' => 'Mitgliedschaft', 'amount_cent' => 1900, 'grants' => 'mitgliedschaft',
            ],
        ]);
    }

    private function abo(array $werte = []): Subscription
    {
        return Subscription::create(array_merge([
            'provider' => 'fake', 'provider_id' => 'sub_1', 'customer_reference' => 'cst_1',
            'product' => 'mitgliedschaft', 'amount_cent' => 1900, 'currency' => 'EUR',
            'interval' => '1 month', 'times_charged' => 1, 'status' => 'active',
            'next_payment_at' => Carbon::parse('2026-10-01 00:00'),
            'email' => 'wer@example.com',
        ], $werte));
    }

    private function zahlung(): Payment
    {
        return Payment::create([
            'provider' => 'fake', 'provider_id' => 'tr_z', 'product' => 'mitgliedschaft',
            'amount_cent' => 1900, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
            'email' => 'wer@example.com',
        ]);
    }

    #[Test]
    public function twelve_cycles_are_one_entitlement_and_not_twelve(): void
    {
        $subject = new SubjectReference('email', 'wer@example.com');

        Entitlements::grant($subject, 'mitgliedschaft', 'statamic-payments', 'sub_1',
            expiresAt: Carbon::parse('2026-09-01 00:00'));

        $bruecke = app(EntitlementsBridge::class);
        $abo = $this->abo();
        $zahlung = $this->zahlung();

        // Dasselbe Abo, drei Zyklen. Genau so kommt es im Betrieb: eine Zeile,
        // deren `next_payment_at` der Anbieter jeden Monat weiterschiebt.
        for ($monat = 10; $monat <= 12; $monat++) {
            $abo->forceFill(['next_payment_at' => Carbon::parse("2026-{$monat}-01 00:00")])->save();
            $bruecke->renewFor($abo->fresh(), $zahlung);
        }

        $this->assertSame(1, Entitlement::count(), 'jeder Zyklus hat einen neuen Zugang geschrieben');
        $this->assertSame('2026-12-01', Entitlement::first()->expires_at->format('Y-m-d'));
    }

    #[Test]
    public function a_first_cycle_without_any_prior_grant_creates_one(): void
    {
        app(EntitlementsBridge::class)->renewFor($this->abo(), $this->zahlung());

        $this->assertSame(1, Entitlement::count());
        $this->assertSame('2026-10-01', Entitlement::first()->expires_at->format('Y-m-d'));
    }

    #[Test]
    public function cancelling_leaves_the_paid_period_intact(): void
    {
        $subject = new SubjectReference('email', 'wer@example.com');

        // Offen vergeben: genau der Fall, der sonst ewig weiterläuft.
        Entitlements::grant($subject, 'mitgliedschaft', 'statamic-payments', 'sub_1');

        $this->assertNull(Entitlement::first()->expires_at);

        app(EntitlementsBridge::class)->closeFor($this->abo(['status' => 'cancelled']));

        $zugang = Entitlement::first();

        $this->assertSame('2026-10-01', $zugang->expires_at->format('Y-m-d'));
        // Und nicht widerrufen: der Zeitraum ist bezahlt.
        $this->assertNull($zugang->revoked_at);
    }
}
