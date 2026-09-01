<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Facades\PaymentLog;
use Goldnead\StatamicPayments\Mail\AbandonedCheckoutMail;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Goldnead\StatamicPayments\Support\AbandonedReminder;
use Goldnead\StatamicPayments\Support\Abandonment;
use Goldnead\StatamicPayments\Tests\TestCase;
use Goldnead\Suppression\Facades\SuppressionGate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;

/**
 * Die Erinnerung an einen offenen Kauf.
 *
 * Was hier zu belegen ist: die Mail geht nur, wenn sie darf (Schalter,
 * Sperrliste); sie steht danach im Protokoll; der Link führt zu einem neuen
 * Checkout mit denselben Positionen; und wer danach zahlt, zählt als
 * zurückgeholt — auch wenn er über die neue Zeile zahlt.
 */
class AbandonedMailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(SuppressionGate::class)) {
            require_once __DIR__.'/../Fixtures/SuppressionGateStub.php';
        }

        SuppressionGate::$suppressed = [];
        SuppressionGate::$throws = false;

        config([
            'statamic-payments.abandoned.enabled' => true,
            'statamic-payments.abandoned.after_minutes' => 60,
            'statamic-payments.abandoned.mail.enabled' => true,
            'statamic-payments.products' => [
                'noten-paket' => ['name' => 'Notenpaket', 'amount_cent' => 1900],
                'chorheft' => ['name' => 'Chorheft', 'amount_cent' => 500],
            ],
        ]);

        Mail::fake();
    }

    protected function tearDown(): void
    {
        SuppressionGate::$suppressed = [];
        SuppressionGate::$throws = false;

        parent::tearDown();
    }

    private function offen(array $werte = []): Payment
    {
        $payment = Payment::create(array_merge([
            'provider' => 'fake',
            'provider_id' => 'tr_'.bin2hex(random_bytes(4)),
            'product' => 'noten-paket',
            'amount_cent' => 2400,
            'currency' => 'EUR',
            'status' => Payment::STATUS_OPEN,
            'email' => 'wer@example.com',
            'name' => 'Maria Beispiel',
            'created_at' => Carbon::now()->subHours(3),
            'utm_source' => 'newsletter',
            'consent_at' => Carbon::now()->subHours(3),
            'consent_text' => 'Ich stimme zu.',
        ], $werte));

        PaymentItem::create(['payment_id' => $payment->id, 'product' => 'noten-paket', 'name' => 'Notenpaket', 'amount_cent' => 1900, 'quantity' => 1, 'kind' => PaymentItem::KIND_PRIMARY, 'offer' => 'fruehling']);
        PaymentItem::create(['payment_id' => $payment->id, 'product' => 'chorheft', 'name' => 'Chorheft', 'amount_cent' => 500, 'quantity' => 1, 'kind' => PaymentItem::KIND_BUMP]);

        return $payment->fresh() ?? $payment;
    }

    #[Test]
    public function the_reminder_goes_out_once_and_is_logged(): void
    {
        $payment = $this->offen();

        $this->assertSame(1, app(Abandonment::class)->sweep());
        $this->assertSame(0, app(Abandonment::class)->sweep());

        Mail::assertSent(AbandonedCheckoutMail::class, function (AbandonedCheckoutMail $mail) use ($payment) {
            return $mail->hasTo('wer@example.com')
                && $mail->payment->is($payment)
                && str_contains($mail->variables['resume_url'], '/!/statamic-payments/weiter/'.$payment->id)
                && str_contains($mail->variables['resume_url'], 'signature=');
        });
        Mail::assertSentCount(1);

        $row = PaymentLog::for($payment)->first();
        $this->assertNotNull($row);
        $this->assertSame('abandoned', $row->kind);
        $this->assertSame('wer@example.com', $row->recipient);
        $this->assertSame('sent', $row->status);
    }

    #[Test]
    public function the_built_in_mail_renders_the_lines_and_the_link(): void
    {
        $payment = $this->offen();
        $rendered = app(AbandonedReminder::class)->render($payment);

        $this->assertNull($rendered['html'], 'ohne email-templates gibt es keine Vorlage');

        $html = (new AbandonedCheckoutMail($payment, $rendered['subject'], null, $rendered['variables']))->render();

        $this->assertStringContainsString('Notenpaket', $html);
        $this->assertStringContainsString('Chorheft', $html);
        $this->assertStringContainsString('24.00', $html);
        $this->assertStringContainsString('/!/statamic-payments/weiter/'.$payment->id, $html);
        $this->assertStringContainsString('Maria Beispiel', $html);
    }

    #[Test]
    public function nothing_goes_out_while_the_mail_is_switched_off(): void
    {
        config(['statamic-payments.abandoned.mail.enabled' => false]);

        $payment = $this->offen();

        $this->assertSame(1, app(Abandonment::class)->sweep(), 'das Ereignis wird trotzdem angekündigt');

        Mail::assertNothingSent();
        $this->assertCount(0, PaymentLog::for($payment));
    }

    #[Test]
    public function a_suppressed_address_gets_no_mail_and_a_note_instead(): void
    {
        SuppressionGate::$suppressed = ['wer@example.com'];

        $payment = $this->offen();

        app(Abandonment::class)->sweep();

        Mail::assertNothingSent();

        $row = PaymentLog::for($payment)->first();
        $this->assertSame('note', $row->channel);
        $this->assertSame('abandoned_suppressed', $row->kind);
    }

    #[Test]
    public function a_suppression_list_that_does_not_answer_counts_as_suppressed(): void
    {
        SuppressionGate::$throws = true;

        $this->offen();
        app(Abandonment::class)->sweep();

        Mail::assertNothingSent();
    }

    #[Test]
    public function an_own_resume_url_wins_and_carries_the_id(): void
    {
        config(['statamic-payments.abandoned.mail.resume_url' => '/kasse/weiter?zahlung={payment}']);

        $payment = $this->offen();

        $this->assertStringEndsWith('/kasse/weiter?zahlung='.$payment->id, app(AbandonedReminder::class)->resumeUrl($payment));
    }

    #[Test]
    public function the_signed_link_starts_the_checkout_again_with_the_same_lines(): void
    {
        $payment = $this->offen();

        $url = URL::temporarySignedRoute('statamic-payments.resume', Carbon::now()->addDay(), ['payPayment' => $payment->id]);

        $response = $this->get($url);

        $response->assertRedirect();
        $this->assertStringStartsWith('https://checkout.example/', $response->headers->get('Location'));

        $neu = Payment::query()->orderByDesc('id')->first();

        $this->assertNotSame($payment->id, $neu->id);
        $this->assertSame($payment->id, $neu->meta['resumed_from']);
        $this->assertSame(2400, $neu->amount_cent);
        $this->assertSame('wer@example.com', $neu->email);
        $this->assertSame('newsletter', $neu->utm_source, 'die Herkunft wandert mit');
        $this->assertSame('Ich stimme zu.', $neu->consent_text, 'die Zustimmung wandert mit');
        // Nach Id gelesen: die Relation sortiert nicht, SQLite liefert hier
        // rückwärts, und die Reihenfolge ist genau das, was der Test prüft.
        $items = $neu->items()->orderBy('id')->get();
        $this->assertSame(['noten-paket', 'chorheft'], $items->pluck('product')->all());
        $this->assertSame(['primary', 'bump'], $items->pluck('kind')->all());
        $this->assertSame('fruehling', $items->first()->offer);
    }

    #[Test]
    public function an_unsigned_or_paid_link_does_not_start_anything(): void
    {
        $payment = $this->offen();

        $this->get('/!/statamic-payments/weiter/'.$payment->id)->assertForbidden();

        $payment->forceFill(['status' => Payment::STATUS_PAID, 'paid_at' => now()])->save();
        $url = URL::temporarySignedRoute('statamic-payments.resume', Carbon::now()->addDay(), ['payPayment' => $payment->id]);

        $this->get($url)->assertStatus(410);
        $this->assertSame(1, Payment::count());
    }

    #[Test]
    public function paying_after_the_reminder_marks_the_payment_recovered(): void
    {
        $payment = $this->offen();
        app(Abandonment::class)->sweep();

        app(Abandonment::class)->settled($payment->fresh());

        $payment = $payment->fresh();
        $this->assertNull($payment->abandoned_notified_at);
        $this->assertNotNull($payment->recovered_at);
    }

    #[Test]
    public function paying_through_a_resumed_checkout_marks_the_original_recovered(): void
    {
        $original = $this->offen();
        app(Abandonment::class)->sweep();

        $url = URL::temporarySignedRoute('statamic-payments.resume', Carbon::now()->addDay(), ['payPayment' => $original->id]);
        $this->get($url)->assertRedirect();

        $neu = Payment::query()->orderByDesc('id')->first();

        app(Abandonment::class)->settled($neu);

        $this->assertNotNull($original->fresh()->recovered_at);
        $this->assertNull($neu->fresh()->recovered_at, 'die neue Zeile wurde nie erinnert');
    }

    #[Test]
    public function a_payment_never_reminded_is_not_recovered(): void
    {
        $payment = $this->offen();

        app(Abandonment::class)->settled($payment);

        $this->assertNull($payment->fresh()->recovered_at);
    }
}
