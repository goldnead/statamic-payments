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

    private function resumeUrl(Payment $payment): string
    {
        return URL::temporarySignedRoute('statamic-payments.resume', Carbon::now()->addDay(), ['payPayment' => $payment->id]);
    }

    private function orderUrl(Payment $payment): string
    {
        return URL::temporarySignedRoute('statamic-payments.resume.start', Carbon::now()->addHour(), ['payPayment' => $payment->id]);
    }

    #[Test]
    public function the_signed_link_shows_the_order_page_and_creates_nothing(): void
    {
        $payment = $this->offen(['discount_code' => 'FRUEHLING', 'discount_cent' => 400, 'amount_cent' => 2000]);

        $response = $this->get($this->resumeUrl($payment));

        $response->assertOk();
        $response->assertSee('Notenpaket');
        $response->assertSee('Chorheft');
        $response->assertSee('20.00 EUR');
        $response->assertSee('4.00 EUR', false);
        $response->assertSee(__('statamic-payments::abandoned.resume_button'));
        $response->assertSee(__('statamic-payments::messages.order_consent'));
        // Die Bestellung ist ein POST auf einen signierten Link, nicht der GET.
        $response->assertSee('method="POST"', false);
        $response->assertSee('/!/statamic-payments/weiter/'.$payment->id.'?', false);
        $response->assertSee('signature=', false);

        $this->assertSame(1, Payment::count(), 'der GET legt nichts an');
    }

    #[Test]
    public function the_order_button_starts_the_checkout_with_a_fresh_consent(): void
    {
        Carbon::setTestNow('2026-09-02 10:00:00');

        $payment = $this->offen(['brand_id' => 7, 'discount_code' => 'FRUEHLING', 'discount_cent' => 400, 'amount_cent' => 2000]);

        $response = $this->post($this->orderUrl($payment), ['consent' => '1']);

        $response->assertRedirect();
        $this->assertStringStartsWith('https://checkout.example/', $response->headers->get('Location'));

        $neu = Payment::query()->orderByDesc('id')->first();

        $this->assertNotSame($payment->id, $neu->id);
        $this->assertSame($payment->id, $neu->meta['resumed_from']);
        $this->assertSame(2000, $neu->amount_cent, 'der Rabatt wandert mit');
        $this->assertSame('FRUEHLING', $neu->discount_code);
        $this->assertSame('wer@example.com', $neu->email);
        $this->assertSame(7, (int) $neu->brand_id, 'die Marke des Originals');
        $this->assertSame('newsletter', $neu->utm_source, 'die Herkunft wandert mit');
        // Die Zustimmung ist neu — jetzt, mit dem gezeigten Wortlaut — und
        // nicht die drei Stunden alte des Originals.
        $this->assertSame('2026-09-02 10:00:00', $neu->consent_at->format('Y-m-d H:i:s'));
        $this->assertSame(__('statamic-payments::messages.order_consent'), $neu->consent_text);
        $this->assertNotSame($payment->consent_text, $neu->consent_text);
        // Nach Id gelesen: die Relation sortiert nicht, SQLite liefert hier
        // rückwärts, und die Reihenfolge ist genau das, was der Test prüft.
        $items = $neu->items()->orderBy('id')->get();
        $this->assertSame(['noten-paket', 'chorheft'], $items->pluck('product')->all());
        $this->assertSame(['primary', 'bump'], $items->pluck('kind')->all());
        $this->assertSame('fruehling', $items->first()->offer);

        Carbon::setTestNow();
    }

    #[Test]
    public function without_the_box_ticked_no_consent_is_written(): void
    {
        $payment = $this->offen();

        $this->post($this->orderUrl($payment))->assertRedirect();

        $neu = Payment::query()->orderByDesc('id')->first();

        $this->assertNotSame($payment->id, $neu->id);
        $this->assertNull($neu->consent_at);
        $this->assertNull($neu->consent_text);
    }

    #[Test]
    public function the_wording_comes_from_the_payment_where_it_carries_one(): void
    {
        $payment = $this->offen(['meta' => ['withdrawal' => ['text' => 'Eigener Satz der Kasse.', 'version' => '2026-06']]]);

        $this->get($this->resumeUrl($payment))->assertSee('Eigener Satz der Kasse.');

        $this->post($this->orderUrl($payment), ['consent' => '1'])->assertRedirect();

        $this->assertSame('Eigener Satz der Kasse.', Payment::query()->orderByDesc('id')->first()->consent_text);
    }

    #[Test]
    public function a_second_press_reuses_the_open_checkout(): void
    {
        $payment = $this->offen();

        $first = $this->post($this->orderUrl($payment), ['consent' => '1']);
        $second = $this->post($this->orderUrl($payment), ['consent' => '1']);

        $this->assertSame(2, Payment::count(), 'ein Original, ein Neustart — kein dritter');
        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));

        // Nach einer Stunde ist die Kasse des Anbieters abgelaufen: dann neu.
        Payment::query()->where('meta->resumed_from', $payment->id)->update(['created_at' => Carbon::now()->subHours(2)]);

        $this->post($this->orderUrl($payment), ['consent' => '1'])->assertRedirect();
        $this->assertSame(3, Payment::count());
    }

    #[Test]
    public function an_unsigned_link_is_refused_and_a_paid_one_answers_with_a_sentence(): void
    {
        $payment = $this->offen();

        $this->get('/!/statamic-payments/weiter/'.$payment->id)->assertForbidden();
        $this->post('/!/statamic-payments/weiter/'.$payment->id, ['consent' => '1'])->assertForbidden();

        $payment->forceFill(['status' => Payment::STATUS_PAID, 'paid_at' => now()])->save();

        $this->get($this->resumeUrl($payment))->assertStatus(410);
        $this->post($this->orderUrl($payment), ['consent' => '1'])->assertStatus(410);
        $this->assertSame(1, Payment::count());
    }

    #[Test]
    public function a_name_with_markup_stays_text_in_a_template(): void
    {
        $payment = $this->offen(['name' => '<b>Eva</b> & Co']);
        $variables = app(AbandonedReminder::class)->variables($payment);

        $html = AbandonedReminder::apply('<p>Hallo {{ buyer.name }}</p>{{ order.lines }}<a href="{{ resume_url }}">x</a>', $variables);

        $this->assertStringContainsString('Hallo &lt;b&gt;Eva&lt;/b&gt; &amp; Co', $html);
        $this->assertStringNotContainsString('<b>Eva</b>', $html);
        $this->assertStringContainsString('<ul><li>', $html, 'die Liste bleibt Markup');
        $this->assertStringContainsString('href="'.$variables['resume_url'].'"', $html);

        // Der Betreff ist kein HTML.
        $this->assertSame('Für <b>Eva</b> & Co', AbandonedReminder::apply('Für {{ buyer.name }}', $variables, escape: false));
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

        $this->post($this->orderUrl($original), ['consent' => '1'])->assertRedirect();

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
