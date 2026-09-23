<?php

/**
 * A stand-in for `Goldnead\StatamicOffers\Offers`, under the sibling's own name.
 *
 * Loaded by hand from the tests that need it, like the other fakes here. The two
 * methods payments calls are copied in their arithmetic from statamic-offers
 * 3d6d85b (`src/Offers.php`), so a test here says what the real one would say.
 * `availableIn` answers from a static list the test fills.
 */

namespace Goldnead\StatamicOffers;

if (! class_exists(Offers::class)) {
    class Offers
    {
        /** @var array<string, list<string>> handle => countries it may NOT be sold in */
        public static array $notIn = [];

        public static function availableIn(string $handle, ?string $country): bool
        {
            $gesperrt = self::$notIn[$handle] ?? null;

            if ($gesperrt === null) {
                return true;
            }

            return $country !== null && ! in_array(strtoupper($country), $gesperrt, true);
        }

        /** @param  array<string, mixed>  $terms */
        public static function recurringDiscountCent(array $terms, int $number, int $amountCent, ?string $currency = null): int
        {
            if ($number < 1 || $amountCent <= 0) {
                return 0;
            }

            $gilt = match ($terms['duration'] ?? 'once') {
                'forever' => true,
                'repeating' => $number <= max(1, (int) ($terms['cycles'] ?? 1)),
                default => $number === 1,
            };

            if (! $gilt) {
                return 0;
            }

            $prozent = $terms['percent'] ?? null;

            if (is_int($prozent) && $prozent > 0) {
                return min($amountCent, (int) round($amountCent * min($prozent, 100) / 100));
            }

            $fest = $terms['amount_cent'] ?? null;

            if (! is_int($fest) || $fest <= 0) {
                return 0;
            }

            $eigene = $terms['currency'] ?? null;

            if (is_string($eigene) && $currency !== null && mb_strtoupper($eigene) !== mb_strtoupper($currency)) {
                return 0;
            }

            return min($amountCent, $fest);
        }
    }
}
