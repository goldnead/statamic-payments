<?php

namespace Goldnead\StatamicPayments\Support;

/**
 * How many minor units a currency has.
 *
 * `amount_cent` is an integer in the smallest unit, and that is right: a float
 * is the road on which one cent disappears every thousand orders. But the name
 * assumes two decimal places, and not every currency has two. The Japanese yen
 * has none; the Tunisian dinar has three. Sell 1.000 ¥ with a hard-coded 100
 * and the provider is handed either ten times or a hundredth of the price,
 * depending on who divides wrong.
 *
 * Only the exceptions are listed. Two places is the overwhelming default, and a
 * table of every ISO 4217 code would be a table nobody maintains — the ones
 * here are the zero- and three-decimal currencies, which is the whole of the
 * disagreement.
 */
final class Money
{
    /** @var array<string, int> */
    private const EXCEPTIONS = [
        // No minor unit at all.
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0,
        'JPY' => 0, 'KMF' => 0, 'KRW' => 0, 'PYG' => 0, 'RWF' => 0,
        'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0,
        'XOF' => 0, 'XPF' => 0,

        // Three.
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3,
        'OMR' => 3, 'TND' => 3,
    ];

    public static function decimals(?string $currency): int
    {
        $code = strtoupper(trim((string) $currency));

        return self::EXCEPTIONS[$code] ?? 2;
    }

    /**
     * The amount as a provider wants to read it: a decimal string.
     *
     * Not a float on the way out either. `number_format` takes the division and
     * the rounding in one step, and the string is what goes over the wire.
     */
    public static function format(int $minorUnits, ?string $currency): string
    {
        $decimals = self::decimals($currency);

        return number_format($minorUnits / (10 ** $decimals), $decimals, '.', '');
    }

    /**
     * The way back: a decimal string to an integer of minor units.
     *
     * The inverse of {@see format()}, and it belongs here for the same reason
     * `format()` does — the decimal count is the whole of the disagreement
     * between currencies, and a provider adapter that did the arithmetic itself
     * would be a second place to get the yen wrong.
     *
     * Parsed as text, never through a float. `(int) round(19.99 * 100)` is 1999
     * on most inputs and 1998 on the one that matters, and the difference is a
     * cent that disappears from somebody's takings without a trace.
     *
     * Anything that is not an amount throws. Returning zero would be a free
     * order; guessing would be a wrong one.
     */
    public static function toMinorUnits(string|int|float $value, ?string $currency): int
    {
        $decimals = self::decimals($currency);
        $text = trim((string) $value);

        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '+-');

        if (preg_match('/^\d+(\.\d*)?$/', $text) !== 1) {
            throw new \InvalidArgumentException("statamic-payments: [{$value}] is not an amount.");
        }

        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, $decimals), $decimals, '0');

        $minor = (int) ($whole.$fraction);

        return $negative ? -$minor : $minor;
    }

    /**
     * Derselbe Betrag, aber für einen Menschen.
     *
     * `format()` schreibt für die Leitung: Punkt als Dezimaltrennzeichen, kein
     * Tausenderpunkt, kein Zeichen. Das ist richtig gegenüber einem Anbieter
     * und falsch auf einer Rechnungszeile, wo „1560.00 EUR" wie ein Auszug aus
     * einem Log aussieht. Zwei Methoden statt eines Schalters, weil die beiden
     * Aufgaben nichts miteinander zu tun haben und ein Schalter irgendwann
     * falsch gestellt wird.
     *
     * Die Stellenzahl kommt weiter aus derselben Tabelle oben — ein Yen-Betrag
     * bekommt hier so wenig Nachkommastellen wie dort.
     */
    public static function display(int $minorUnits, ?string $currency): string
    {
        $decimals = self::decimals($currency);

        return number_format($minorUnits / (10 ** $decimals), $decimals, ',', '.')
            .' '.self::symbol($currency);
    }

    /**
     * Das Zeichen, oder der Code, wenn es keins gibt.
     *
     * Bewusst kurz gehalten: die Liste nennt die Währungen, in denen dieses
     * Paket tatsächlich abgerechnet wird. Ein Code ist eine richtige Antwort,
     * ein falsches Zeichen nicht — deshalb fällt alles Unbekannte auf den Code
     * zurück und wird nicht geraten.
     */
    public static function symbol(?string $currency): string
    {
        return match (strtoupper(trim((string) $currency))) {
            'EUR' => '€',
            'CHF' => 'CHF',
            'GBP' => '£',
            'USD' => '$',
            default => strtoupper(trim((string) $currency)),
        };
    }
}
