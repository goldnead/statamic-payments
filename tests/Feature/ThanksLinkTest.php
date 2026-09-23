<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\ThanksLink;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * The thank-you page that expires (P5).
 */
class ThanksLinkTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function redirectUrl(): string
    {
        $result = app(Checkout::class)->start('noten-paket', ['email' => 'kim@example.com']);

        $this->assertNotNull($result);

        return (string) $this->gateway->lastPayload['redirectUrl'];
    }

    #[Test]
    public function off_by_default_the_provider_sends_the_buyer_straight_to_the_page(): void
    {
        $this->assertStringEndsWith('/danke?payment=1', $this->redirectUrl());
        $this->assertTrue(app(ThanksLink::class)->state()['valid'], 'a page using the tag went blank on a site without the setting');
    }

    #[Test]
    public function on_the_buyer_comes_back_through_a_signed_link_that_admits_them(): void
    {
        config(['statamic-payments.thanks.expires_minutes' => 30]);
        Carbon::setTestNow('2026-09-23 10:00:00');

        $url = $this->redirectUrl();
        $this->assertStringContainsString('/!/statamic-payments/danke/1', $url);
        $this->assertStringContainsString('signature=', $url);

        // Paid, as the provider says before it sends the buyer back.
        $this->gateway->markPaid('tr_1');
        $this->postJson(route('statamic-payments.webhook'), ['id' => 'tr_1'])->assertOk();

        $this->get($url)->assertRedirect(url('/danke?payment=1'));

        $state = app(ThanksLink::class)->state(request());
        $this->assertTrue($state['valid']);
        $this->assertTrue($state['paid']);
        $this->assertSame(1, $state['payment_id']);

        // The page itself stops being theirs after the same time.
        Carbon::setTestNow('2026-09-23 10:31:00');
        $this->assertFalse(app(ThanksLink::class)->state(request())['valid']);
    }

    #[Test]
    public function an_unpaid_order_is_not_valid_but_says_it_is_pending(): void
    {
        config(['statamic-payments.thanks.expires_minutes' => 30]);
        $url = $this->redirectUrl();

        $this->get($url)->assertRedirect();

        $state = app(ThanksLink::class)->state(request());
        $this->assertFalse($state['valid'], 'a SEPA order not yet paid opened the download');
        $this->assertFalse($state['paid']);
        $this->assertTrue($state['pending']);
    }

    #[Test]
    public function the_window_starts_at_the_first_visit_not_at_the_checkout(): void
    {
        config(['statamic-payments.thanks.expires_minutes' => 30]);
        Carbon::setTestNow('2026-09-23 10:00:00');
        $url = $this->redirectUrl();

        // A SEPA buyer comes back two hours later: still theirs.
        Carbon::setTestNow('2026-09-23 12:00:00');
        $this->get($url)->assertRedirect(url('/danke?payment=1'));

        // Reopening the same link inside the window works again.
        Carbon::setTestNow('2026-09-23 12:20:00');
        $this->get($url)->assertRedirect(url('/danke?payment=1'));

        // After the window it does not, however long the signature still runs.
        Carbon::setTestNow('2026-09-23 12:31:00');
        $this->get($url)->assertStatus(410);
    }

    #[Test]
    public function a_link_opened_too_late_lands_on_the_expired_page(): void
    {
        config(['statamic-payments.thanks.expires_minutes' => 30]);
        Carbon::setTestNow('2026-09-23 10:00:00');
        $url = $this->redirectUrl();

        // The signature runs 24 hours; after that nobody is admitted.
        Carbon::setTestNow('2026-09-24 10:01:00');

        $this->get($url)
            ->assertStatus(410)
            ->assertSee(__('statamic-payments::checkout.thanks_expired_title'));
    }

    #[Test]
    public function the_target_cannot_be_swapped(): void
    {
        config(['statamic-payments.thanks.expires_minutes' => 30]);
        $url = $this->redirectUrl();

        $this->get(str_replace(urlencode('/danke?payment=1'), urlencode('https://evil.example/'), $url))
            ->assertStatus(410);
    }

    #[Test]
    public function an_expired_link_can_go_to_a_page_of_the_sites_own(): void
    {
        config([
            'statamic-payments.thanks.expires_minutes' => 30,
            'statamic-payments.thanks.expired_url' => '/link-abgelaufen',
        ]);
        Carbon::setTestNow('2026-09-23 10:00:00');
        $url = $this->redirectUrl();
        Carbon::setTestNow('2026-09-24 10:05:00');

        $this->get($url)->assertRedirect('/link-abgelaufen');
    }

    #[Test]
    public function a_visitor_without_the_note_is_not_valid(): void
    {
        config(['statamic-payments.thanks.expires_minutes' => 30]);

        $this->assertFalse(app(ThanksLink::class)->state()['valid']);
    }
}
