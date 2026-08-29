<?php

namespace Goldnead\StatamicPayments\Tests\Feature\Portal;

use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Portal\Mail\CancellationConfirmed;
use Goldnead\StatamicPayments\Portal\Mail\PortalLinkMail;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;

/**
 * Ending an agreement, and the two ways it must not end.
 *
 * Two separate promises are under test here and they are easy to conflate:
 *
 * 1. **The § 312k shape.** A button with the prescribed wording, a confirmation
 *    page with a second prescribed wording, and a confirmation in Textform
 *    carrying the date and the time.
 * 2. **The provider decides.** Nothing local is written unless the provider
 *    confirmed. A screen that says "cancelled" on a local flag is how somebody
 *    keeps being charged for a thing their account says they ended.
 *
 * The second is the one with teeth, and it is tested from both failure shapes a
 * provider actually produces: the one that will not answer, and the one that
 * answers and is still charging.
 */
class CancellationTest extends TestCase
{
    protected Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->subscription = Subscription::create([
            'provider' => 'fake',
            'provider_id' => 'sub_1',
            'customer_reference' => 'cst_1',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'interval' => '1 month',
            'times_charged' => 3,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subMonths(3),
            'next_payment_at' => now()->addMonth(),
            'email' => 'anna@example.de',
        ]);

        $this->gateway->subscriptions['sub_1'] = [
            'customer' => 'cst_1',
            'status' => Subscription::STATUS_ACTIVE,
        ];

        $this->signIn('anna@example.de');
    }

    #[Test]
    public function the_cancellation_button_has_its_own_public_url(): void
    {
        // § 312k wants the button reachable without logging in and without
        // searching. Whoever presses it cannot yet prove the contract is theirs,
        // so what it leads to is the identification form.
        $this->flush();

        $this->get(route('statamic-payments.portal.cancel.entry'))
            ->assertOk()
            ->assertSee(__('statamic-payments::portal.cancel_entry_title'));
    }

    #[Test]
    public function the_overview_carries_the_prescribed_button(): void
    {
        $this->get(route('statamic-payments.portal.show'))
            ->assertOk()
            ->assertSee('Verträge hier kündigen')
            ->assertSee(route('statamic-payments.portal.cancel.confirm', ['paySubscription' => $this->subscription->getKey()]));
    }

    #[Test]
    public function the_confirmation_page_names_the_contract_and_carries_the_second_button(): void
    {
        $this->get(route('statamic-payments.portal.cancel.confirm', ['paySubscription' => $this->subscription->getKey()]))
            ->assertOk()
            ->assertSee('Notenpaket')
            ->assertSee('19.00')
            ->assertSee('Jetzt kündigen');
    }

    #[Test]
    public function every_prescribed_word_can_be_replaced_without_touching_the_code(): void
    {
        // The whole reason the wording lives in a translation file: it goes in
        // front of a lawyer, the statute has been amended once, and a firm that
        // wants a different phrase cannot be told to wait for a release.
        Lang::addLines([
            'portal.cancel_button' => 'Hier Vertrag beenden',
            'portal.cancel_now' => 'Verbindlich beenden',
        ], app()->getLocale(), 'statamic-payments');

        $this->get(route('statamic-payments.portal.show'))
            ->assertSee('Hier Vertrag beenden')
            ->assertDontSee('Verträge hier kündigen');

        $this->get(route('statamic-payments.portal.cancel.confirm', ['paySubscription' => $this->subscription->getKey()]))
            ->assertSee('Verbindlich beenden')
            ->assertDontSee('Jetzt kündigen');
    }

    #[Test]
    public function pressing_it_asks_the_provider_and_writes_what_the_provider_said(): void
    {
        $this->post(route('statamic-payments.portal.cancel.run', ['paySubscription' => $this->subscription->getKey()]))
            ->assertOk()
            ->assertSee(__('statamic-payments::portal.cancelled_title'));

        $this->assertSame(['sub_1'], $this->gateway->cancelled);

        $fresh = $this->subscription->fresh();

        $this->assertSame(Subscription::STATUS_CANCELLED, $fresh->status);
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertNull($fresh->next_payment_at);
    }

    #[Test]
    public function the_confirmation_is_in_textform_and_carries_the_date_and_the_time(): void
    {
        $this->post(route('statamic-payments.portal.cancel.run', ['paySubscription' => $this->subscription->getKey()]));

        $moment = $this->subscription->fresh()->cancelled_at;

        Mail::assertSent(CancellationConfirmed::class, function (CancellationConfirmed $mail) use ($moment) {
            $rendered = $mail->render();

            return $mail->hasTo('anna@example.de')
                // The two facts § 312k Abs. 2 S. 4 names, in the mail and not
                // only on a screen that is gone on reload.
                && str_contains($rendered, $moment->translatedFormat(__('statamic-payments::portal.date_format')))
                && str_contains($rendered, $moment->translatedFormat(__('statamic-payments::portal.time_format')))
                && str_contains($rendered, 'Notenpaket');
        });
    }

    #[Test]
    public function the_screen_and_the_mail_state_one_moment_and_not_two(): void
    {
        $this->travelTo(now()->startOfMinute());

        $screen = $this->post(route('statamic-payments.portal.cancel.run', ['paySubscription' => $this->subscription->getKey()]));

        $moment = $this->subscription->fresh()->cancelled_at;

        // Read back off the row rather than off the clock. Three timestamps for
        // one event, differing by however long the mailer took, is what reading
        // `now()` in three places produces.
        $screen->assertSee($moment->translatedFormat(__('statamic-payments::portal.date_format')));
        $screen->assertSee($moment->translatedFormat(__('statamic-payments::portal.time_format')));
    }

    #[Test]
    public function a_provider_that_will_not_answer_changes_nothing(): void
    {
        $this->gateway->refuseToCancel = true;

        $this->post(route('statamic-payments.portal.cancel.run', ['paySubscription' => $this->subscription->getKey()]))
            ->assertRedirect(route('statamic-payments.portal.cancel.confirm', ['paySubscription' => $this->subscription->getKey()]))
            ->assertSessionHas('statamic-payments.portal.error');

        $fresh = $this->subscription->fresh();

        // Nothing. Not the status, not the dates.
        $this->assertSame(Subscription::STATUS_ACTIVE, $fresh->status);
        $this->assertNull($fresh->cancelled_at);
        $this->assertNull($fresh->ended_at);
        $this->assertNotNull($fresh->next_payment_at);

        // And no confirmation for a cancellation that did not happen. A buyer
        // who is told they are done stops watching their statement.
        Mail::assertNotSent(CancellationConfirmed::class);
    }

    #[Test]
    public function a_provider_that_says_yes_and_keeps_charging_changes_nothing(): void
    {
        // The nastier of the two: the call succeeds, the answer comes back, and
        // the agreement is still running at the other end. Believing the call
        // rather than the answer is how somebody keeps being charged.
        $this->gateway->cancelLies = true;

        $this->post(route('statamic-payments.portal.cancel.run', ['paySubscription' => $this->subscription->getKey()]))
            ->assertSessionHas('statamic-payments.portal.error');

        $this->assertSame(Subscription::STATUS_ACTIVE, $this->subscription->fresh()->status);
        $this->assertNull($this->subscription->fresh()->cancelled_at);

        Mail::assertNotSent(CancellationConfirmed::class);
    }

    #[Test]
    public function a_buyer_cannot_cancel_somebody_elses_contract(): void
    {
        $someoneElse = Subscription::create([
            'provider' => 'fake',
            'provider_id' => 'sub_2',
            'customer_reference' => 'cst_2',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'interval' => '1 month',
            'times_charged' => 0,
            'status' => Subscription::STATUS_ACTIVE,
            'email' => 'boris@example.de',
        ]);

        $this->post(route('statamic-payments.portal.cancel.run', ['paySubscription' => $someoneElse->getKey()]))
            ->assertNotFound();

        $this->assertSame(Subscription::STATUS_ACTIVE, $someoneElse->fresh()->status);
        $this->assertSame([], $this->gateway->cancelled);
    }

    #[Test]
    public function pressing_it_twice_confirms_rather_than_cancelling_again(): void
    {
        $this->post(route('statamic-payments.portal.cancel.run', ['paySubscription' => $this->subscription->getKey()]))->assertOk();

        $first = $this->subscription->fresh()->cancelled_at;

        $this->post(route('statamic-payments.portal.cancel.run', ['paySubscription' => $this->subscription->getKey()]))
            ->assertOk()
            ->assertSee(__('statamic-payments::portal.cancelled_title'));

        // The provider was asked once. The date of the cancellation is the date
        // of the cancellation, not of the second press.
        $this->assertSame(['sub_1'], $this->gateway->cancelled);
        $this->assertEquals($first, $this->subscription->fresh()->cancelled_at);
        Mail::assertSent(CancellationConfirmed::class, 1);
    }

    #[Test]
    public function a_cancellation_cannot_be_triggered_by_following_a_link(): void
    {
        // A GET that cancels is a GET a mail client's link prefetcher will press
        // on somebody's behalf.
        $this->get(route('statamic-payments.portal.cancel.confirm', ['paySubscription' => $this->subscription->getKey()]))
            ->assertOk();

        $this->assertSame(Subscription::STATUS_ACTIVE, $this->subscription->fresh()->status);
        $this->assertSame([], $this->gateway->cancelled);
    }

    /** Go in the way a buyer does: ask for a link, follow it out of the mail. */
    protected function signIn(string $email): void
    {
        Mail::fake();

        $this->post(route('statamic-payments.portal.request.send'), ['email' => $email]);

        $url = null;

        Mail::assertSent(PortalLinkMail::class, function (PortalLinkMail $mail) use (&$url) {
            $url ??= $mail->url;

            return true;
        });

        $this->get((string) $url)->assertRedirect(route('statamic-payments.portal.show'));

        Mail::fake();
    }

    protected function flush(): void
    {
        $this->flushSession();
    }
}
