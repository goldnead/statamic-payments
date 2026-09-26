<?php

namespace Goldnead\StatamicPayments\Tests\Feature\Portal;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Portal\Display;
use Goldnead\StatamicPayments\Portal\Mail\PortalLinkMail;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;

/**
 * „Beendet am 26.09.2026" next to „Bezahlt bis 26.09.2027" (Staging K2,
 * 26.09.2026): a cancelled annual plan printed the day it was cancelled as
 * the day it ended. The contract ends with the paid term. Until then it is
 * cancelled and runs; only afterwards it ended, on the term's last day.
 */
class CancelledTermEndTest extends TestCase
{
    protected Subscription $subscription;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('app.locale', 'de');
        $app['config']->set('statamic-payments.display_timezone', 'Europe/Berlin');
    }

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('de');
        $this->travelTo(Carbon::parse('2026-09-26 10:00:00', 'UTC'));

        Mail::fake();

        $this->subscription = Subscription::create([
            'provider' => 'fake',
            'provider_id' => 'sub_term',
            'customer_reference' => 'cst_term',
            'product' => 'noten-paket',
            'amount_cent' => 7900,
            'currency' => 'EUR',
            'interval' => '12 months',
            'times_charged' => 1,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => Carbon::parse('2027-09-26 09:00:00', 'UTC'),
            'next_payment_at' => Carbon::parse('2027-09-26 09:00:00', 'UTC'),
            'email' => 'konto1@example.test',
        ]);

        Payment::create([
            'provider' => 'fake',
            'provider_id' => 'tr_term',
            'product' => 'noten-paket',
            'amount_cent' => 7900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'konto1@example.test',
            'paid_at' => Carbon::parse('2026-09-26 09:00:00', 'UTC'),
            'subscription_id' => $this->subscription->getKey(),
        ]);

        $this->gateway->subscriptions['sub_term'] = [
            'customer' => 'cst_term',
            'status' => Subscription::STATUS_ACTIVE,
        ];

        $this->signIn('konto1@example.test');
    }

    #[Test]
    public function a_cancelled_agreement_whose_paid_term_runs_says_until_when_it_runs(): void
    {
        $this->post(route('statamic-payments.portal.cancel.run', ['paySubscription' => $this->subscription->getKey()]))->assertOk();

        $this->get(route('statamic-payments.portal.show'))
            ->assertOk()
            ->assertSee(__('statamic-payments::portal.subscription_runs_until', ['date' => '26.09.2027']))
            ->assertDontSee(__('statamic-payments::portal.subscription_ended', ['date' => '26.09.2026']));
    }

    #[Test]
    public function after_the_paid_term_it_ended_on_the_terms_last_day_not_on_the_day_it_was_cancelled(): void
    {
        $this->post(route('statamic-payments.portal.cancel.run', ['paySubscription' => $this->subscription->getKey()]))->assertOk();

        $this->travelTo(Carbon::parse('2027-10-01 10:00:00', 'UTC'));
        $this->signIn('konto1@example.test');

        $this->get(route('statamic-payments.portal.show'))
            ->assertOk()
            ->assertSee(__('statamic-payments::portal.subscription_ended', ['date' => '26.09.2027']))
            // The order itself is listed on 26.09.2026; the agreement is not
            // said to have ended then.
            ->assertDontSee(__('statamic-payments::portal.subscription_ended', ['date' => '26.09.2026']));
    }

    #[Test]
    public function the_sentence_for_an_app_stands_on_its_own(): void
    {
        $this->post(route('statamic-payments.portal.cancel.run', ['paySubscription' => $this->subscription->getKey()]))->assertOk();
        $cancelled = $this->subscription->fresh();

        $this->assertSame('2027-09-26', $cancelled->endsAt()?->toDateString());
        $this->assertSame('Gekündigt, läuft bis 26.09.2027', Display::ending($cancelled, standalone: true));
        $this->assertSame('Läuft bis 26.09.2027', Display::ending($cancelled));

        $this->travelTo(Carbon::parse('2027-10-01 10:00:00', 'UTC'));
        $this->assertSame('Beendet am 26.09.2027', Display::ending($cancelled, standalone: true));
    }

    #[Test]
    public function a_running_agreement_has_no_end_to_state(): void
    {
        $this->assertNull($this->subscription->endsAt());
        $this->assertNull(Display::ending($this->subscription, standalone: true));
    }

    #[Test]
    public function without_a_paid_term_after_it_the_moment_it_stopped_is_the_end(): void
    {
        // Dunning: the last paid term ran out before the agreement was ended.
        $this->subscription->payments()->update(['paid_at' => Carbon::parse('2025-01-01 09:00:00', 'UTC')]);
        $this->subscription->forceFill([
            'status' => Subscription::STATUS_CANCELLED,
            'cancelled_at' => Carbon::parse('2026-09-20 10:00:00', 'UTC'),
            'ended_at' => Carbon::parse('2026-09-20 10:00:00', 'UTC'),
            'next_payment_at' => null,
        ])->save();

        $this->assertSame('Beendet am 20.09.2026', Display::ending($this->subscription->fresh(), standalone: true));
    }

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
}
