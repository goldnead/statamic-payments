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
 * Ein Produkt, das mehrere Zugaenge vergibt.
 *
 * **Warum das gegen das echte Addon laeuft und nicht gegen eine Attrappe.**
 * Der Fehler, den diese Datei bewacht, war stumm: `grants` durfte nur eine
 * Zeichenkette sein, und eine Liste fiel an `is_string()` heraus — ein Buendel
 * vergab damit *gar nichts*, nicht etwa das erste Stueck. Zahlung durch,
 * Rechnung geschrieben, kein Zugang, keine Fehlermeldung. Eine Attrappe haette
 * bestaetigt, dass diese Seite ihre Aufrufe richtig macht; ob am Ende ein
 * Zugang in der Tabelle steht, beantwortet nur das Geschwister selbst.
 */
class BundleGrantsSeveralEntitlementsTest extends TestCase
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

        config([
            'statamic-payments.entitlements.enabled' => true,
            'statamic-payments.products.buendel' => [
                'name' => 'Frühlings-Bündel',
                'amount_cent' => 4900,
                'grants' => ['noten-fruehling', 'playback-fruehling', 'workshop-mitschnitt'],
            ],
            'statamic-payments.products.einzeln' => [
                'name' => 'Notenpaket',
                'amount_cent' => 1900,
                'grants' => 'noten-fruehling',
            ],
        ]);
    }

    private function zahlung(string $produkt, string $ref = 'tr_1'): Payment
    {
        return Payment::create([
            'provider' => 'fake',
            'provider_id' => $ref,
            'product' => $produkt,
            'amount_cent' => 4900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'wer@example.com',
        ]);
    }

    #[Test]
    public function a_bundle_hands_over_every_one_of_its_parts(): void
    {
        app(EntitlementsBridge::class)->grantFor($this->zahlung('buendel'));

        $this->assertSame(3, Entitlement::count());
        $this->assertEqualsCanonicalizing(
            ['noten-fruehling', 'playback-fruehling', 'workshop-mitschnitt'],
            Entitlement::pluck('product_slug')->all(),
        );
    }

    #[Test]
    public function a_single_slug_still_works_exactly_as_before(): void
    {
        app(EntitlementsBridge::class)->grantFor($this->zahlung('einzeln'));

        $this->assertSame(1, Entitlement::count());
        $this->assertSame('noten-fruehling', Entitlement::first()->product_slug);
    }

    #[Test]
    public function a_refund_takes_back_every_part_and_not_just_the_first(): void
    {
        $zahlung = $this->zahlung('buendel');

        app(EntitlementsBridge::class)->grantFor($zahlung);
        $this->assertSame(3, Entitlement::count());

        app(EntitlementsBridge::class)->revokeFor($zahlung, isFull: true);

        // Die halbe Rueckabwicklung waere hier der Fehler: Geld zurueck, zwei
        // Zugaenge bleiben stehen.
        $this->assertSame(0, Entitlement::whereNull('revoked_at')->count());
    }

    #[Test]
    public function a_subscription_on_a_bundle_renews_all_of_it(): void
    {
        $subject = new SubjectReference('email', 'wer@example.com');

        foreach (['noten-fruehling', 'playback-fruehling', 'workshop-mitschnitt'] as $slug) {
            Entitlements::grant($subject, $slug, 'statamic-payments', 'sub_1',
                expiresAt: Carbon::parse('2026-09-01 00:00'));
        }

        $abo = Subscription::create([
            'provider' => 'fake', 'provider_id' => 'sub_1', 'customer_reference' => 'cst_1',
            'product' => 'buendel', 'amount_cent' => 4900, 'currency' => 'EUR',
            'interval' => '1 month', 'times_charged' => 1, 'status' => 'active',
            'next_payment_at' => Carbon::parse('2026-10-01 00:00'),
            'email' => 'wer@example.com',
        ]);

        app(EntitlementsBridge::class)->renewFor($abo, $this->zahlung('buendel', 'tr_2'));

        $this->assertSame(3, Entitlement::count(), 'ein Zyklus hat neue Zugaenge angelegt statt zu verlaengern');

        foreach (Entitlement::all() as $zugang) {
            $this->assertSame('2026-10-01', $zugang->expires_at->format('Y-m-d'), $zugang->product_slug.' wurde nicht verlaengert');
        }
    }

    #[Test]
    public function a_slug_named_twice_is_granted_once(): void
    {
        config(['statamic-payments.products.buendel.grants' => ['noten-fruehling', 'noten-fruehling']]);

        app(EntitlementsBridge::class)->grantFor($this->zahlung('buendel'));

        // Zwei Zeilen mit derselben Aussage sind keine zwei Zugaenge.
        $this->assertSame(1, Entitlement::count());
    }
}
