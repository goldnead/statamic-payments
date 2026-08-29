<?php

namespace Goldnead\StatamicPayments\Portal;

use Goldnead\StatamicPayments\Support\Money;

/**
 * Numbers and rhythms, as a buyer reads them rather than as a provider takes
 * them.
 *
 * {@see Money::format()} is the provider's format and has to stay that way: a
 * decimal point, no separators, the string that goes over the wire. Put it on a
 * page for a German buyer and it says `1234.50 EUR` where the receipt beside it
 * says `1.234,50 €`.
 *
 * Same for the rhythm. `Subscription.interval` holds the provider's own
 * vocabulary — `1 month`, `12 weeks` — kept as typed because the set of units is
 * the provider's to define. Rendered raw it reads "alle 1 month" on a page that
 * is otherwise in German, which is the single most obvious tell that a screen
 * was assembled rather than written.
 *
 * Both go through translation keys, including the separators, because which
 * character separates a decimal is a property of a language and not of money.
 * An unknown unit falls back to the provider's own string: wrong-looking beats
 * invented, and a subscription nobody can read the rhythm of is still one they
 * can cancel.
 */
final class Display
{
    public static function money(int $cent, ?string $currency): string
    {
        $decimals = Money::decimals($currency);

        $number = number_format(
            $cent / (10 ** $decimals),
            $decimals,
            (string) __('statamic-payments::portal.decimal_point'),
            (string) __('statamic-payments::portal.thousands_separator'),
        );

        return trim($number.' '.(string) $currency);
    }

    /**
     * "1 month" → „monatlich"; "3 months" → „alle 3 Monate".
     *
     * `trans_choice`, so that the singular is a word and not a number with a
     * unit after it. „alle 1 Monat" is what a template that only interpolates
     * produces, and it is the phrasing nobody says out loud.
     */
    public static function rhythm(string $interval): string
    {
        if (preg_match('/^\s*(\d+)\s*(day|week|month|year)s?\s*$/i', $interval, $match) !== 1) {
            return $interval;
        }

        $count = (int) $match[1];

        return trans_choice(
            'statamic-payments::portal.interval_'.strtolower($match[2]),
            $count,
            ['count' => $count],
        );
    }
}
