<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Events\PaymentCommunicationLogged;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Facades\PaymentLog;
use Goldnead\StatamicPayments\Legal\Withdrawals;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentCommunication;
use Goldnead\StatamicPayments\Portal\LinkRequests;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * Das Kommunikationsprotokoll.
 *
 * Zwei Versprechen: was verschickt wurde, steht danach an der Zahlung; und ein
 * Protokoll, das nicht schreiben kann, hält keine Mail auf.
 */
class PaymentLogTest extends TestCase
{
    protected function payment(array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'provider' => 'fake',
            'provider_id' => 'tr_'.uniqid(),
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'paid_at' => now(),
            'email' => 'kaeufer@example.com',
            'name' => 'Maria Beispiel',
        ], $overrides));
    }

    #[Test]
    public function a_mail_is_written_to_the_payment_and_announced(): void
    {
        Event::fake([PaymentCommunicationLogged::class]);

        $payment = $this->payment();

        $row = PaymentLog::mail($payment, 'invoice', 'kaeufer@example.com', 'Ihre Rechnung', meta: ['invoice' => 'R-1']);

        $this->assertInstanceOf(PaymentCommunication::class, $row);
        $this->assertSame('mail', $row->channel);
        $this->assertSame('invoice', $row->kind);
        $this->assertSame('sent', $row->status);
        $this->assertSame(['invoice' => 'R-1'], $row->meta);
        $this->assertSame($payment->id, PaymentLog::for($payment)->first()->payment_id);

        // Über die Id geht es auch — der Aufrufer hat oft nur die.
        PaymentLog::note($payment->id, 'support', 'Kunde hat angerufen.');
        $this->assertCount(2, PaymentLog::for($payment->id));
        $this->assertSame('support', PaymentLog::for($payment)->first()->kind);

        Event::assertDispatchedTimes(PaymentCommunicationLogged::class, 2);
    }

    #[Test]
    public function a_log_that_cannot_write_does_not_throw(): void
    {
        $payment = $this->payment();

        Log::shouldReceive('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'could not be written'));

        Schema::drop('payment_communications');

        $this->assertNull(PaymentLog::mail($payment, 'invoice', 'kaeufer@example.com', 'Ihre Rechnung'));
    }

    #[Test]
    public function an_unknown_payment_id_is_refused_quietly(): void
    {
        Log::shouldReceive('warning')->once();

        $this->assertNull(PaymentLog::mail(999999, 'invoice', 'x@example.com'));
        $this->assertSame(0, PaymentCommunication::count());
    }

    #[Test]
    public function the_portal_link_is_logged_on_the_buyers_latest_order(): void
    {
        Mail::fake();

        $older = $this->payment(['paid_at' => now()->subDays(3)]);
        $latest = $this->payment(['paid_at' => now()->subDay()]);

        app(LinkRequests::class)->request('kaeufer@example.com', '127.0.0.1');

        $this->assertSame(0, PaymentLog::for($older)->count());
        $row = PaymentLog::for($latest)->first();
        $this->assertNotNull($row);
        $this->assertSame('portal_link', $row->kind);
        $this->assertSame('kaeufer@example.com', $row->recipient);
        $this->assertNotEmpty($row->subject);
    }

    #[Test]
    public function a_withdrawal_receipt_is_logged_only_where_a_payment_matched(): void
    {
        Mail::fake();

        $payment = $this->payment();
        $withdrawals = app(Withdrawals::class);

        $matched = $withdrawals->declare(['name' => 'Maria', 'email' => 'kaeufer@example.com', 'order_reference' => (string) $payment->id], '127.0.0.1');
        $withdrawals->confirm($matched);

        $unmatched = $withdrawals->declare(['name' => 'Wer', 'email' => 'niemand@example.com', 'order_reference' => '4711'], '127.0.0.1');
        $withdrawals->confirm($unmatched);

        // Beide Mails des zugeordneten Widerrufs, und nur die: der Widerruf
        // ohne Treffer hat keine Zahlung, an der eine Zeile hängen könnte.
        $rows = PaymentLog::for($payment);
        $this->assertSame(['withdrawal_notice', 'withdrawal_receipt'], $rows->pluck('kind')->sort()->values()->all());
        $this->assertSame($matched->fresh()->public_id, $rows->firstWhere('kind', 'withdrawal_receipt')->meta['withdrawal']);
        $this->assertSame(2, PaymentCommunication::count());
    }

    #[Test]
    public function an_ordinary_purchase_records_nothing_by_itself(): void
    {
        Mail::fake();

        // Der Feldfund vom 02.09.2026: drei echte Käufe, fünf zugestellte
        // Mails, null Zeilen. Kein Defekt der Fassade — die schreibt, siehe
        // oben —, sondern die Bauart. Dieses Paket verschickt bei einem
        // gewöhnlichen Kauf **gar keine** Mail: Kaufbestätigung, Zugangsdaten
        // und Willkommensgruß kommen von der Seite, und was das Paket nicht
        // verschickt, kann es nicht protokollieren.
        //
        // Der Test hält das als Zusage fest, damit ein leeres Protokoll nach
        // einem Kauf nicht wieder als Fehler gelesen wird — und damit
        // auffällt, falls dieses Paket später doch selbst etwas verschickt und
        // den Eintrag vergisst. Wer die Zeilen will, trägt sie ein:
        $payment = $this->payment();

        PaymentPaid::dispatch($payment);

        $this->assertCount(0, PaymentLog::for($payment));

        PaymentLog::mail($payment, 'purchase_confirmation', (string) $payment->email, 'Danke für deinen Kauf');

        $this->assertSame('purchase_confirmation', PaymentLog::for($payment)->first()->kind);
    }
}
