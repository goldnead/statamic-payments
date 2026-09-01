<?php

namespace Goldnead\StatamicPayments\Tests\Feature\Legal;

use Goldnead\StatamicPayments\Legal\Mail\CancellationNotice;
use Goldnead\StatamicPayments\Legal\Mail\CancellationReceipt;
use Goldnead\StatamicPayments\Models\Cancellation;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;

/**
 * Der Kündigungsbutton nach § 312k BGB, ohne Login.
 *
 * Wie beim Widerruf, plus die eine Sache, die hier mehr passiert: ein
 * eindeutig zugeordnetes laufendes Abo wird beim Anbieter gekündigt — über
 * denselben Weg wie im Portal, Anbieter zuerst. Mehrdeutig heißt: nicht
 * gekündigt, aber gemeldet, und der Verbraucher hat seine Bestätigung trotzdem.
 */
class CancellationButtonTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.cancellation.notify', 'shop@example.com');
    }

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('de');

        Mail::fake();
    }

    protected function subscription(array $overrides = []): Subscription
    {
        $subscription = Subscription::create(array_merge([
            'provider' => 'fake',
            'provider_id' => 'sub_'.uniqid(),
            'customer_reference' => 'cst_1',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'interval' => '1 month',
            'times_charged' => 2,
            'status' => Subscription::STATUS_ACTIVE,
            'next_payment_at' => now()->addMonth(),
            'email' => 'anna@example.de',
        ], $overrides));

        $this->gateway->subscriptions[$subscription->provider_id] = [
            'customer' => 'cst_1',
            'status' => $subscription->status,
        ];

        return $subscription;
    }

    /** @return array<string, string> */
    protected function input(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Anna Beispiel',
            'email' => 'ANNA@example.de',
            'identification' => 'sub_1',
            'kind' => 'ordinary',
            'reason' => '',
            'effective_at' => '',
        ], $overrides);
    }

    #[Test]
    public function the_form_carries_the_statutory_button_wording(): void
    {
        $this->get(route('statamic-payments.cancellation.form'))
            ->assertOk()
            ->assertSee('Verträge hier kündigen')
            ->assertSee('name="kind"', false);
    }

    #[Test]
    public function the_confirmation_page_names_the_declaration_and_carries_jetzt_kuendigen(): void
    {
        $this->post(route('statamic-payments.cancellation.declare'), $this->input(['effective_at' => '2026-12-31']));
        $cancellation = Cancellation::first();

        $this->assertStringStartsWith('K-', $cancellation->public_id);
        $this->assertSame('2026-12-31', $cancellation->effective_at->toDateString());

        $this->get(route('statamic-payments.cancellation.show', ['payCancellation' => $cancellation->public_id]))
            ->assertOk()
            ->assertSee('Ordentliche Kündigung')
            ->assertSee('sub_1')
            ->assertSee('31.12.2026')
            ->assertSee('jetzt kündigen');

        Mail::assertNothingSent();
    }

    #[Test]
    public function an_extraordinary_cancellation_needs_a_reason(): void
    {
        $this->from(route('statamic-payments.cancellation.form'))
            ->post(route('statamic-payments.cancellation.declare'), $this->input(['kind' => 'extraordinary']))
            ->assertRedirect(route('statamic-payments.cancellation.form'))
            ->assertSessionHasErrors(['reason']);

        $this->assertSame(0, Cancellation::count());
    }

    #[Test]
    public function confirming_cancels_the_unambiguous_running_subscription_at_the_provider(): void
    {
        $this->travelTo(now()->startOfSecond());

        $subscription = $this->subscription(['provider_id' => 'sub_1']);

        $this->post(route('statamic-payments.cancellation.declare'), $this->input());
        $cancellation = Cancellation::first();

        $this->post(route('statamic-payments.cancellation.confirm', ['payCancellation' => $cancellation->public_id]))
            ->assertRedirect(route('statamic-payments.cancellation.show', ['payCancellation' => $cancellation->public_id]));

        $fresh = $cancellation->fresh();

        $this->assertTrue(now()->equalTo($fresh->confirmed_at));
        $this->assertSame($subscription->getKey(), $fresh->subscription_id);
        $this->assertNotNull($fresh->provider_cancelled_at);

        // Der Anbieter wurde gefragt und die Zeile sagt, was er antwortete.
        $this->assertSame(['sub_1'], $this->gateway->cancelled);
        $this->assertSame(Subscription::STATUS_CANCELLED, $subscription->fresh()->status);
        $this->assertNull($subscription->fresh()->next_payment_at);

        Mail::assertSent(CancellationReceipt::class, function (CancellationReceipt $mail) use ($fresh) {
            $rendered = $mail->render();

            return $mail->hasTo('ANNA@example.de')
                && str_contains($rendered, $fresh->public_id)
                && str_contains($rendered, $fresh->confirmed_at->translatedFormat('d.m.Y'))
                && str_contains($rendered, $fresh->confirmed_at->translatedFormat('H:i'))
                && str_contains($rendered, 'frühestmöglich');
        });

        Mail::assertSent(CancellationNotice::class, fn (CancellationNotice $m) => $m->hasTo('shop@example.com') && str_contains($m->render(), 'beim Zahlungsdienstleister gekündigt'));

        // Schritt 3: Datum, Uhrzeit, genannter Zeitpunkt. Kein Name.
        $this->get(route('statamic-payments.cancellation.show', ['payCancellation' => $fresh->public_id]))
            ->assertOk()
            ->assertSee($fresh->public_id)
            ->assertSee($fresh->confirmed_at->translatedFormat('H:i'))
            ->assertSee('frühestmöglich')
            ->assertDontSee('Anna Beispiel');
    }

    #[Test]
    public function an_ambiguous_match_cancels_nothing_but_reports_and_acknowledges(): void
    {
        // Zwei laufende Abos derselben Adresse, und die Kennung trifft beide:
        // die Id des ersten ist die provider_id des zweiten.
        $first = $this->subscription(['provider_id' => 'sub_erstes']);
        $this->subscription(['provider_id' => (string) $first->getKey()]);

        $this->post(route('statamic-payments.cancellation.declare'), $this->input(['identification' => (string) $first->getKey()]));
        $cancellation = Cancellation::first();

        $this->post(route('statamic-payments.cancellation.confirm', ['payCancellation' => $cancellation->public_id]));

        $fresh = $cancellation->fresh();

        $this->assertNull($fresh->subscription_id);
        $this->assertNull($fresh->provider_cancelled_at);
        $this->assertSame([], $this->gateway->cancelled);
        $this->assertSame(2, Subscription::query()->where('status', Subscription::STATUS_ACTIVE)->count());

        Mail::assertSent(CancellationReceipt::class, fn (CancellationReceipt $m) => $m->hasTo('ANNA@example.de'));
        Mail::assertSent(CancellationNotice::class, fn (CancellationNotice $m) => str_contains($m->render(), 'Kein eindeutiges Abo'));
    }

    #[Test]
    public function a_provider_that_will_not_cancel_leaves_the_row_honest(): void
    {
        $this->subscription(['provider_id' => 'sub_1']);
        $this->gateway->refuseToCancel = true;

        $this->post(route('statamic-payments.cancellation.declare'), $this->input());
        $cancellation = Cancellation::first();

        $this->post(route('statamic-payments.cancellation.confirm', ['payCancellation' => $cancellation->public_id]));

        $fresh = $cancellation->fresh();

        // Zugeordnet, aber nicht gekündigt — und genau so gemeldet.
        $this->assertNotNull($fresh->subscription_id);
        $this->assertNull($fresh->provider_cancelled_at);
        $this->assertNotNull($fresh->confirmed_at);
        Mail::assertSent(CancellationNotice::class, fn (CancellationNotice $m) => str_contains($m->render(), 'nicht beim Zahlungsdienstleister gekündigt'));
        Mail::assertSent(CancellationReceipt::class, 1);
    }

    #[Test]
    public function an_already_ended_subscription_is_matched_but_not_cancelled_again(): void
    {
        $this->subscription(['provider_id' => 'sub_1', 'status' => Subscription::STATUS_CANCELLED, 'cancelled_at' => now()->subMonth()]);

        $this->post(route('statamic-payments.cancellation.declare'), $this->input());
        $cancellation = Cancellation::first();

        $this->post(route('statamic-payments.cancellation.confirm', ['payCancellation' => $cancellation->public_id]));

        $this->assertNotNull($cancellation->fresh()->subscription_id);
        $this->assertNull($cancellation->fresh()->provider_cancelled_at);
        $this->assertSame([], $this->gateway->cancelled);
    }

    #[Test]
    public function confirming_twice_is_one_cancellation(): void
    {
        $this->subscription(['provider_id' => 'sub_1']);

        $this->post(route('statamic-payments.cancellation.declare'), $this->input());
        $cancellation = Cancellation::first();

        $this->post(route('statamic-payments.cancellation.confirm', ['payCancellation' => $cancellation->public_id]));
        $first = $cancellation->fresh()->confirmed_at;

        $this->travel(3)->minutes();
        $this->post(route('statamic-payments.cancellation.confirm', ['payCancellation' => $cancellation->public_id]))->assertRedirect();

        $this->assertEquals($first, $cancellation->fresh()->confirmed_at);
        $this->assertSame(['sub_1'], $this->gateway->cancelled);
        Mail::assertSent(CancellationReceipt::class, 1);
    }

    #[Test]
    public function the_portal_way_stays_in_place(): void
    {
        // Der Komfortweg für Eingeloggte bleibt; der neue Weg kommt dazu.
        $this->get(route('statamic-payments.portal.cancel.entry'))->assertOk();
    }

    #[Test]
    public function it_can_be_switched_off(): void
    {
        config()->set('statamic-payments.cancellation.enabled', false);

        $this->get(route('statamic-payments.cancellation.form'))->assertNotFound();
        $this->post(route('statamic-payments.cancellation.declare'), $this->input())->assertNotFound();
    }

    #[Test]
    public function the_statutory_wording_is_a_translation_key(): void
    {
        $this->assertSame('Verträge hier kündigen', __('statamic-payments::cancellation.button'));
        $this->assertSame('jetzt kündigen', __('statamic-payments::cancellation.confirm_button'));
    }
}
