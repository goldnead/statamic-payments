<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Portal\Display;
use Goldnead\StatamicPayments\Portal\LinkTokenizer;
use Goldnead\StatamicPayments\Support\SubscriptionReminders;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * What a buyer and an operator are shown while a coupon runs (Gauntlet 2, point 4).
 *
 * The agreement costs 20 euro; a coupon takes 4 off charges 1 to 3. The first
 * charge has been paid, so the next one (number 2, on 5 October) and the one
 * after (number 3, on 5 November) cost 16.
 */
class CouponDisplayTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'mitgliedschaft' => ['name' => 'Mitgliedschaft', 'amount_cent' => 2000, 'interval' => '1 month'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-30 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function abo(): Subscription
    {
        return Subscription::create([
            'provider' => 'fake', 'provider_id' => 'sub_1', 'customer_reference' => 'cst_1',
            'product' => 'mitgliedschaft', 'amount_cent' => 2000, 'currency' => 'EUR',
            'interval' => '1 month', 'times_charged' => 0, 'status' => Subscription::STATUS_ACTIVE,
            'next_payment_at' => Carbon::parse('2026-10-05 00:00'),
            'email' => 'wer@example.com', 'name' => 'Kim',
            'meta' => ['coupon' => [
                'code' => 'CHOR20', 'percent' => 20, 'amount_cent' => null, 'currency' => null,
                'duration' => 'repeating', 'cycles' => 3, 'floor_cent' => null, 'current_discount_cent' => 400,
            ]],
        ]);
    }

    #[Test]
    public function the_agreement_knows_how_long_its_coupon_runs(): void
    {
        $summary = $this->abo()->couponSummary();

        $this->assertSame('CHOR20', $summary['code']);
        $this->assertSame(400, $summary['discount_cent']);
        $this->assertSame('2026-11-05', $summary['until']?->toDateString());
        $this->assertFalse($summary['forever']);
    }

    #[Test]
    public function the_reminder_names_what_is_actually_charged_and_the_coupon(): void
    {
        $abo = $this->abo();
        $variables = app(SubscriptionReminders::class)->variables($abo, 'upcoming', Carbon::parse('2026-10-05'));

        $this->assertSame(Display::money(1600, 'EUR'), $variables['plan']['display']);
        $this->assertStringContainsString('CHOR20', $variables['plan']['coupon']);
        $this->assertStringContainsString(
            Carbon::parse('2026-11-05')->translatedFormat(__('statamic-payments::portal.date_format')),
            $variables['plan']['coupon'],
        );
    }

    #[Test]
    public function the_reminder_link_works_until_the_charge(): void
    {
        $abo = $this->abo();
        $url = app(SubscriptionReminders::class)->variables($abo, 'upcoming', Carbon::parse('2026-10-05'))['portal_url'];

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertGreaterThanOrEqual(Carbon::parse('2026-10-05 23:59:00')->getTimestamp(), (int) $query['expires'],
            'the link in a mail sent five days ahead expired after thirty minutes');
        $this->assertNotNull(app(LinkTokenizer::class)->open(basename((string) parse_url($url, PHP_URL_PATH))));
    }

    #[Test]
    public function the_portal_shows_the_charged_amount_and_the_coupon(): void
    {
        $abo = $this->abo();

        $response = $this->get(app(LinkTokenizer::class)->issue('wer@example.com', 0));
        $response->assertRedirect(route('statamic-payments.portal.show'));

        $this->get(route('statamic-payments.portal.show'))
            ->assertOk()
            ->assertSee(Display::money(1600, 'EUR'))
            ->assertSee('CHOR20');
    }

    #[Test]
    public function the_cp_row_charges_what_the_provider_charges(): void
    {
        $this->abo();
        $user = tap(User::make()->email('cp@example.com')->makeSuper())->save();

        $row = $this->actingAs($user)->getJson('/cp/utilities/subscriptions')->json('data.0');

        $this->assertSame('16.00', $row['amount']);
        $this->assertStringContainsString('CHOR20', (string) $row['coupon']);
        // The field is labelled "Gutschein" already; the value does not say it again.
        $this->assertStringStartsWith('CHOR20', (string) $row['coupon']);
        // A charge falls on a day, not at midnight.
        $this->assertStringNotContainsString(':', (string) $row['next_payment_at_display']);
    }
}
