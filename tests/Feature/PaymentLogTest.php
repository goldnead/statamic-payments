<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Events\PaymentCommunicationLogged;
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

        $rows = PaymentLog::for($payment);
        $this->assertCount(1, $rows);
        $this->assertSame('withdrawal_receipt', $rows->first()->kind);
        $this->assertSame($matched->fresh()->public_id, $rows->first()->meta['withdrawal']);
        $this->assertSame(1, PaymentCommunication::count());
    }
}
