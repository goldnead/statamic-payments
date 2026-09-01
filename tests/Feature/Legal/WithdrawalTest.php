<?php

namespace Goldnead\StatamicPayments\Tests\Feature\Legal;

use Goldnead\StatamicPayments\Legal\Links;
use Goldnead\StatamicPayments\Legal\Mail\WithdrawalNotice;
use Goldnead\StatamicPayments\Legal\Mail\WithdrawalReceipt;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Withdrawal;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Antlers;

/**
 * Der Widerrufsbutton nach § 356a BGB.
 *
 * Was hier belegt wird: die zwei Schritte, die Eingangsbestätigung mit
 * Kennung und Zeit, die Zuordnung nur bei eindeutigem Treffer, der Hinweis auf
 * ein erloschenes Recht, die Idempotenz, die Bremse — und dass kein Weg durch
 * diesen Controller verrät, ob eine Adresse hier gekauft hat.
 */
class WithdrawalTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.withdrawal.notify', 'shop@example.com');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Die Wortlaute, die hier geprüft werden, sind die deutschen — das
        // Gesetz schreibt sie auf Deutsch vor.
        app()->setLocale('de');

        Mail::fake();
    }

    protected function payment(array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'provider' => 'fake',
            'provider_id' => 'tr_'.uniqid(),
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'paid_at' => now()->subDays(3),
            'email' => 'anna@example.de',
            'name' => 'Anna Beispiel',
        ], $overrides));
    }

    /** @return array<string, string> */
    protected function input(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Anna Beispiel',
            'email' => 'Anna@Example.de',
            'order_reference' => 'tr_abc',
            'contact' => '',
            'message' => '',
        ], $overrides);
    }

    #[Test]
    public function the_form_is_public_and_carries_the_statutory_heading(): void
    {
        $this->get(route('statamic-payments.withdrawal.form'))
            ->assertOk()
            ->assertSee('Vertrag widerrufen')
            ->assertSee('name="order_reference"', false);
    }

    #[Test]
    public function step_one_creates_a_declared_row_and_leads_to_the_confirmation_page(): void
    {
        $response = $this->post(route('statamic-payments.withdrawal.declare'), $this->input());

        $withdrawal = Withdrawal::first();

        $this->assertNotNull($withdrawal);
        $this->assertNotNull($withdrawal->declared_at);
        $this->assertNull($withdrawal->confirmed_at);
        $this->assertStringStartsWith('W-', $withdrawal->public_id);
        $this->assertSame(10, strlen($withdrawal->public_id));
        $this->assertDoesNotMatchRegularExpression('/[0O1I]/', substr($withdrawal->public_id, 2));
        // Kein Kontaktmittel genannt: die Adresse ist eines.
        $this->assertSame('Anna@Example.de', $withdrawal->contact);
        $this->assertNotNull($withdrawal->ip_hash);
        $this->assertNotSame('127.0.0.1', $withdrawal->ip_hash);

        $response->assertRedirect(route('statamic-payments.withdrawal.show', ['payWithdrawal' => $withdrawal->public_id]));

        // Schritt 2: die Eingaben, und genau die Schaltfläche, die § 356a
        // Abs. 3 vorschreibt.
        $this->get(route('statamic-payments.withdrawal.show', ['payWithdrawal' => $withdrawal->public_id]))
            ->assertOk()
            ->assertSee('Anna Beispiel')
            ->assertSee('tr_abc')
            ->assertSee('Widerruf bestätigen');

        // Noch nichts verschickt: Schritt 1 ist kein Widerruf.
        Mail::assertNothingSent();
    }

    #[Test]
    public function step_two_confirms_acknowledges_the_consumer_and_tells_the_merchant(): void
    {
        $this->travelTo(now()->startOfSecond());

        $payment = $this->payment(['provider_id' => 'tr_abc']);

        $this->post(route('statamic-payments.withdrawal.declare'), $this->input());
        $withdrawal = Withdrawal::first();

        $this->post(route('statamic-payments.withdrawal.confirm', ['payWithdrawal' => $withdrawal->public_id]))
            ->assertRedirect(route('statamic-payments.withdrawal.show', ['payWithdrawal' => $withdrawal->public_id]));

        $fresh = $withdrawal->fresh();

        $this->assertTrue(now()->equalTo($fresh->confirmed_at));
        $this->assertSame($payment->getKey(), $fresh->payment_id);
        $this->assertNotNull($fresh->receipt_sent_at);
        $this->assertNotNull($fresh->merchant_notified_at);

        Mail::assertSent(WithdrawalReceipt::class, function (WithdrawalReceipt $mail) use ($fresh) {
            $rendered = $mail->render();

            return $mail->hasTo('Anna@Example.de')
                && str_contains($mail->envelope()->subject, 'Eingang Ihres Widerrufs '.$fresh->public_id)
                && str_contains($rendered, $fresh->public_id)
                && str_contains($rendered, 'tr_abc')
                && str_contains($rendered, $fresh->confirmed_at->translatedFormat('d.m.Y'))
                && str_contains($rendered, $fresh->confirmed_at->translatedFormat('H:i'))
                && str_contains($rendered, 'ist bei uns eingegangen am');
        });

        Mail::assertSent(WithdrawalNotice::class, function (WithdrawalNotice $mail) use ($fresh) {
            return $mail->hasTo('shop@example.com')
                && str_contains($mail->render(), $fresh->public_id)
                && str_contains($mail->render(), 'Zugeordnete Zahlung');
        });

        // Schritt 3: Kennung und Zeit. Nicht Name, nicht Adresse.
        $this->get(route('statamic-payments.withdrawal.show', ['payWithdrawal' => $fresh->public_id]))
            ->assertOk()
            ->assertSee($fresh->public_id)
            ->assertSee($fresh->confirmed_at->translatedFormat('H:i'))
            ->assertDontSee('Anna Beispiel')
            ->assertDontSee('Anna@Example.de');
    }

    #[Test]
    public function confirming_twice_neither_moves_the_clock_nor_mails_again(): void
    {
        $this->post(route('statamic-payments.withdrawal.declare'), $this->input());
        $withdrawal = Withdrawal::first();

        $this->post(route('statamic-payments.withdrawal.confirm', ['payWithdrawal' => $withdrawal->public_id]));
        $first = $withdrawal->fresh()->confirmed_at;

        $this->travel(5)->minutes();

        $this->post(route('statamic-payments.withdrawal.confirm', ['payWithdrawal' => $withdrawal->public_id]))
            ->assertRedirect();

        $this->assertEquals($first, $withdrawal->fresh()->confirmed_at);
        Mail::assertSent(WithdrawalReceipt::class, 1);
        Mail::assertSent(WithdrawalNotice::class, 1);
    }

    #[Test]
    public function an_unmatched_declaration_is_still_acknowledged_and_reported(): void
    {
        // Es gibt keine Zahlung zu dieser Adresse. Das Formular verrät das
        // nicht, der Verbraucher bekommt seine Bestätigung, der Händler die
        // Meldung.
        $this->post(route('statamic-payments.withdrawal.declare'), $this->input(['email' => 'niemand@example.de']));
        $withdrawal = Withdrawal::first();

        $this->post(route('statamic-payments.withdrawal.confirm', ['payWithdrawal' => $withdrawal->public_id]));

        $this->assertNull($withdrawal->fresh()->payment_id);
        Mail::assertSent(WithdrawalReceipt::class, fn (WithdrawalReceipt $m) => $m->hasTo('niemand@example.de'));
        Mail::assertSent(WithdrawalNotice::class, fn (WithdrawalNotice $m) => str_contains($m->render(), 'Keine eindeutige Zahlung'));
    }

    #[Test]
    public function an_ambiguous_reference_is_not_matched(): void
    {
        // Zwei Zahlungen, dieselbe Adresse, und die Bestellkennung „1" trifft
        // die Id der ersten *und*, weil jemand `provider_id` so genannt hat,
        // die zweite. Zwei Treffer sind keiner.
        $first = $this->payment(['provider_id' => 'tr_eins']);
        $this->payment(['provider_id' => (string) $first->getKey()]);

        $this->post(route('statamic-payments.withdrawal.declare'), $this->input(['order_reference' => (string) $first->getKey()]));
        $withdrawal = Withdrawal::first();

        $this->post(route('statamic-payments.withdrawal.confirm', ['payWithdrawal' => $withdrawal->public_id]));

        $this->assertNull($withdrawal->fresh()->payment_id);
    }

    #[Test]
    public function the_numeric_order_id_matches_as_well_as_the_provider_id(): void
    {
        $payment = $this->payment();

        $this->post(route('statamic-payments.withdrawal.declare'), $this->input(['order_reference' => '#'.$payment->getKey()]));
        $withdrawal = Withdrawal::first();

        $this->post(route('statamic-payments.withdrawal.confirm', ['payWithdrawal' => $withdrawal->public_id]));

        $this->assertSame($payment->getKey(), $withdrawal->fresh()->payment_id);
    }

    #[Test]
    public function a_recorded_consent_on_the_match_becomes_a_hint_and_not_a_refusal(): void
    {
        $this->payment([
            'provider_id' => 'tr_digital',
            'consent_at' => now()->subDays(3),
            'consent_text' => 'Ich stimme zu, dass die Lieferung sofort beginnt.',
        ]);

        $this->post(route('statamic-payments.withdrawal.declare'), $this->input(['order_reference' => 'tr_digital']));
        $withdrawal = Withdrawal::first();

        $this->post(route('statamic-payments.withdrawal.confirm', ['payWithdrawal' => $withdrawal->public_id]));

        $this->assertTrue($withdrawal->fresh()->right_expired_hint);

        // Der Verbraucher liest davon nichts …
        Mail::assertSent(WithdrawalReceipt::class, fn (WithdrawalReceipt $m) => ! str_contains($m->render(), '356 Abs. 5'));
        // … der Händler schon.
        Mail::assertSent(WithdrawalNotice::class, fn (WithdrawalNotice $m) => str_contains($m->render(), '§ 356 Abs. 5 BGB'));
    }

    #[Test]
    public function a_declaration_after_the_configured_period_is_flagged_to_the_merchant_only(): void
    {
        $this->payment(['provider_id' => 'tr_alt', 'paid_at' => now()->subDays(40)]);

        $this->post(route('statamic-payments.withdrawal.declare'), $this->input(['order_reference' => 'tr_alt']));
        $withdrawal = Withdrawal::first();

        $this->post(route('statamic-payments.withdrawal.confirm', ['payWithdrawal' => $withdrawal->public_id]));

        Mail::assertSent(WithdrawalNotice::class, fn (WithdrawalNotice $m) => str_contains($m->render(), 'nach Ablauf der konfigurierten Frist von 14 Tagen'));
        Mail::assertSent(WithdrawalReceipt::class, fn (WithdrawalReceipt $m) => ! str_contains($m->render(), 'Frist'));
    }

    #[Test]
    public function the_confirmation_page_is_not_readable_without_having_declared_in_this_session(): void
    {
        $this->post(route('statamic-payments.withdrawal.declare'), $this->input());
        $withdrawal = Withdrawal::first();

        // Ein anderer Browser, der die Kennung kennt: keine Zusammenfassung
        // mit Name und Adresse — zurück zum Formular mit einem Satz — und kein
        // Bestätigen einer fremden Erklärung.
        $this->flushSession();

        $this->get(route('statamic-payments.withdrawal.show', ['payWithdrawal' => $withdrawal->public_id]))
            ->assertRedirect(route('statamic-payments.withdrawal.form'))
            ->assertSessionHas('statamic-payments.portal.status', __('statamic-payments::withdrawal.restart'));
        $this->post(route('statamic-payments.withdrawal.confirm', ['payWithdrawal' => $withdrawal->public_id]))->assertNotFound();

        $this->assertNull($withdrawal->fresh()->confirmed_at);
    }

    #[Test]
    public function an_unknown_reference_is_a_404_and_nothing_else(): void
    {
        $this->get(route('statamic-payments.withdrawal.show', ['payWithdrawal' => 'W-GIBTSNIX']))->assertNotFound();
        $this->post(route('statamic-payments.withdrawal.confirm', ['payWithdrawal' => 'W-GIBTSNIX']))->assertNotFound();
    }

    #[Test]
    public function an_address_with_an_umlaut_is_accepted(): void
    {
        // `email:filter` hätte sie abgelehnt. Wer mit dieser Adresse gekauft
        // hat, muss mit ihr widerrufen können.
        $this->post(route('statamic-payments.withdrawal.declare'), $this->input(['email' => 'bärbel.öztürk@beispiel.de']))
            ->assertRedirect();

        $this->assertSame('bärbel.öztürk@beispiel.de', Withdrawal::first()->email);
    }

    #[Test]
    public function the_form_validates_without_revealing_anything(): void
    {
        $this->from(route('statamic-payments.withdrawal.form'))
            ->post(route('statamic-payments.withdrawal.declare'), $this->input(['email' => 'kein-mail', 'order_reference' => '']))
            ->assertRedirect(route('statamic-payments.withdrawal.form'))
            ->assertSessionHasErrors(['email', 'order_reference']);

        $this->assertSame(0, Withdrawal::count());
    }

    #[Test]
    public function the_posts_are_rate_limited(): void
    {
        // Die Vorgabe: sechs in zehn Minuten, je Adresse. Die siebte ist zu
        // viel — 429, keine siebte Zeile.
        for ($i = 0; $i < 6; $i++) {
            $this->post(route('statamic-payments.withdrawal.declare'), $this->input())->assertRedirect();
        }

        $this->post(route('statamic-payments.withdrawal.declare'), $this->input())->assertStatus(429);

        $this->assertSame(6, Withdrawal::count());
    }

    #[Test]
    public function it_can_be_switched_off(): void
    {
        config()->set('statamic-payments.withdrawal.enabled', false);

        $this->get(route('statamic-payments.withdrawal.form'))->assertNotFound();
        $this->post(route('statamic-payments.withdrawal.declare'), $this->input())->assertNotFound();

        $this->assertNull(Links::withdrawal());
        $this->assertSame('', $this->antlers('{{ payments:withdrawal_url }}'));
    }

    #[Test]
    public function the_tag_and_the_helper_hand_the_footer_its_link(): void
    {
        $this->assertSame(route('statamic-payments.withdrawal.form'), Links::withdrawal());
        $this->assertSame(route('statamic-payments.withdrawal.form'), $this->antlers('{{ payments:withdrawal_url }}'));
        $this->assertSame(route('statamic-payments.cancellation.form'), $this->antlers('{{ payments:cancellation_url }}'));
    }

    /**
     * Als Template geparst, nicht als Nutzerdaten: `Antlers::parse()` ohne das
     * dritte Argument läuft im Modus für Inhalte aus Feldern, und der lässt
     * keine Tags zu — die Ausgabe ist dann leer und ein „Runtime Access
     * Violation" steht im Log.
     */
    protected function antlers(string $template): string
    {
        return (string) Antlers::parse($template, [], true);
    }

    #[Test]
    public function the_statutory_wording_is_a_translation_key_and_not_compiled_in(): void
    {
        $this->assertSame('Vertrag widerrufen', __('statamic-payments::withdrawal.button'));
        $this->assertSame('Widerruf bestätigen', __('statamic-payments::withdrawal.confirm_button'));
    }
}
