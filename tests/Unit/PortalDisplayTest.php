<?php

namespace Goldnead\StatamicPayments\Tests\Unit;

use Goldnead\StatamicPayments\Portal\Display;
use Goldnead\StatamicPayments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * How a number and a rhythm read on a page a buyer opens.
 *
 * Both of these were wrong in the first rendered screenshot and in nothing else:
 * `19.00 EUR` under a German heading, and „alle 1 month" beside it. Neither
 * failed a test, because no test looked at the page — which is the argument for
 * having looked.
 */
class PortalDisplayTest extends TestCase
{
    #[Test]
    public function money_is_formatted_in_the_readers_language(): void
    {
        app()->setLocale('de');
        $this->assertSame('1.234,50 EUR', Display::money(123450, 'EUR'));

        app()->setLocale('en');
        $this->assertSame('1,234.50 EUR', Display::money(123450, 'EUR'));
    }

    #[Test]
    public function money_still_respects_currencies_without_two_decimals(): void
    {
        app()->setLocale('de');

        // The yen has no minor unit at all. `Money::decimals()` is the authority
        // and this must not reimplement it.
        $this->assertSame('1.000 JPY', Display::money(1000, 'JPY'));
    }

    #[Test]
    public function the_singular_rhythm_is_a_word(): void
    {
        app()->setLocale('de');

        $this->assertSame('monatlich', Display::rhythm('1 month'));
        $this->assertSame('alle 3 Monate', Display::rhythm('3 months'));
        $this->assertSame('wöchentlich', Display::rhythm('1 week'));
        $this->assertSame('alle 14 Tage', Display::rhythm('14 days'));
    }

    #[Test]
    public function a_rhythm_this_package_cannot_read_is_shown_as_the_provider_wrote_it(): void
    {
        app()->setLocale('de');

        // Wrong-looking beats invented. The set of units belongs to the
        // provider, and a subscription whose rhythm nobody can read is still one
        // the buyer can cancel.
        $this->assertSame('2 fortnights', Display::rhythm('2 fortnights'));
        $this->assertSame('', Display::rhythm(''));
    }
}
