<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * One format for every date and amount in the subscription detail (Gauntlet 2,
 * point 8): the shop's display time zone and the reader's language, worked out
 * on the server, rather than whatever the browser's locale makes of an ISO
 * string ("9/20/2026, 12:00 PM" next to "01.11.2026").
 */
class CpSubscriptionDisplayTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.locale', 'de');
        $app['config']->set('statamic.system.display_timezone', 'Europe/Berlin');
        $app['config']->set('statamic-payments.products', [
            'mitgliedschaft' => ['name' => 'Mitgliedschaft', 'amount_cent' => 1900, 'interval' => '1 month'],
        ]);
    }

    #[Test]
    public function dates_and_amounts_arrive_formatted_for_the_reader(): void
    {
        Subscription::create([
            'provider' => 'fake', 'provider_id' => 'sub_1', 'customer_reference' => 'cst_1',
            'product' => 'mitgliedschaft', 'amount_cent' => 1900, 'currency' => 'EUR',
            'interval' => '1 month', 'times_charged' => 1, 'status' => Subscription::STATUS_PAUSED,
            'starts_at' => Carbon::parse('2026-04-14 14:57:00', 'UTC'),
            'paused_at' => Carbon::parse('2026-09-20 10:00:00', 'UTC'),
            'resumes_at' => Carbon::parse('2026-10-31 23:00:00', 'UTC'),
            'email' => 'wer@example.com',
        ]);

        $user = tap(User::make()->email('cp@example.com')->makeSuper())->save();
        $row = $this->actingAs($user)->getJson('/cp/utilities/subscriptions')->json('data.0');

        $this->assertSame('14.04.2026 16:57', $row['starts_at_display']);
        $this->assertSame('20.09.2026 12:00', $row['paused_at_display']);
        // A day, and the day in Berlin: 23:00 UTC is already 1 November there.
        $this->assertSame('01.11.2026', $row['resumes_at']);
        $this->assertSame('19,00 EUR', $row['amount_display']);
    }
}
