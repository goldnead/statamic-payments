<?php

namespace Goldnead\StatamicPayments\Portal;

use Closure;

/**
 * One spelling of an address, so that a link and a row can be compared.
 *
 * `Payment.email` is stored exactly as the provider or the checkout form gave
 * it, upper case and stray spaces included. The portal has to match a typed
 * address against those rows, and `WHERE email = ?` on raw input finds nothing
 * for the buyer who typed `Anna@Example.de` into a form that once recorded
 * `anna@example.de`.
 *
 * Lower-cased and trimmed, and nothing else. Not the local part, either:
 * stripping dots or `+tags` is a Gmail convention, not a rule, and applying it
 * would make two genuinely different mailboxes on a strict host look like one —
 * which on this page means showing somebody another person's orders.
 */
final class EmailAddress
{
    public static function normalise(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = mb_strtolower(trim($value));

        return $value === '' ? null : $value;
    }

    /**
     * Whether it is worth spending a lookup on.
     *
     * Not `filter_var`: it rejects every non-ASCII address, and a buyer with an
     * umlaut in their domain is a buyer whose order this page has to be able to
     * show. One `@`, something either side, no whitespace.
     */
    public static function looksDeliverable(string $email): bool
    {
        return preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/u', $email) === 1;
    }

    /**
     * Dieselbe Prüfung als Validierungsregel, für die Formulare, die eine
     * Adresse entgegennehmen und ablehnen dürfen (Widerruf, Kündigung). Der
     * Fehlertext ist Laravels eigener für `email`, damit er übersetzt ist.
     *
     * @return Closure(string, mixed, Closure(string): void): void
     */
    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! self::looksDeliverable(trim($value))) {
                $fail((string) __('validation.email', ['attribute' => $attribute]));
            }
        };
    }
}
