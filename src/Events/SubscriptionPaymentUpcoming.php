<?php

namespace Goldnead\StatamicPayments\Events;

use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * The next charge of an agreement is close.
 *
 * Once per agreement and charge date, claimed in `payment_subscription_notices`,
 * so an overlapping scheduler run announces nothing twice.
 */
class SubscriptionPaymentUpcoming
{
    use Dispatchable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly Carbon $dueAt,
        /** How many days before the charge this went out, as configured. */
        public readonly int $daysBefore,
    ) {}
}
