<?php

namespace Goldnead\StatamicPayments\Events;

use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * It ran to its end on its own: a payment plan that paid its last instalment.
 *
 * Distinct from `SubscriptionCancelled`, and the distinction matters to whoever
 * is listening: one is somebody leaving, the other is somebody finishing. An
 * automation that sends "sorry to see you go" on both would say it to a customer
 * who just paid everything they owed.
 */
class SubscriptionEnded
{
    use Dispatchable;

    public function __construct(public readonly Subscription $subscription) {}
}
