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
}
