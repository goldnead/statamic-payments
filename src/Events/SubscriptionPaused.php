<?php

namespace Goldnead\StatamicPayments\Events;

use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * An agreement was paused. Nothing is charged until it resumes.
 *
 * Dispatched after the provider confirmed — on Stripe the pause itself, on
 * Mollie the end of the running agreement that stands in for one (see
 * `Subscriptions::pause()`).
 */
class SubscriptionPaused
{
    use Dispatchable;

    public function __construct(
        public readonly Subscription $subscription,
        /** When it resumes by itself, or null: until somebody resumes it. */
        public readonly ?Carbon $resumesAt,
        /** Who asked: `cp` or `portal`. */
        public readonly string $by,
    ) {}
}
