<?php

namespace Goldnead\StatamicPayments\Events;

use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A paused agreement runs again.
 *
 * `$subscription->next_payment_at` is the first charge after the pause. It keeps
 * the old billing day: nothing is charged at the moment of resuming.
 */
class SubscriptionResumed
{
    use Dispatchable;

    public function __construct(
        public readonly Subscription $subscription,
        /** `cp`, `portal` or `schedule` (the date set when pausing was reached). */
        public readonly string $by,
    ) {}
}
