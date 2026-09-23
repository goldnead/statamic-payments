<?php

namespace Goldnead\StatamicPayments\Contracts;

use Goldnead\StatamicPayments\Support\RemoteSubscription;
use Illuminate\Support\Carbon;

/**
 * A provider that can pause an agreement itself.
 *
 * Optional, like every capability beyond charging. Stripe has it
 * (`pause_collection`); Mollie does not, and there this package pauses by ending
 * the running agreement and starting a new one on resume, against the same
 * mandate and on the old billing day. See `Support\SubscriptionPauses`.
 *
 * Both methods answer with the provider's view afterwards. A paused agreement
 * reports `Subscription::STATUS_PAUSED`.
 */
interface PausesSubscriptions
{
    public function pauseSubscription(string $customerReference, string $subscriptionId, ?Carbon $resumesAt = null): RemoteSubscription;

    public function resumeSubscription(string $customerReference, string $subscriptionId): RemoteSubscription;
}
