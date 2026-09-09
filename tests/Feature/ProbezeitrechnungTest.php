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
use Illuminate\Support\Facades\Log;
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

    /**
     * Das Abo und die eine Zahlung, die es an der Kasse gab.
     *
     * @param  int  $bereitsAbgebucht  wie viele Zyklen schon gezaehlt sind; 0 ist der Zustand
     *                                 direkt nach der Kasse und damit der Probezeitfall
     */
    protected function abgeschlossenesAbo(int $bereitsAbgebucht = 0): Subscription
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
            'times_charged' => $bereitsAbgebucht,
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

    /**
     * Die Gutschrift, die durch den Waechter von v1.23.1 hindurchging.
     *
     * Ein Downgrade mitten im Monat laesst Stripe eine Rechnung ueber einen
     * **negativen** `total` schreiben: die anteilige Gutschrift fuer den
     * Zeitraum, den der Kaeufer zum alten Preis schon bezahlt hat. Sie steht
     * auf `paid` — an einer Rechnung, die Geld zurueckgibt, bleibt nichts
     * offen — und sie nennt das Abo.
     *
     * `amountCent !== 0` liess sie durch, und dahinter entstand exakt der
     * Schaden, den v1.23.1 behoben hat: eine bezahlte Zeile ueber den vollen
     * Abo-Betrag fuer einen Zeitraum, in dem kein Geld **hereinkam**, ein
     * zweiter Zugang und ein hochgezaehlter Zyklus-Zaehler. Schlimmer als der
     * Nullfall sogar, denn hier ist das Vorzeichen des echten Betrags dem
     * gebuchten entgegengesetzt.
     */
    #[Test]
    public function eine_gutschrift_ist_kein_bezahlter_zyklus(): void
    {
        $abo = $this->abgeschlossenesAbo();
        $this->stripeAntwortet([
            'total' => -840,
            'amount_due' => -840,
            'amount_paid' => 0,
            'billing_reason' => 'subscription_update',
        ]);

        $this->assertSame(1, Payment::count());
        $this->assertSame(1, Entitlement::count());

        $this->deliver('evt_gutschrift', 'invoice.paid', ['id' => 'in_probe', 'object' => 'invoice'])->assertOk();

        $this->assertFalse(
            Payment::query()->where('provider_id', 'in_probe')->exists(),
            'eine Gutschrift hat einen bezahlten Auftrag ueber den vollen Abo-Betrag angelegt',
        );
        $this->assertSame(1, Payment::count(), 'der Kaeufer sieht eine Bestellung fuer eine Rueckerstattung');
        $this->assertSame(1, Entitlement::count(), 'eine Gutschrift hat einen zweiten Zugang vergeben');
        $this->assertSame(0, $abo->fresh()->times_charged, 'eine Gutschrift hat den Zyklus-Zaehler hochgezaehlt');
    }

    /**
     * Der Erstzyklus bleibt leise.
     *
     * Die Probezeitrechnung kommt bei **jeder** Anmeldung. Eine Warnung, die
     * bei jeder Anmeldung klingelt, wird nach der dritten nicht mehr gelesen —
     * und dann faellt der Fall darunter, um den es wirklich geht, nicht mehr auf.
     */
    #[Test]
    public function die_probezeitrechnung_bleibt_leise(): void
    {
        $this->abgeschlossenesAbo();
        $this->stripeAntwortet();

        Log::spy();

        $this->deliver('evt_probe_leise', 'invoice.paid', ['id' => 'in_probe', 'object' => 'invoice'])->assertOk();

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message) => str_contains($message, 'worth nothing'),
        )->once();

        Log::shouldNotHaveReceived('warning', [
            \Mockery::on(fn (string $message) => str_contains($message, 'worth nothing')),
            \Mockery::any(),
        ]);
    }

    /**
     * Ein Zyklus ueber null Euro mitten im Lauf ist keine Kleinigkeit.
     *
     * Ein Gutschein ueber 100 % oder ein ausgesetzter Monat geht denselben Weg
     * wie die Probezeitrechnung, und das ist gewollt — der Betrag ist hier
     * geerbt, nicht belegt. Die Folge ist aber groesser, als der Kommentar bis
     * v1.23.1 zugab: ohne Zahlung laeuft auch `Subscriptions::recordCycle()`
     * nicht, damit kein `refresh()`, damit bleibt `next_payment_at` stehen und
     * der Kaeufer **verliert Zugang**. Das gehoert auf `warning` und der Satz
     * muss es aussprechen.
     */
    #[Test]
    public function ein_zyklus_ueber_null_euro_nach_dem_ersten_warnt_laut(): void
    {
        $this->abgeschlossenesAbo(bereitsAbgebucht: 3);
        $this->stripeAntwortet(['total' => 0, 'amount_due' => 0, 'amount_paid' => 0, 'billing_reason' => 'subscription_cycle']);

        Log::spy();

        $this->deliver('evt_null_im_lauf', 'invoice.paid', ['id' => 'in_probe', 'object' => 'invoice'])->assertOk();

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message) => str_contains($message, 'worth nothing')
                && str_contains($message, 'no access was extended'),
        )->once();

        $this->assertFalse(Payment::query()->where('provider_id', 'in_probe')->exists());
    }
}
