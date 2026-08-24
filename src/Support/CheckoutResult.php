<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Models\Payment;

/**
 * What a started checkout gives back: the row, and where to send the buyer.
 *
 * Two values rather than a checkout URL glued onto the model. An attribute
 * without a column survives until someone calls `save()` on it, and then throws
 * on a column that does not exist — at the worst possible moment, mid-order.
 */
final readonly class CheckoutResult
{
    public function __construct(
        public Payment $payment,
        public string $checkoutUrl,
    ) {}
}
