<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Events\SubscriptionCardExpired;
use Goldnead\StatamicPayments\Events\SubscriptionCardExpiring;
use Goldnead\StatamicPayments\Events\SubscriptionPaymentUpcoming;
use Goldnead\StatamicPayments\Mail\SubscriptionReminderMail;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\SubscriptionReminders;
use Goldnead\StatamicPayments\Tests\Support\PausingFakeGateway;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Reminders before a charge and before a card stops working (P3).
 *
 * The property the brief names first: idempotent. A second run, the same day or
 * the next, sends nothing it has already sent.
 */
class SubscriptionRemindersTest extends TestCase
{
    protected PausingFakeGateway $nativ;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'mitgliedschaft' => ['name' => 'Mitgliedschaft', 'amount_cent' => 1900, 'interval' => '1 month'],
            'still' => ['name' => 'Still', 'amount_cent' => 1900, 'interval' => '1 month', 'reminders' => false],
        ]);
        $app['config']->set('statamic-payments.reminders.upcoming.enabled', true);
        $app['config']->set('statamic-payments.reminders.card_expiring.enabled', true);
        $app['config']->set('statamic-payments.reminders.card_expired.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-23 09:00:00');

        $this->gateway = $this->nativ = new PausingFakeGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function abo(array $werte = []): Subscription
    {
        return Subscription::create(array_merge([
            'provider' => 'fake', 'provider_id' => 'sub_'.uniqid(), 'customer_reference' => 'cst_1',
            'product' => 'mitgliedschaft', 'amount_cent' => 1900, 'currency' => 'EUR',
            'interval' => '1 month', 'times_charged' => 1, 'status' => Subscription::STATUS_ACTIVE,
            'next_payment_at' => Carbon::parse('2026-09-28 00:00'),
            'email' => 'wer@example.com', 'name' => 'Kim',
        ], $werte));
    }

    protected function run_(): array
    {
        return app(SubscriptionReminders::class)->run();
    }

    #[Test]
    public function a_charge_within_the_window_is_announced_once_however_often_the_pass_runs(): void
    {
        Mail::fake();
        Event::fake([SubscriptionPaymentUpcoming::class]);
        $abo = $this->abo();

        $this->artisan('payments:reminders')->assertSuccessful();
        $this->artisan('payments:reminders')->assertSuccessful();

        Carbon::setTestNow('2026-09-24 09:00:00');
        $this->artisan('payments:reminders')->assertSuccessful();

        Mail::assertSent(SubscriptionReminderMail::class, 1);
        Mail::assertSent(SubscriptionReminderMail::class, fn ($m) => $m->kind === 'upcoming'
            && $m->hasTo('wer@example.com')
            && $m->variables['date'] === '2026-09-28'
            && str_contains($m->variables['portal_url'], '/konto/link/'));
        Event::assertDispatchedTimes(SubscriptionPaymentUpcoming::class, 1);
        Event::assertDispatched(SubscriptionPaymentUpcoming::class, fn ($e) => $e->subscription->is($abo)
            && $e->dueAt->toDateString() === '2026-09-28'
            && $e->daysBefore === 5);
    }

    #[Test]
    public function the_next_charge_is_a_new_reminder(): void
    {
        Mail::fake();
        $abo = $this->abo();

        $this->run_();
        $abo->forceFill(['next_payment_at' => Carbon::parse('2026-10-28')])->save();
        Carbon::setTestNow('2026-10-25 09:00:00');
        $this->run_();

        Mail::assertSent(SubscriptionReminderMail::class, 2);
    }

    #[Test]
    public function outside_the_window_off_for_the_product_or_paused_nothing_goes_out(): void
    {
        Mail::fake();
        $this->abo(['next_payment_at' => Carbon::parse('2026-10-20')]);
        $this->abo(['product' => 'still']);
        $this->abo(['status' => Subscription::STATUS_PAUSED, 'next_payment_at' => null]);

        $this->run_();

        Mail::assertNothingSent();
    }

    #[Test]
    public function everything_is_off_by_default(): void
    {
        config([
            'statamic-payments.reminders.upcoming.enabled' => false,
            'statamic-payments.reminders.card_expiring.enabled' => false,
            'statamic-payments.reminders.card_expired.enabled' => false,
        ]);
        Mail::fake();
        $this->abo();

        $this->run_();

        Mail::assertNothingSent();
        $this->assertSame(0, $this->nativ->cardAsked, 'the provider was asked about cards while every reminder is off');
    }

    #[Test]
    public function a_card_about_to_expire_is_announced_with_the_portal_link(): void
    {
        Mail::fake();
        Event::fake([SubscriptionCardExpiring::class, SubscriptionCardExpired::class]);
        // 22 days ahead, inside the 30-day window.
        $this->nativ->cardExpiries['cst_1'] = '2026-10-15';
        $abo = $this->abo(['next_payment_at' => Carbon::parse('2026-10-20')]);

        $this->run_();
        $this->run_();

        Mail::assertSent(SubscriptionReminderMail::class, 1);
        Mail::assertSent(SubscriptionReminderMail::class, fn ($m) => $m->kind === 'card_expiring');
        Event::assertDispatchedTimes(SubscriptionCardExpiring::class, 1);
        Event::assertNotDispatched(SubscriptionCardExpired::class);
        $this->assertSame('2026-10-15', $abo->fresh()->card_expires_at->toDateString());
        $this->assertSame(1, $this->nativ->cardAsked, 'the provider was asked again on the same day');
    }

    #[Test]
    public function an_expired_card_is_its_own_reminder(): void
    {
        Mail::fake();
        Event::fake([SubscriptionCardExpired::class]);
        $this->nativ->cardExpiries['cst_1'] = '2026-08-31';
        $this->abo(['next_payment_at' => Carbon::parse('2026-10-20')]);

        $this->run_();

        Mail::assertSent(SubscriptionReminderMail::class, fn ($m) => $m->kind === 'card_expired');
        Event::assertDispatched(SubscriptionCardExpired::class, fn ($e) => $e->expiredAt->toDateString() === '2026-08-31');
    }

    #[Test]
    public function no_card_no_reminder(): void
    {
        Mail::fake();
        $this->abo(['next_payment_at' => Carbon::parse('2026-10-20')]);

        $this->run_();

        Mail::assertNothingSent();
    }

    #[Test]
    public function mail_off_keeps_the_event(): void
    {
        config(['statamic-payments.reminders.upcoming.mail' => false]);
        Mail::fake();
        Event::fake([SubscriptionPaymentUpcoming::class]);
        $this->abo();

        $this->run_();

        Mail::assertNothingSent();
        Event::assertDispatchedTimes(SubscriptionPaymentUpcoming::class, 1);
    }

    #[Test]
    public function a_mail_that_did_not_go_out_is_tried_again_next_time(): void
    {
        Event::fake([SubscriptionPaymentUpcoming::class]);
        $this->abo();

        Mail::shouldReceive('to')->andThrow(new RuntimeException('relay down'));
        $report = $this->run_();

        $this->assertSame(1, $report['failed']);
        Event::assertNotDispatched(SubscriptionPaymentUpcoming::class);

        // The relay is back.
        $this->app->forgetInstance('mail.manager');
        Mail::clearResolvedInstances();
        Mail::fake();
        $this->run_();

        Mail::assertSent(SubscriptionReminderMail::class, 1);
        Event::assertDispatchedTimes(SubscriptionPaymentUpcoming::class, 1);
    }

    #[Test]
    public function the_mail_renders_in_german_with_date_amount_and_link(): void
    {
        app()->setLocale('de');
        $abo = $this->abo();
        $reminders = app(SubscriptionReminders::class);
        $rendered = $reminders->render($abo, 'upcoming', Carbon::parse('2026-09-28'));

        $html = (new SubscriptionReminderMail($abo, 'upcoming', $rendered['subject'], null, $rendered['variables']))->render();

        $this->assertSame('Bald wird Mitgliedschaft abgebucht', $rendered['subject']);
        // The family's transactional mails say "Guten Tag" and "Sie" (invoices,
        // offers, the abandoned-checkout reminder, the portal).
        $this->assertStringContainsString('Guten Tag Kim,', $html);
        $this->assertStringContainsString('Sie', $html);
        $this->assertDoesNotMatchRegularExpression('/\b(du|dein|deine|dir|dich)\b/i', strip_tags($html));
        $this->assertStringContainsString('19,00', $html);
        $this->assertStringContainsString('28.09.2026', $html);
        $this->assertStringContainsString('/konto/link/', $html);
    }

    #[Test]
    public function the_dunning_letter_speaks_the_same_way(): void
    {
        app()->setLocale('de');

        foreach (trans('statamic-payments::dunning') as $key => $text) {
            $this->assertDoesNotMatchRegularExpression('/\b(du|dein|deine|dir|dich)\b/i', (string) $text, $key);
        }
    }

    #[Test]
    public function days_are_counted_in_the_shops_time_zone(): void
    {
        config(['statamic.system.display_timezone' => 'Europe/Berlin', 'statamic-payments.reminders.upcoming.days' => 6]);
        Mail::fake();

        // 23 September, 01:30 in Berlin; the charge is on 29 September, 14:00
        // in Berlin. Six days there, seven in UTC.
        Carbon::setTestNow(Carbon::parse('2026-09-22 23:30:00', 'UTC'));
        $this->abo(['next_payment_at' => Carbon::parse('2026-09-29 12:00:00', 'UTC')]);

        $this->run_();

        Mail::assertSent(SubscriptionReminderMail::class, fn ($m) => $m->variables['date'] === '2026-09-29');
    }
}
