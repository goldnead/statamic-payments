<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Events\PaymentChargedBack;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Chargebacks;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * Zwei Luecken im Rueckbuchungs-Pfad, die sonst still bleiben.
 *
 * Erstens: ein frueherer Versuch hat den Anspruch geschrieben, aber nicht den
 * Zustand. Die naechste Zustellung muss beides nachholen. Zweitens: Mollie
 * meldet eine Rueckbuchung ohne Kennung. Dann wird nichts gebucht, weil ohne
 * Kennung jede weitere Zustellung den Zugang erneut entziehen wuerde.
 */
class ChargebackLuckenTest extends TestCase
{
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

    #[Test]
    public function a_claim_whose_state_was_never_written_is_completed_on_the_next_delivery(): void
    {
        // Der Abbruch zwischen den beiden Schreibvorgaengen, nachgestellt: die
        // Anspruchszeile steht, `charged_back_at` ist null.
        $payment = $this->paidPayment('fake', 'tr_halb_erledigt');
        DB::table('payment_chargebacks')->insert([
            'payment_id' => $payment->getKey(),
            'reference' => 'chb_halb',
            'amount_cent' => 1900,
            'reason' => null,
            'created_at' => now(),
        ]);
        Event::fake([PaymentChargedBack::class]);
        Log::spy();

        $this->assertNull($payment->fresh()->charged_back_at);

        $this->assertTrue(app(Chargebacks::class)->record($payment, 'chb_halb', 1900));

        $this->assertNotNull($payment->fresh()->charged_back_at, 'der Zustand muss nachgeholt sein');
        $this->assertSame(1, DB::table('payment_chargebacks')->where('payment_id', $payment->getKey())->count());
        Event::assertDispatchedTimes(PaymentChargedBack::class, 1);
        Event::assertDispatched(PaymentChargedBack::class, fn ($e) => $e->reference === 'chb_halb');

        Log::shouldHaveReceived('warning', [
            Mockery::on(fn ($message) => is_string($message) && str_contains($message, 'claimed but never applied')),
            Mockery::any(),
        ]);
    }

    #[Test]
    public function a_mollie_chargeback_without_a_reference_books_nothing_and_says_so(): void
    {
        // Mollie meldet den Betrag, aber keine Kennung. Ohne Kennung gibt es
        // keine Idempotenz, und jede Zustellung wuerde den Zugang erneut
        // entziehen. Also: nichts buchen, aber laut sein.
        $payment = $this->paidPayment('fake', 'tr_ohne_kennung');
        Event::fake([PaymentChargedBack::class]);
        Log::spy();

        $this->gateway->markPaid('tr_ohne_kennung');
        $this->gateway->markChargedBack('tr_ohne_kennung', 1900, '');

        $this->postJson('/!/statamic-payments/webhook', ['id' => 'tr_ohne_kennung'])->assertOk();

        $frisch = $payment->fresh();

        $this->assertNull($frisch->charged_back_at);
        $this->assertTrue($frisch->isPaid());
        $this->assertDatabaseMissing('payment_chargebacks', ['payment_id' => $payment->getKey()]);
        Event::assertNotDispatched(PaymentChargedBack::class);

        Log::shouldHaveReceived('warning', [
            Mockery::on(fn ($message) => is_string($message) && str_contains($message, 'named no reference')),
            Mockery::on(fn ($context) => is_array($context) && ($context['provider_id'] ?? null) === 'tr_ohne_kennung'),
        ]);
    }
}
