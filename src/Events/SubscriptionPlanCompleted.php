<?php

namespace Goldnead\StatamicPayments\Events;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payment plan has received its last instalment. Everything is paid.
 *
 * Fired together with `SubscriptionEnded` (status `completed`), and exists so a
 * listener does not have to read the status to tell "finished paying" from
 * "stopped paying". Once per plan: the cycle that completes it is claimed.
 */
class SubscriptionPlanCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly Subscription $subscription,
        /** The cycle that paid the last instalment. */
        public readonly Payment $payment,
    ) {}
}
