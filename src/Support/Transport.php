<?php

namespace Goldnead\StatamicPayments\Support;

use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Whether a failed provider call is "no answer" rather than "no".
 *
 * The difference decides money (Gauntlet 23.09.2026). A refusal (a 4xx: no
 * mandate, invalid amount) means nothing happened at the provider; the row goes
 * back and the next try may be a new request. A timeout, a dropped connection
 * or a 5xx means the provider may well have done it and only the answer was
 * lost. Then the row stays claimed and the sweep asks the provider, instead of
 * a second request starting a second agreement.
 *
 * In doubt, transient: treating a lost answer as a refusal charges twice,
 * treating a refusal as a lost answer only waits for the next sweep.
 */
final class Transport
{
    public static function isTransient(Throwable $e): bool
    {
        if ($e instanceof ProviderUnavailable || $e instanceof ConnectionException) {
            return true;
        }

        // Mollie: network and timeout exceptions by class, server errors by code.
        $class = $e::class;

        if (str_contains($class, 'Network') || str_contains($class, 'Timeout')
            || str_contains($class, 'ServiceUnavailable') || str_ends_with($class, 'ServerException')) {
            return true;
        }

        if (str_starts_with($class, 'Mollie\\')) {
            $code = (int) $e->getCode();

            return $code === 0 || $code >= 500;
        }

        // Stripe's adapter already throws `ProviderUnavailable` for a 5xx, a
        // 429 and a connection that never came up (StripeGateway::read()).
        return false;
    }
}
