<?php

namespace Goldnead\StatamicPayments\Tests\Feature\Portal;

use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Portal\Mail\CancellationConfirmed;
use Goldnead\StatamicPayments\Portal\Mail\PortalLinkMail;
use Goldnead\StatamicPayments\Support\LocalTime;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;

/**
 * What the cancellation screens say about time (Gesamtprüfung 25.09.2026).
 *
 * Three findings on one screen (shots/G-10d, G-10e):
 *
 * - „Beginn 25.09.2027" for an annual plan bought on 25.09.2026: the row
 *   printed `starts_at`, which is when the provider's rhythm starts (one
 *   interval after the first, already paid charge), not when the contract
 *   began.
 * - „Die Kündigung wirkt sofort" next to a checkout that promised the end of
 *   the paid term. The agreement stops charging at once; the paid period is
 *   kept to its end. The screen now says that, with the date.
 * - „um 19:07 Uhr" at 21:07 in Berlin: the time was formatted in
 *   `app.timezone` (UTC). It is shown in the display zone now, the database
 *   keeps UTC, and `app.timezone` is never turned.
 */
class CancellationDatesAndZoneTest extends TestCase
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

        // 19:07 UTC is 21:07 in Berlin in September (summer time).
        $this->travelTo(Carbon::parse('2026-09-25 19:07:00', 'UTC'));

        Mail::fake();

        $this->subscription = Subscription::create([
            'provider' => 'fake',
            'provider_id' => 'sub_zone',
            'customer_reference' => 'cst_zone',
            'product' => 'noten-paket',
            'amount_cent' => 7900,
            'currency' => 'EUR',
            'interval' => '12 months',
            'times_charged' => 0,
            'status' => Subscription::STATUS_ACTIVE,
            // The provider's rhythm: one interval after the first charge.
            'starts_at' => Carbon::parse('2027-09-25 18:02:00', 'UTC'),
            'next_payment_at' => Carbon::parse('2027-09-25 18:02:00', 'UTC'),
            'email' => 'konto1@example.test',
        ]);

        // Bought the evening before, late enough that UTC and Berlin disagree
        // on the day: 22:30 UTC on the 23rd is 00:30 on the 24th in Berlin.
        DB::table((new Subscription)->getTable())->where('id', $this->subscription->id)
            ->update(['created_at' => '2025-09-23 22:30:00']);

        $this->gateway->subscriptions['sub_zone'] = [
            'customer' => 'cst_zone',
            'status' => Subscription::STATUS_ACTIVE,
        ];

        $this->signIn('konto1@example.test');
    }

    #[Test]
    public function the_guard_the_display_zone_is_not_the_application_zone(): void
    {
        // Without this the rest proves nothing: with both zones equal every
        // time would read the same either way.
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('Europe/Berlin', LocalTime::zone());
    }

    #[Test]
    public function the_confirmation_page_shows_when_the_contract_began_not_when_the_provider_charges_next(): void
    {
        $this->get(route('statamic-payments.portal.cancel.confirm', ['paySubscription' => $this->subscription->getKey()]))
            ->assertOk()
            ->assertSeeInOrder([__('statamic-payments::portal.cancel_started'), '24.09.2025'])
            ->assertDontSee('23.09.2025');
    }

    #[Test]
    public function the_confirmation_page_says_the_contract_ends_with_the_paid_term_not_at_once(): void
    {
        $this->get(route('statamic-payments.portal.cancel.confirm', ['paySubscription' => $this->subscription->getKey()]))
            ->assertOk()
            ->assertSee(__('statamic-payments::portal.cancel_effect_until', ['date' => '25.09.2027']))
            ->assertDontSee('wirkt sofort');
    }

    #[Test]
    public function the_cancelled_screen_states_the_time_in_the_display_zone(): void
    {
        $screen = $this->post(route('statamic-payments.portal.cancel.run', ['paySubscription' => $this->subscription->getKey()]))
            ->assertOk();

        $screen->assertSee('21:07')->assertDontSee('19:07');
        $screen->assertSee(__('statamic-payments::portal.cancelled_until', ['date' => '25.09.2027']));

        // Stored as UTC wall clock, unchanged.
        $raw = DB::table((new Subscription)->getTable())->where('id', $this->subscription->id)->value('cancelled_at');
        $this->assertStringContainsString('19:07', (string) $raw);
    }

    #[Test]
    public function the_confirmation_mail_states_the_time_in_the_display_zone_and_the_end_of_the_term(): void
    {
        $this->post(route('statamic-payments.portal.cancel.run', ['paySubscription' => $this->subscription->getKey()]));

        Mail::assertSent(CancellationConfirmed::class, function (CancellationConfirmed $mail) {
            $rendered = $mail->render();

            return str_contains($rendered, '21:07')
                && ! str_contains($rendered, '19:07')
                && str_contains($rendered, '25.09.2027')
                && ! str_contains($rendered, 'sofort wirksam');
        });
    }

    #[Test]
    public function an_unknown_zone_falls_back_instead_of_breaking_the_page(): void
    {
        config(['statamic-payments.display_timezone' => 'Mars/Olympus']);
        config(['statamic.system.display_timezone' => null]);

        $this->assertSame('UTC', LocalTime::zone());
    }

    #[Test]
    public function without_an_own_value_the_zone_follows_statamic_then_the_application(): void
    {
        config(['statamic-payments.display_timezone' => null]);
        config(['statamic-payments.legal.timezone' => null]);
        config(['statamic.system.display_timezone' => 'Europe/Vienna']);
        $this->assertSame('Europe/Vienna', LocalTime::zone());

        config(['statamic.system.display_timezone' => null]);
        $this->assertSame('UTC', LocalTime::zone());
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
