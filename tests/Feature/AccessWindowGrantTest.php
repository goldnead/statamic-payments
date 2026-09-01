<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\IdentityContracts\ServiceProvider;
use Goldnead\StatamicPayments\Integrations\EntitlementsBridge;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * „Ab einem Datum" und „nur für N Tage" (Register K·5).
 *
 * Das Angebot schreibt `meta.access` an die Zahlung; die Brücke gibt es als
 * `startsAt`/`expiresAt` an den Zugang weiter. Ohne Angabe bleibt alles wie
 * es war: sofort, unbefristet.
 */
class AccessWindowGrantTest extends TestCase
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

        if (! class_exists(Entitlements::class)) {
            $this->markTestSkipped('the sibling has to be installed for this to mean anything');
        }

        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-entitlements/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-brand-context/database/migrations');

        config([
            'statamic-payments.entitlements.enabled' => true,
            'statamic-payments.products.kurs' => ['name' => 'Kurs', 'amount_cent' => 24900, 'grants' => 'kurs-zugang'],
        ]);
    }

    private function zahlung(?array $access): Payment
    {
        return Payment::create([
            'provider' => 'fake', 'provider_id' => 'tr_'.bin2hex(random_bytes(4)),
            'product' => 'kurs', 'amount_cent' => 24900, 'currency' => 'EUR',
            'status' => Payment::STATUS_PAID, 'email' => 'wer@example.com',
            'meta' => $access === null ? null : ['access' => $access],
        ]);
    }

    #[Test]
    public function a_start_date_and_a_duration_become_the_window_of_the_grant(): void
    {
        app(EntitlementsBridge::class)->grantFor($this->zahlung(['starts_at' => '2026-10-01', 'days' => 30]));

        $grant = Entitlement::first();

        $this->assertSame('2026-10-01', $grant->starts_at->toDateString());
        $this->assertSame('2026-10-31', $grant->expires_at->toDateString());
    }

    #[Test]
    public function a_duration_alone_counts_from_now(): void
    {
        Carbon::setTestNow('2026-09-02 12:00:00');

        app(EntitlementsBridge::class)->grantFor($this->zahlung(['starts_at' => null, 'days' => 7]));

        $grant = Entitlement::first();

        // Der Nachbar setzt einen fehlenden Beginn selbst auf jetzt.
        $this->assertSame('2026-09-02', $grant->starts_at->toDateString());
        $this->assertSame('2026-09-09', $grant->expires_at->toDateString());

        Carbon::setTestNow();
    }

    #[Test]
    public function without_a_window_the_grant_is_immediate_and_open_ended(): void
    {
        app(EntitlementsBridge::class)->grantFor($this->zahlung(null));

        $grant = Entitlement::first();

        $this->assertFalse($grant->starts_at->isFuture(), 'sofort, nicht geplant');
        $this->assertNull($grant->expires_at);
    }
}
