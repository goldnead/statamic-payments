<?php

namespace Goldnead\StatamicPayments\Events;

use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Money went back to the buyer.
 *
 * Carries what was refunded *this time* as well as the running total, because
 * the two answer different questions: a listener deciding whether to withdraw
 * access needs to know whether everything has now been repaid, while an
 * accounting listener needs the individual movement.
 *
 * Not a status change. A partially refunded order is still a paid order — the
 * money moved, the thing was delivered, and part of it came back.
 */
class PaymentRefunded
{
    use Dispatchable;

    public function __construct(
        public readonly Payment $payment,
        public readonly int $amountCent,
        public readonly bool $isFull,
    ) {}
}
