<?php

namespace Goldnead\StatamicPayments\Events;

use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The seam. What a payment *means* belongs to the site: granting access,
 * sending a file, notifying someone. This addon takes money and says so.
 *
 * Dispatched **once per payment**, guaranteed by a conditional update rather
 * than by a check — so a listener may grant access without carrying its own
 * idempotency, which is the one thing every listener would eventually get wrong.
 */
class PaymentPaid
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
