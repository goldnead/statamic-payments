<?php

namespace Goldnead\StatamicPayments\Contracts;

use Goldnead\StatamicPayments\Support\CheckoutSession;
use Goldnead\StatamicPayments\Support\RemotePayment;

/**
 * The seam over a payment provider.
 *
 * Two methods, and the second is the one that matters. `fetch()` exists because
 * a webhook may not be believed: providers post an id, and the only safe way to
 * learn a status is to ask them for it. An implementation that returned the
 * caller's claim here would defeat the entire design.
 *
 * Cut for one provider but shaped for two: nothing in this interface says
 * "Mollie", so a second implementation is a class and not a fork.
 */
interface PaymentGateway
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function createPayment(array $payload): CheckoutSession;

    /** Ask the provider what this payment's status really is. */
    public function fetch(string $providerId): RemotePayment;

    /**
     * A short, stable handle for this provider — `mollie`, `stripe`.
     *
     * It is written into every row and read back on every lookup. Without it
     * the `provider` column would be decoration, and the day a site runs two
     * providers, a webhook from one could match a row from the other.
     */
    public function provider(): string;
}
