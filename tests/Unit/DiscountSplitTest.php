<?php

namespace Goldnead\StatamicPayments\Tests\Unit;

use Goldnead\StatamicPayments\Support\DiscountSplit;
use Goldnead\StatamicPayments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Which line a discount came off.
 *
 * The reason this exists is a single number: a payment records one discount
 * amount, and an invoice has to place it across lines that may sit at different
 * VAT rates. Sheet music at 7%, a course at 19%, one ten-euro voucher across
 * both — from the total alone the split is unrecoverable, and the invoice is
 * then not visibly wrong, just indeterminate.
 *
 * Every test here is really about one property: **the parts add up to the
 * whole.** An invoice whose lines do not sum to its total is one somebody has
 * to explain.
 */
class DiscountSplitTest extends TestCase
{
    /** @param list<array{amount_cent: int, quantity: int}> $lines */
    private function summe(array $lines, int $rabatt): int
    {
        return array_sum(DiscountSplit::across($lines, $rabatt));
    }

    #[Test]
    public function it_splits_proportionally_to_line_value(): void
    {
        // 3000 und 1000, also drei Viertel und ein Viertel von 400.
        $anteile = DiscountSplit::across([
            ['amount_cent' => 3000, 'quantity' => 1],
            ['amount_cent' => 1000, 'quantity' => 1],
        ], 400);

        $this->assertSame([300, 100], $anteile);
    }

    #[Test]
    public function quantity_counts_as_value(): void
    {
        // Zwei Stueck zu 1000 wiegen so viel wie eines zu 2000.
        $anteile = DiscountSplit::across([
            ['amount_cent' => 1000, 'quantity' => 2],
            ['amount_cent' => 2000, 'quantity' => 1],
        ], 100);

        $this->assertSame([50, 50], $anteile);
    }

    #[Test]
    public function the_parts_always_add_up_to_the_whole(): void
    {
        // Die eigentliche Zusage. Drei Zeilen, die sich nicht glatt teilen
        // lassen: 1/3 von 10 Cent geht nicht auf.
        foreach ([1, 7, 10, 33, 99, 100, 999, 1234] as $rabatt) {
            $this->assertSame($rabatt, $this->summe([
                ['amount_cent' => 1000, 'quantity' => 1],
                ['amount_cent' => 1000, 'quantity' => 1],
                ['amount_cent' => 1000, 'quantity' => 1],
            ], $rabatt), "bei {$rabatt} Cent fehlt oder entsteht ein Cent");
        }
    }

    #[Test]
    public function a_leftover_cent_goes_to_the_larger_line_and_does_so_reproducibly(): void
    {
        // 1 Cent auf 2000 und 1000: der Rest gehoert der groesseren Zeile.
        // Und zwar unabhaengig davon, in welcher Reihenfolge sie ankommen —
        // sonst gaebe dieselbe Bestellung zweimal eine andere Rechnung.
        $this->assertSame([1, 0], DiscountSplit::across([
            ['amount_cent' => 2000, 'quantity' => 1],
            ['amount_cent' => 1000, 'quantity' => 1],
        ], 1));

        $this->assertSame([0, 1], DiscountSplit::across([
            ['amount_cent' => 1000, 'quantity' => 1],
            ['amount_cent' => 2000, 'quantity' => 1],
        ], 1));
    }

    #[Test]
    public function no_line_ever_carries_more_discount_than_it_is_worth(): void
    {
        // Eine negative Position auf einer Rechnung ist keine.
        $anteile = DiscountSplit::across([
            ['amount_cent' => 100, 'quantity' => 1],
            ['amount_cent' => 10000, 'quantity' => 1],
        ], 10100);

        $this->assertSame(10100, array_sum($anteile));

        foreach ($anteile as $i => $anteil) {
            $this->assertLessThanOrEqual([100, 10000][$i], $anteil);
        }
    }

    #[Test]
    public function it_never_gives_away_more_than_there_is(): void
    {
        // Ein Gutschein groesser als der Warenkorb ist ein Fehler weiter oben.
        // Hier wird er geklemmt, nicht weitergereicht.
        $anteile = DiscountSplit::across([['amount_cent' => 500, 'quantity' => 1]], 900);

        $this->assertSame([500], $anteile);
    }

    #[Test]
    public function nothing_to_split_is_not_an_error(): void
    {
        $this->assertSame([], DiscountSplit::across([], 100));
        $this->assertSame([0, 0], DiscountSplit::across([
            ['amount_cent' => 100, 'quantity' => 1],
            ['amount_cent' => 100, 'quantity' => 1],
        ], 0));
    }

    #[Test]
    public function a_basket_worth_nothing_gets_no_discount_rather_than_an_invented_one(): void
    {
        // Alles auf die erste Zeile zu legen waere eine erfundene Zuordnung.
        $this->assertSame([0, 0], DiscountSplit::across([
            ['amount_cent' => 0, 'quantity' => 1],
            ['amount_cent' => 0, 'quantity' => 3],
        ], 500));
    }

    #[Test]
    public function a_percentage_and_the_amount_it_produces_split_identically(): void
    {
        // Das ist der Grund fuer "anteilig nach Positionswert": ein
        // 20-Prozent-Gutschein und die Zahl, die daraus entsteht, verteilen
        // sich gleich. Sonst haenge die Rechnung daran, welche Form der
        // Gutschein zufaellig hatte.
        $zeilen = [
            ['amount_cent' => 2500, 'quantity' => 1],
            ['amount_cent' => 1500, 'quantity' => 2],
        ];

        $gesamt = 2500 + 3000;

        $this->assertSame(
            DiscountSplit::across($zeilen, (int) round($gesamt * 0.20)),
            DiscountSplit::across($zeilen, 1100),
        );
    }
}
