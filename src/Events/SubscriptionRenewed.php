<?php

namespace Goldnead\StatamicPayments\Events;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A cycle was charged and paid.
 *
 * Once per cycle, claimed with a conditional update, so a redelivered webhook
 * does not announce the same month twice.
 */
class SubscriptionRenewed
{
    use Dispatchable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly Payment $payment,
    ) {}
}
