<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\IdentityContracts\ServiceProvider;
use Goldnead\StatamicPayments\Integrations\EntitlementsBridge;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

/**
 * Die Rechnung, die Stripe zu jedem neuen Abo schreibt.
 *
 * Ein Abo mit Probezeit nimmt heute Geld an der Kasse und laesst den ersten
 * Einzug in der Zukunft. Stripe legt zum Abo trotzdem sofort eine Rechnung an —
 * `billing_reason: subscription_create`, `total: 0` — und markiert sie als
 * `paid`, weil an einer Rechnung ueber null Euro nichts offen bleibt. Der
 * Webhook `invoice.paid` kommt also **im selben Augenblick** wie die Kasse.
 *
 * Am 09.09.2026 gegen ein echtes Stripe-Testkonto beobachtet: der Kaeufer
 * bekam zwei Auftraege ueber 19 EUR und zwei Zugaenge, Stripe hatte einmal
 * abgebucht. Der Zyklus-Pfad erbte den Betrag vom Abo und fragte die Rechnung
 * nie nach ihrem eigenen.
 */
class ProbezeitrechnungTest extends TestCase
{
    protected string $secret = 'whsec_kJ8vN2mQ4pR7sT1uV3wX5yZ6aB8cD0eF';

    /** Die Geschwister als echte Provider: ein zweiter Zugang ist nur an einem echten zu zaehlen. */
    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), array_values(array_filter([
            class_exists(ServiceProvider::class) ? ServiceProvider::class : null,
            class_exists(\Goldnead\BrandContext\ServiceProvider::class) ? \Goldnead\BrandContext\ServiceProvider::class : null,
            class_exists(\Goldnead\Entitlements\ServiceProvider::class) ? \Goldnead\Entitlements\ServiceProvider::class : null,
        ])));
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.stripe.key', 'sk_test_notARealKey');
        $app['config']->set('statamic-payments.stripe.webhook_secret', $this->secret);
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

        // Eine URL, die niemand gestellt hat, muss den Test umbringen und nicht
        // heimlich das echte Stripe erreichen.
        Http::preventStrayRequests();
    }

    protected function deliver(string $eventId, string $type, array $object): TestResponse
    {
        $body = json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ], JSON_UNESCAPED_SLASHES);

        $timestamp = Carbon::now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $this->secret);

        return $this->call(
            'POST',
            '/!/statamic-payments/webhook/stripe',
            [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            $body,
        );
    }

    /** Das Abo und die eine Zahlung, die es an der Kasse gab. */
    protected function abgeschlossenesAbo(): Subscription
    {
        $abo = Subscription::create([
            'provider' => 'stripe',
            'provider_id' => 'sub_probe',
            'customer_reference' => 'cus_Probe',
            'product' => 'mitgliedschaft',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'interval' => '1 month',
            'times' => null,
            'times_charged' => 0,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => Carbon::now()->addDays(14),
            'next_payment_at' => Carbon::now()->addDays(14),
            'email' => 'kaeufer@example.com',
        ]);

        $kasse = Payment::create([
            'provider' => 'stripe',
            'provider_id' => 'cs_probe',
            'product' => 'mitgliedschaft',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'paid_at' => Carbon::now(),
            'fulfilled_at' => Carbon::now(),
            'subscription_id' => $abo->getKey(),
            'email' => 'kaeufer@example.com',
        ]);

        app(EntitlementsBridge::class)->grantFor($kasse);

        return $abo;
    }

    /**
     * @param  array<string, mixed>  $ueberschreibungen  was an der Rechnung anders sein soll
     */
    protected function stripeAntwortet(array $ueberschreibungen = []): void
    {
        Http::fake([
            'api.stripe.com/v1/invoices/in_probe*' => Http::response(array_merge([
                'id' => 'in_probe',
                'object' => 'invoice',
                'status' => 'paid',
                'billing_reason' => 'subscription_create',
                'subscription' => 'sub_probe',
                'customer_email' => 'kaeufer@example.com',
                // Was Stripe zu einer Probezeit wirklich schickt.
                'total' => 0,
                'amount_due' => 0,
                'amount_paid' => 0,
                'charge' => null,
            ], $ueberschreibungen)),
            'api.stripe.com/v1/subscriptions/sub_probe*' => Http::response([
                'id' => 'sub_probe',
                'object' => 'subscription',
                'status' => 'active',
                'customer' => 'cus_Probe',
                'current_period_end' => Carbon::now()->addDays(14)->getTimestamp(),
            ]),
        ]);
    }

    #[Test]
    public function eine_rechnung_ueber_null_euro_ist_kein_bezahlter_zyklus(): void
    {
        $abo = $this->abgeschlossenesAbo();
        $this->stripeAntwortet();

        $this->assertSame(1, Payment::count());
        $this->assertSame(1, Entitlement::count());

        $this->deliver('evt_probe', 'invoice.paid', ['id' => 'in_probe', 'object' => 'invoice'])->assertOk();

        $this->assertSame(1, Payment::count(), 'der Kaeufer sieht zwei Bestellungen fuer eine Abbuchung');
        $this->assertFalse(
            Payment::query()->where('provider_id', 'in_probe')->exists(),
            'die Probezeitrechnung hat einen eigenen Auftrag angelegt, obwohl Stripe null Euro genommen hat',
        );
        $this->assertSame(1, Entitlement::count(), 'derselbe Kauf hat zwei Zugaenge vergeben');
        $this->assertSame(0, $abo->fresh()->times_charged, 'der Zyklus-Zaehler laeuft der Abbuchung voraus');
    }

    /**
     * Der Rand, an dem die Regel kippen wuerde.
     *
     * Null und „nicht gesagt" sind zwei Dinge. Eine Antwort ohne `total` — ein
     * ausgeschnittenes Feld, eine aeltere API-Fassung, ein Zwischenspeicher —
     * darf nicht wie eine Rechnung ueber null Euro behandelt werden, sonst
     * verschwindet die Rate eines echten Zahlungsplans stillschweigend.
     */
    #[Test]
    public function eine_rechnung_die_nichts_ueber_ihren_betrag_sagt_wird_verbucht_wie_bisher(): void
    {
        $abo = $this->abgeschlossenesAbo();
        $this->stripeAntwortet(['total' => null, 'amount_due' => null, 'amount_paid' => null, 'billing_reason' => 'subscription_cycle']);

        $this->deliver('evt_ohne_betrag', 'invoice.paid', ['id' => 'in_probe', 'object' => 'invoice'])->assertOk();

        $zyklus = Payment::query()->where('provider_id', 'in_probe')->first();

        $this->assertNotNull($zyklus, 'eine Rechnung ohne Betragsangabe wurde wie null Euro behandelt');
        $this->assertTrue($zyklus->isPaid());
        $this->assertSame(1900, $zyklus->amount_cent);
        $this->assertSame(1, $abo->fresh()->times_charged);
    }

    /**
     * Und der Zyklus mit echtem Betrag bleibt, was er war.
     *
     * Der eine Fall, den diese Aenderung auf keinen Fall anfassen darf: die
     * Monatsrate, an der das Geld wirklich haengt.
     */
    #[Test]
    public function ein_zyklus_mit_betrag_wird_weiter_verbucht(): void
    {
        $abo = $this->abgeschlossenesAbo();
        $this->stripeAntwortet(['total' => 1900, 'amount_due' => 1900, 'amount_paid' => 1900, 'billing_reason' => 'subscription_cycle']);

        $this->deliver('evt_zyklus', 'invoice.paid', ['id' => 'in_probe', 'object' => 'invoice'])->assertOk();

        $zyklus = Payment::query()->where('provider_id', 'in_probe')->first();

        $this->assertNotNull($zyklus);
        $this->assertTrue($zyklus->isPaid());
        $this->assertSame(1900, $zyklus->amount_cent);
        $this->assertSame(1, $abo->fresh()->times_charged);
    }
}
