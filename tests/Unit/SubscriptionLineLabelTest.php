<?php

namespace Goldnead\StatamicPayments\Tests\Unit;

use Goldnead\StatamicPayments\Support\Subscriptions;
use Goldnead\StatamicPayments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The name of a subscription line on the payment and the invoice.
 *
 * ChoirLive's product is called "ChoirLive Chortarif (jährlich)". The line
 * read "ChoirLive Chortarif (jährlich) — jährlich" (Gesamtprüfung 25.09.2026,
 * shots/G-09e): the rhythm twice. A name that already says it gets no suffix.
 */
class SubscriptionLineLabelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('de');
    }

    #[Test]
    public function a_name_that_already_names_the_rhythm_is_not_repeated(): void
    {
        $this->assertSame(
            'ChoirLive Chortarif (jährlich)',
            Subscriptions::lineLabel('ChoirLive Chortarif (jährlich)', '12 months', null, 1, 7900, 'EUR'),
        );

        $this->assertSame(
            'Monatlich Singen',
            Subscriptions::lineLabel('Monatlich Singen', '1 month', null, 1, 900, 'EUR'),
        );
    }

    #[Test]
    public function a_name_without_the_rhythm_still_gets_it(): void
    {
        $this->assertSame(
            'ChoirLive Chortarif — jährlich',
            Subscriptions::lineLabel('ChoirLive Chortarif', '12 months', null, 1, 7900, 'EUR'),
        );
    }

    #[Test]
    public function a_different_rhythm_word_inside_a_longer_one_does_not_count(): void
    {
        // "halbjährlich" contains "jährlich"; the product is billed yearly.
        $this->assertSame(
            'Kurs halbjährliche Termine — jährlich',
            Subscriptions::lineLabel('Kurs halbjährliche Termine', '12 months', null, 1, 7900, 'EUR'),
        );
    }
}
