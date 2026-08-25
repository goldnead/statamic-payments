<?php

namespace Goldnead\StatamicPayments\Support;

/**
 * Which line a discount came off.
 *
 * A payment records one discount amount. An invoice needs to know how it was
 * distributed, because the lines can sit at different VAT rates: sheet music at
 * 7%, a course at 19%, one ten-euro voucher across both. From a single number
 * that split is not recoverable — and the invoice is then not visibly wrong,
 * just indeterminate, which is the worse of the two.
 *
 * **The rule: proportional to line value.** It is what tax offices expect, it
 * needs no knowledge of what the discount meant, and it gives the same answer
 * for a fixed amount and for a percentage — a 20% voucher and its resulting
 * euro figure distribute identically, which is what makes it safe to apply
 * after the fact.
 *
 * **The rounding rule, named rather than left to chance:** distribute by
 * integer division, then give every remaining cent to the largest lines first.
 * The alternative — rounding each share and hoping — either loses a cent or
 * invents one, and an invoice whose lines do not add up to its total is an
 * invoice somebody has to explain.
 */
final class DiscountSplit
{
    /**
     * @param  list<array{amount_cent: int, quantity: int}>  $lines
     * @return list<int> the discount per line, in the order given, summing exactly to $discountCent
     */
    public static function across(array $lines, int $discountCent): array
    {
        $anzahl = count($lines);

        if ($anzahl === 0 || $discountCent <= 0) {
            return array_fill(0, $anzahl, 0);
        }

        $werte = array_map(
            fn (array $l) => max(0, (int) $l['amount_cent']) * max(0, (int) $l['quantity']),
            $lines,
        );

        $summe = array_sum($werte);

        if ($summe <= 0) {
            // Nichts, worauf sich verteilen liesse. Alles auf die erste Zeile
            // zu legen waere eine erfundene Zuordnung; null ist die ehrliche.
            return array_fill(0, $anzahl, 0);
        }

        // Nie mehr verteilen, als es zu verteilen gibt.
        $discountCent = min($discountCent, $summe);

        $anteile = [];
        $verteilt = 0;

        foreach ($werte as $wert) {
            $anteil = intdiv($wert * $discountCent, $summe);
            $anteile[] = $anteil;
            $verteilt += $anteil;
        }

        // Die Restcents. Nach Positionswert absteigend, damit die Verteilung
        // von der Reihenfolge der Zeilen unabhaengig ist und zweimal dieselbe
        // Eingabe zweimal dasselbe Ergebnis liefert.
        $rest = $discountCent - $verteilt;

        if ($rest > 0) {
            $reihenfolge = array_keys($werte);
            usort($reihenfolge, fn (int $a, int $b) => [$werte[$b], $a] <=> [$werte[$a], $b]);

            foreach ($reihenfolge as $i) {
                if ($rest <= 0) {
                    break;
                }

                // Eine Position darf nie mehr Rabatt tragen, als sie wert ist:
                // eine negative Zeile auf einer Rechnung ist keine.
                if ($anteile[$i] >= $werte[$i]) {
                    continue;
                }

                $anteile[$i]++;
                $rest--;
            }
        }

        return $anteile;
    }
}
