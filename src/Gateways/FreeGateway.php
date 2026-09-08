<?php

namespace Goldnead\StatamicPayments\Gateways;

use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\CheckoutSession;
use Goldnead\StatamicPayments\Support\RemotePayment;
use LogicException;

/**
 * The provider that does not exist.
 *
 * A basket the catalogue priced at zero is settled on the spot by
 * `Checkout::free()` and stamped `provider => 'free'`. No provider was involved
 * and none ever will be — but the handle is in the column like any other, and
 * something that resolves gateways by handle has to have an answer for it.
 * Without this class `Gateways::resolve('free')` would throw on a perfectly
 * ordinary free order.
 *
 * `fetch()` says paid, and that is not a lie told for convenience: the
 * catalogue priced the basket at zero, so there is nothing outstanding. It is
 * also the same answer `Fulfilment::fulfilFree()` already constructs by hand.
 *
 * `createPayment()` throws, because reaching it means a caller decided to send
 * somebody to a hosted checkout for nothing.
 */
class FreeGateway implements PaymentGateway
{
    public function provider(): string
    {
        return 'free';
    }

    public function createPayment(array $payload): CheckoutSession
    {
        throw new LogicException(
            'statamic-payments: a free order has no checkout to send anybody to. '
            .'Checkout::start() settles a zero basket itself.'
        );
    }

    public function fetch(string $providerId): RemotePayment
    {
        return new RemotePayment(
            providerId: $providerId,
            status: Payment::STATUS_PAID,
            metadata: ['free' => true],
        );
    }
}
