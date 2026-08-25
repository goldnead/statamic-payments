<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Money;
use Goldnead\StatamicPayments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Three small rules that each keep a number honest.
 *
 * The quantity is the *only* figure a checkout accepts from a request — the
 * unit price never is — so it needs bounds. The currency decides how many minor
 * units make one, and assuming two is how a yen price comes out a hundred times
 * wrong. And the return URL is a trapdoor: an open redirect behind a successful
 * payment has unusually good cover.
 */
class QuantityCurrencyAndReturnTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'heft' => ['name' => 'Heft', 'amount_cent' => 500],
            'spende' => [
                'name' => 'Spende', 'amount_cent' => 100,
                'min_quantity' => 5, 'max_quantity' => 50,
            ],
        ]);
    }

    #[Test]
    public function a_product_without_bounds_keeps_the_behaviour_it_always_had(): void
    {
        // Drei Hefte sind drei Hefte. Das war immer erlaubt und bleibt es.
        $ergebnis = app(Checkout::class)->start(['heft' => 3]);

        $this->assertSame(1500, $ergebnis->payment->amount_cent);
    }

    #[Test]
    public function a_generous_net_still_catches_a_mistyped_number(): void
    {
        // Kein Geschäftsregel, ein Netz: eine Zahl aus einem Request darf
        // keine fünfstellige Belastung werden.
        config(['statamic-payments.max_quantity' => 1000]);

        $this->assertNull(app(Checkout::class)->start(['heft' => 100000]));
    }

    #[Test]
    public function a_variable_quantity_product_gets_its_declared_bounds(): void
    {
        // Der Spendenfall: der Stückpreis bleibt serverseitig, was aus dem
        // Request kommt, ist eine ganze Zahl mit Ober- und Untergrenze.
        $this->assertNull(app(Checkout::class)->start(['spende' => 4]), 'unter der Untergrenze');
        $this->assertNull(app(Checkout::class)->start(['spende' => 51]), 'über der Obergrenze');

        $ergebnis = app(Checkout::class)->start(['spende' => 25]);

        $this->assertSame(2500, $ergebnis->payment->amount_cent);
    }

    #[Test]
    public function a_currency_without_minor_units_is_not_divided_by_a_hundred(): void
    {
        // 1000 Yen sind 1000 Yen. Durch 100 geteilt wären es zehn.
        $this->assertSame('1000', Money::format(1000, 'JPY'));
        $this->assertSame('10.00', Money::format(1000, 'EUR'));
        $this->assertSame('1.000', Money::format(1000, 'BHD'));
    }

    #[Test]
    public function an_unknown_currency_gets_the_overwhelming_default(): void
    {
        // Zwei Stellen sind der Normalfall; eine Tabelle aller ISO-Codes wäre
        // eine, die niemand pflegt.
        $this->assertSame(2, Money::decimals('EUR'));
        $this->assertSame(2, Money::decimals('XYZ'));
        $this->assertSame(2, Money::decimals(null));
        $this->assertSame(0, Money::decimals('jpy'), 'die Schreibweise darf nicht entscheiden');
    }

    #[Test]
    public function a_return_url_pointing_somewhere_else_is_dropped_rather_than_followed(): void
    {
        foreach ([
            'https://boese.example/abgriff',
            '//boese.example/abgriff',
            'javascript:alert(1)',
            'not a url at all',
        ] as $ziel) {
            $ergebnis = app(Checkout::class)->start(['heft'], [], $ziel);

            $this->assertStringNotContainsString(
                'boese.example',
                $this->gateway->lastPayload['redirectUrl'] ?? '',
                "[{$ziel}] wurde durchgereicht",
            );
            $this->assertNotSame($ziel, $this->gateway->lastPayload['redirectUrl'] ?? null);
        }
    }

    #[Test]
    public function a_return_url_on_this_site_is_used_as_given(): void
    {
        // Die Zusage bleibt: der Aufrufer bestimmt, wo der Käufer landet —
        // solange es die eigene Seite ist.
        app(Checkout::class)->start(['heft'], [], '/danke/kurs');

        $this->assertSame('/danke/kurs', $this->gateway->lastPayload['redirectUrl'] ?? null);
    }
}
