<?php

namespace Goldnead\StatamicPayments\Tests\Unit;

use Goldnead\StatamicPayments\Integrations\EntitlementsBridge;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Tests\Support\StrictEntitlements;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * A subscription and the access it pays for, kept in step.
 *
 * The three interesting rules are all about *not* doing the obvious thing:
 * a renewal is not a second grant, a cancellation is not a revocation, and a
 * renewal without a date from the provider is not a guess.
 */
class SubscriptionEntitlementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'statamic-payments.entitlements.enabled' => true,
            'statamic-payments.products.mitgliedschaft' => [
                'name' => 'Mitgliedschaft',
                'amount_cent' => 1900,
                'grants' => 'mitgliedschaft',
            ],
        ]);
    }

    private function abo(array $werte = []): Subscription
    {
        return Subscription::create(array_merge([
            'provider' => 'fake',
            'provider_id' => 'sub_1',
            'customer_reference' => 'cst_1',
            'product' => 'mitgliedschaft',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'interval' => '1 month',
            'times_charged' => 1,
            'status' => 'active',
            'next_payment_at' => Carbon::parse('2026-10-01 00:00'),
            'email' => 'wer@example.com',
        ], $werte));
    }

    private function zahlung(): Payment
    {
        return Payment::create([
            'provider' => 'fake',
            'provider_id' => 'tr_zyklus',
            'product' => 'mitgliedschaft',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'wer@example.com',
        ]);
    }

    private function bindEntitlements(object $root): void
    {
        $this->assertTrue(class_exists('Goldnead\\Entitlements\\Facades\\Entitlements'));

        app()->instance('Goldnead\\Entitlements\\EntitlementManager', $root);
    }

    #[Test]
    public function a_renewal_extends_the_window_instead_of_granting_again(): void
    {
        // Der eigentliche Punkt. Ein Jahr Mitgliedschaft ist ein Zugang, nicht
        // zwölf — sonst wird „hat diese Person Zugang" eine Aggregation.
        $geschwister = new StrictEntitlements;
        $geschwister->renewAntwort = (object) ['slug' => 'mitgliedschaft'];
        $this->bindEntitlements($geschwister);

        app(EntitlementsBridge::class)->renewFor($this->abo(), $this->zahlung());

        $this->assertCount(1, $geschwister->renewed);
        $this->assertCount(0, $geschwister->granted, 'eine Verlängerung darf keinen zweiten Zugang schreiben');
        $this->assertSame('2026-10-01', $geschwister->renewed[0]['until']);
        $this->assertIsNotString($geschwister->renewed[0]['subject']);
    }

    #[Test]
    public function the_first_cycle_of_a_subscription_that_predates_the_bridge_is_granted(): void
    {
        // renew() gibt null zurück: es gab nichts zu verlängern. Dann ist
        // Vergeben die richtige Antwort und nicht Schweigen.
        $geschwister = new StrictEntitlements;
        $geschwister->renewAntwort = null;
        $this->bindEntitlements($geschwister);

        app(EntitlementsBridge::class)->renewFor($this->abo(), $this->zahlung());

        $this->assertCount(1, $geschwister->renewed);
        $this->assertCount(1, $geschwister->granted);
        $this->assertSame('mitgliedschaft', $geschwister->granted[0]['slug']);
    }

    #[Test]
    public function a_renewal_without_a_date_from_the_provider_changes_nothing(): void
    {
        // Ein geratenes Ende ist ein Zugang, der zu früh oder zu spät endet,
        // und beides merkt erst der Kunde.
        $geschwister = new StrictEntitlements;
        $this->bindEntitlements($geschwister);

        app(EntitlementsBridge::class)->renewFor($this->abo(['next_payment_at' => null]), $this->zahlung());

        $this->assertCount(0, $geschwister->renewed);
        $this->assertCount(0, $geschwister->granted);
    }

    #[Test]
    public function cancelling_closes_an_open_ended_grant_rather_than_revoking_it(): void
    {
        // Wer kündigt, hat den laufenden Zeitraum bezahlt und behält ihn.
        // Widerrufen hieße, ihm gekaufte Zeit wegzunehmen.
        $geschwister = new StrictEntitlements;
        $grant = new class
        {
            public array $gesetzt = [];

            public function forceFill(array $w): static
            {
                $this->gesetzt = $w;

                return $this;
            }

            public function save(): bool
            {
                return true;
            }
        };
        $geschwister->offeneGrants = [$grant];
        $this->bindEntitlements($geschwister);

        app(EntitlementsBridge::class)->closeFor($this->abo(['status' => 'cancelled']));

        $this->assertArrayHasKey('expires_at', $grant->gesetzt);
        $this->assertSame('2026-10-01', $grant->gesetzt['expires_at']->format('Y-m-d'));
    }

    #[Test]
    public function a_grant_that_already_has_an_end_is_left_alone(): void
    {
        // Es läuft von selbst aus. Anzufassen hieße, ein Datum zu überschreiben,
        // das jemand anderes aus einem guten Grund gesetzt hat.
        $geschwister = new StrictEntitlements;
        $geschwister->offeneGrants = [];
        $this->bindEntitlements($geschwister);

        app(EntitlementsBridge::class)->closeFor($this->abo(['status' => 'cancelled']));

        $this->assertTrue(true, 'kein Fehler, kein Schreibvorgang');
    }

    #[Test]
    public function nothing_happens_when_the_product_grants_nothing(): void
    {
        $geschwister = new StrictEntitlements;
        $this->bindEntitlements($geschwister);

        app(EntitlementsBridge::class)->renewFor($this->abo(['product' => 'kennt-keiner']), $this->zahlung());

        $this->assertCount(0, $geschwister->renewed);
    }

    #[Test]
    public function nothing_happens_while_the_bridge_is_switched_off(): void
    {
        config(['statamic-payments.entitlements.enabled' => false]);

        $geschwister = new StrictEntitlements;
        $this->bindEntitlements($geschwister);

        app(EntitlementsBridge::class)->renewFor($this->abo(), $this->zahlung());

        $this->assertCount(0, $geschwister->renewed);
    }
}
