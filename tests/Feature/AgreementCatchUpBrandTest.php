<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\ServiceProvider;
use Goldnead\StatamicPayments\Events\SubscriptionStarted;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Brands;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Support\SubscriptionReplacements;
use Goldnead\StatamicPayments\Support\Subscriptions;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Der Nachhol-Pfad (`payments:agreement-catch-up`) laeuft in keiner Anfrage
 * und in keiner Marke. Der Webhook-Pfad packt den Abo-Schritt in
 * `Brands::runFor($payment->brand_id, ...)`, damit markenbewusste Listener
 * die Abo-Events in der Marke der Zahlung hoeren. Der Nachhol-Pfad muss
 * dasselbe tun.
 */
class AgreementCatchUpBrandTest extends TestCase
{
    /** Wie oft der Abo-Schritt noch scheitern soll. */
    public static int $failures = 0;

    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), array_values(array_filter([
            class_exists(ServiceProvider::class) ? ServiceProvider::class : null,
        ])));
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.follow_up.collect_mandate', true);
        $app['config']->set('statamic-payments.products', [
            'monatsabo' => ['name' => 'Monatsabo', 'amount_cent' => 1900, 'interval' => '1 month'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(BrandContext::class)) {
            $this->markTestSkipped('goldnead/statamic-brand-context has to be installed for this to mean anything');
        }

        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-brand-context/database/migrations');
        config(['brand-context.multi_brand' => true]);

        self::$failures = 0;

        $this->app->bind(SubscriptionReplacements::class, fn ($app) => new class($app->make(Subscriptions::class), $app->make(Catalogue::class)) extends SubscriptionReplacements
        {
            public function creditDays(Payment $payment): int
            {
                if (AgreementCatchUpBrandTest::$failures > 0) {
                    AgreementCatchUpBrandTest::$failures--;

                    throw new RuntimeException('Abo-Tabelle gesperrt');
                }

                return parent::creditDays($payment);
            }
        });
    }

    #[Test]
    public function the_catch_up_hears_the_agreement_in_the_brand_of_its_payment(): void
    {
        $shop = Brand::create(['handle' => 'shop-b', 'name' => 'Shop B']);

        // Vorbedingung: die Marke ist nicht die Standardmarke, sonst
        // beweist der Test nichts.
        $this->assertNotSame(Brands::defaultId(), (int) $shop->getKey());

        // Der Abo-Schritt scheitert einmal, im ersten Lauf unter der Marke der Zahlung.
        self::$failures = 1;

        $kauf = BrandContext::runFor($shop->getKey(), function () {
            $result = app(Subscriptions::class)->start('monatsabo', ['email' => 'k@example.com']);
            $this->assertNotNull($result);
            $this->gateway->markPaid($result->payment->provider_id);
            $this->postJson(route('statamic-payments.webhook'), ['id' => $result->payment->provider_id])->assertOk();

            return $result->payment->fresh();
        });

        $this->assertSame((int) $shop->getKey(), (int) $kauf->brand_id);
        $this->assertArrayHasKey('agreement_pending', $kauf->meta);

        // Erst jetzt hoeren: der Listener soll den Nachzug sehen, nicht den ersten Lauf.
        $heard = [];
        Event::listen(SubscriptionStarted::class, function () use (&$heard) {
            $heard[] = Brands::stampId();
        });

        // Der Nachzug laeuft ohne aktive Marke, wie der Befehl in der Konsole.
        $this->artisan('payments:agreement-catch-up')->assertSuccessful();

        $this->assertArrayNotHasKey('agreement_pending', $kauf->fresh()->meta ?? []);
        $this->assertSame([(int) $shop->getKey()], $heard, 'der Listener hat die Marke der Zahlung nicht gesehen');
    }
}
