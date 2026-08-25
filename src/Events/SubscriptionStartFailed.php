<?php

namespace Goldnead\StatamicPayments\Events;

use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody paid for a subscription and did not get one.
 *
 * The worst quiet outcome this package can produce, so it is not quiet. The
 * money arrived, the payment row says so, and the agreement behind it does not
 * exist — because the provider refused, or because something between the two
 * steps went wrong.
 *
 * A listener here is how a site finds out on the day rather than on the day the
 * customer writes in. The payment carries `meta.subscription_start_failed_at`
 * and `meta.subscription_start_error` as well, so a report can find them without
 * having listened.
 */
class SubscriptionStartFailed
{
    use Dispatchable;

    public function __construct(
        public readonly Payment $payment,
        public readonly string $reason,
    ) {}
}
