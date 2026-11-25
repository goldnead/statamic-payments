<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Events\PaymentRefunded;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * Stripe-Erstattungen, deren Zuordnung nicht aufgeht.
 *
 * Jeder dieser Wege endet mit 200 und ohne Buchung. Ohne Log-Zeile waere das
 * Schweigen ueber Geld, das das Konto verlassen hat — deshalb wird beides
 * geprueft: der Zustand in der Datenbank und die Meldung.
 */
class StripeRefundWebhookLuckenTest extends TestCase
{
    protected string $secret = 'whsec_kJ8vN2mQ4pR7sT1uV3wX5yZ6aB8cD0eF';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.stripe.key', 'sk_test_notARealKey');
        $app['config']->set('statamic-payments.stripe.webhook_secret', $this->secret);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Eine nicht gestubbte URL soll den Test scheitern lassen, nicht das
        // echte Stripe erreichen.
        Http::preventStrayRequests();
    }

    protected function paidPayment(string $provider, string $providerId): Payment
    {
        return Payment::create([
            'provider' => $provider,
            'provider_id' => $providerId,
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'paid_at' => now(),
            'fulfilled_at' => now(),
            'email' => 'kaeufer@example.com',
        ]);
    }

    /** Ein signiertes Stripe-Ereignis aus einem fertigen Body. */
    protected function deliverRaw(string $body): TestResponse
    {
        $timestamp = now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $this->secret);

        return $this->call('POST', '/!/statamic-payments/webhook/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $body);
    }

    protected function deliverStripe(string $eventId, string $type, ?array $object): TestResponse
    {
        return $this->deliverRaw(json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ], JSON_UNESCAPED_SLASHES));
    }

    #[Test]
    public function a_refund_on_a_charge_without_an_id_is_logged_and_booked_nowhere(): void
    {
        // Die Zahlung existiert, und der Intent auf dem Objekt zeigt auf sie.
        // Gerade deshalb darf nur die fehlende Charge-ID den Weg stoppen —
        // sonst wuerde die Suche nach der Zahlung greifen und eine Erstattung
        // buchen, die niemand einer Charge zuordnen kann.
        $payment = $this->paidPayment('stripe', 'pi_ohne_id_1');
        Event::fake([PaymentRefunded::class]);
        Log::spy();

        $this->deliverStripe('evt_ohne_charge_id', 'charge.refunded', [
            'object' => 'charge',
            'payment_intent' => 'pi_ohne_id_1',
            'amount_refunded' => 1900,
        ])->assertOk();

        $this->assertSame(0, (int) $payment->fresh()->refunded_cent);
        $this->assertSame(0, DB::table('payment_refunds')->count());
        Event::assertNotDispatched(PaymentRefunded::class);
        Http::assertNothingSent();

        // Diese Zeile hat keinen Kontext; die Meldung ist alles, was sie sagt.
        Log::shouldHaveReceived('warning', [
            Mockery::on(fn ($message) => is_string($message) && str_contains($message, 'could not be matched to an order')),
        ]);
    }

    #[Test]
    public function a_refund_for_a_payment_this_site_does_not_have_is_logged_and_not_booked(): void
    {
        // Geld hat das Konto verlassen, aber zu keiner Zeile dieser Seite. Die
        // Zeile daneben gehoert einer anderen Zahlung und bleibt unberuehrt.
        $andere = $this->paidPayment('stripe', 'cs_test_andere');
        Event::fake([PaymentRefunded::class]);
        Log::spy();

        Http::fake([
            'api.stripe.com/v1/charges/ch_fremd*' => Http::response([
                'id' => 'ch_fremd',
                'object' => 'charge',
                'invoice' => null,
            ]),
        ]);

        $this->deliverStripe('evt_fremde_erstattung', 'charge.refunded', [
            'id' => 'ch_fremd',
            'object' => 'charge',
            'payment_intent' => null,
            'amount_refunded' => 1900,
        ])->assertOk();

        $this->assertSame(0, DB::table('payment_refunds')->count());
        $this->assertSame(0, (int) $andere->fresh()->refunded_cent);
        Event::assertNotDispatched(PaymentRefunded::class);

        Log::shouldHaveReceived('warning', [
            Mockery::on(fn ($message) => is_string($message) && str_contains($message, 'refunded a charge this site has no payment for')),
            Mockery::on(fn ($context) => is_array($context) && ($context['charge'] ?? null) === 'ch_fremd'),
        ]);
    }

    #[Test]
    public function a_refund_on_a_subscription_renewal_without_an_intent_finds_its_row_by_invoice(): void
    {
        // Die Rate traegt die Rechnungskennung. Ohne payment_intent darf die
        // Zuordnung nur ueber die Rechnung der Charge laufen.
        $rate = $this->paidPayment('stripe', 'in_rate_erstattung_1');
        $andere = $this->paidPayment('stripe', 'in_rate_erstattung_andere');
        Event::fake([PaymentRefunded::class]);

        Http::fake([
            'api.stripe.com/v1/charges/ch_rate_erstattung*' => Http::response([
                'id' => 'ch_rate_erstattung',
                'object' => 'charge',
                'invoice' => 'in_rate_erstattung_1',
            ]),
            'api.stripe.com/v1/refunds*' => Http::response([
                'object' => 'list',
                'data' => [[
                    'id' => 're_rate_1',
                    'object' => 'refund',
                    'amount' => 1900,
                    'status' => 'succeeded',
                    'charge' => 'ch_rate_erstattung',
                ]],
            ]),
        ]);

        $this->deliverStripe('evt_rate_erstattung', 'charge.refunded', [
            'id' => 'ch_rate_erstattung',
            'object' => 'charge',
            'payment_intent' => null,
            'amount_refunded' => 1900,
        ])->assertOk();

        $this->assertSame(1900, (int) $rate->fresh()->refunded_cent);
        $this->assertSame(0, (int) $andere->fresh()->refunded_cent);
        $this->assertDatabaseHas('payment_refunds', [
            'payment_id' => $rate->getKey(),
            'reference' => 're_rate_1',
            'amount_cent' => 1900,
        ]);
        Event::assertDispatched(PaymentRefunded::class, fn (PaymentRefunded $e) => $e->amountCent === 1900 && $e->isFull === true);
    }

    #[Test]
    public function a_verified_event_without_a_readable_object_is_answered_and_logged(): void
    {
        // Verifiziert, aber ohne lesbares Objekt. Stripe bekommt 200 und
        // wiederholt nicht; die Zeile im Log ist dann das Einzige, was bleibt.
        Event::fake([PaymentRefunded::class]);
        Log::spy();

        $body = json_encode([
            'id' => 'evt_ohne_objekt',
            'object' => 'event',
            'type' => 'charge.refunded',
            'data' => [],
        ], JSON_UNESCAPED_SLASHES);

        $this->deliverRaw($body)->assertOk()->assertExactJson(['received' => true]);

        Http::assertNothingSent();
        Event::assertNotDispatched(PaymentRefunded::class);

        Log::shouldHaveReceived('warning', [
            Mockery::on(fn ($message) => is_string($message) && str_contains($message, 'carried no object to act on')),
            Mockery::on(fn ($context) => is_array($context) && ($context['type'] ?? null) === 'charge.refunded'),
        ]);
    }
}
