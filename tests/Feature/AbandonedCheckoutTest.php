<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Events\CheckoutAbandoned;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Abandonment;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * Somebody started a checkout and did not finish it.
 *
 * The feature is one question asked on a schedule, and everything that can go
 * wrong is in the words "long enough" and "once". A reminder that arrives twice
 * is a support ticket nobody can reproduce; a reminder that arrives while the
 * person is still typing their card number is worse.
 */
class AbandonedCheckoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('statamic-payments.abandoned.enabled', true);
        config()->set('statamic-payments.abandoned.after_minutes', 60);
    }

    private function zahlung(array $werte = []): Payment
    {
        return Payment::create(array_merge([
            'provider' => 'mollie',
            'provider_id' => 'tr_'.bin2hex(random_bytes(4)),
            'product' => 'kurs',
            'amount_cent' => 24900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_OPEN,
            'email' => 'wer@example.com',
            'created_at' => Carbon::now()->subHours(3),
        ], $werte));
    }

    #[Test]
    public function an_unpaid_checkout_past_the_cut_off_is_announced_once(): void
    {
        Event::fake([CheckoutAbandoned::class]);

        $zahlung = $this->zahlung();

        $this->assertSame(1, app(Abandonment::class)->sweep());

        // Der zweite Lauf ist der eigentliche Test: der Planer überlappt sich,
        // und ohne den Anspruch in der Tabelle melden beide dieselbe Zahlung.
        $this->assertSame(0, app(Abandonment::class)->sweep());

        Event::assertDispatchedTimes(CheckoutAbandoned::class, 1);
        $this->assertNotNull($zahlung->fresh()->abandoned_notified_at);
    }

    #[Test]
    public function somebody_still_typing_is_left_alone(): void
    {
        Event::fake([CheckoutAbandoned::class]);

        $this->zahlung(['created_at' => Carbon::now()->subMinutes(10)]);

        $this->assertSame(0, app(Abandonment::class)->sweep());
        Event::assertNotDispatched(CheckoutAbandoned::class);
    }

    #[Test]
    public function a_failed_payment_is_not_also_an_abandoned_one(): void
    {
        // `failed`, `expired` und `canceled` haben ihr eigenes Ereignis. Beides
        // zu melden hieße zwei Mails über eine Sache.
        Event::fake([CheckoutAbandoned::class]);

        foreach ([Payment::STATUS_FAILED, Payment::STATUS_EXPIRED, Payment::STATUS_CANCELED, Payment::STATUS_PAID] as $status) {
            $this->zahlung(['status' => $status]);
        }

        $this->assertSame(0, app(Abandonment::class)->sweep());
        Event::assertNotDispatched(CheckoutAbandoned::class);
    }

    #[Test]
    public function a_fulfilled_payment_is_never_announced_whatever_its_status_says(): void
    {
        // Eine erfüllte Zahlung mit einem Status, der noch `open` sagt, ist ein
        // Zustand, den es geben kann — und niemand darf für etwas erinnert
        // werden, das er bekommen hat.
        Event::fake([CheckoutAbandoned::class]);

        $this->zahlung(['fulfilled_at' => Carbon::now()->subHour()]);

        $this->assertSame(0, app(Abandonment::class)->sweep());
    }

    #[Test]
    public function paying_afterwards_takes_the_row_out_of_the_abandoned_state(): void
    {
        // Die Strecke, die irgendwo noch läuft, muss aufhören können, und das
        // ehrliche Signal dafür ist die Erfüllung.
        $zahlung = $this->zahlung();

        app(Abandonment::class)->sweep();
        $this->assertNotNull($zahlung->fresh()->abandoned_notified_at);

        app(Abandonment::class)->settled($zahlung->fresh());

        $this->assertNull($zahlung->fresh()->abandoned_notified_at);
    }

    #[Test]
    public function it_stays_quiet_until_a_site_switches_it_on(): void
    {
        // Aus gutem Grund die Vorgabe: die Adresse aus einem unfertigen
        // Checkout wurde zum Kaufen gegeben, nicht zum Beworbenwerden.
        Event::fake([CheckoutAbandoned::class]);

        config()->set('statamic-payments.abandoned.enabled', false);

        $this->zahlung();

        $this->assertSame(0, app(Abandonment::class)->sweep());
        Event::assertNotDispatched(CheckoutAbandoned::class);
    }

    #[Test]
    public function the_waiting_period_is_the_sites_to_choose(): void
    {
        Event::fake([CheckoutAbandoned::class]);

        config()->set('statamic-payments.abandoned.after_minutes', 15);

        $this->zahlung(['created_at' => Carbon::now()->subMinutes(20)]);

        $this->assertSame(1, app(Abandonment::class)->sweep());
    }

    #[Test]
    public function a_failed_cycle_of_a_running_agreement_is_not_an_abandoned_purchase(): void
    {
        // Ein gescheiterter Stripe-Zyklus wird als `open` angelegt und bleibt
        // `open` — die Form, auf die diese Bereinigung genau passt. Er ist aber
        // kein liegengebliebener Einkauf: es gibt keinen Warenkorb, den jemand
        // noch abschliessen koennte, sondern ein laufendes Abo, dessen Karte
        // nicht mehr geht. Dafuer schreibt die Mahnstrecke. Ein „Sie haben
        // etwas vergessen" daneben verbrennt den Kanal genau in dem Moment, in
        // dem der Kunde einen ernsten Brief bekommen soll — und `prune-unpaid`
        // haelt aus demselben Grund die Finger davon.
        Event::fake([CheckoutAbandoned::class]);

        $abo = Subscription::create([
            'provider' => 'stripe',
            'provider_id' => 'sub_1',
            'customer_reference' => 'cus_1',
            'product' => 'kurs',
            'amount_cent' => 24900,
            'currency' => 'EUR',
            'interval' => '1 month',
            'times_charged' => 4,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => Carbon::now()->subMonths(4),
            'email' => 'wer@example.com',
        ]);

        $rate = $this->zahlung([
            'provider' => 'stripe',
            'meta' => ['cycle_of' => ['subscription_id' => $abo->getKey()]],
        ]);

        $abo->forceFill(['dunning_started_at' => Carbon::now(), 'dunning_payment_id' => $rate->getKey()])->save();

        $this->assertSame(0, app(Abandonment::class)->sweep());
        Event::assertNotDispatched(CheckoutAbandoned::class);
        $this->assertNull($rate->fresh()->abandoned_notified_at);
    }

    #[Test]
    public function a_cycle_is_left_alone_even_without_a_sequence_running(): void
    {
        // Die Wache haengt nicht an der Mahnstrecke. Ist sie abgeschaltet, ist
        // der Zyklus trotzdem kein abgebrochener Kauf.
        Event::fake([CheckoutAbandoned::class]);

        $this->zahlung([
            'provider' => 'stripe',
            'meta' => ['cycle_of' => ['subscription_id' => 7, 'first_payment_id' => 3]],
        ]);

        $this->assertSame(0, app(Abandonment::class)->sweep());
        Event::assertNotDispatched(CheckoutAbandoned::class);
    }

    #[Test]
    public function the_command_reports_what_it_did(): void
    {
        $this->zahlung();

        $this->artisan('payments:sweep-abandoned')
            ->expectsOutputToContain('Ein abgebrochener Checkout gemeldet.')
            ->assertSuccessful();
    }
}
