<?php

namespace Goldnead\StatamicPayments\Listeners;

use Goldnead\StatamicPayments\Events\SubscriptionCancelled;
use Goldnead\StatamicPayments\Events\SubscriptionEnded;
use Goldnead\StatamicPayments\Events\SubscriptionRenewed;
use Goldnead\StatamicPayments\Integrations\EntitlementsBridge;

/**
 * A subscription and the access it pays for, kept in step.
 *
 * Autoloaded by `AddonServiceProvider` off the first parameter type of each
 * `handle*` method — which is why they are named rather than one `handle()`.
 *
 * Without this, every installation wrote the same three listeners itself, and
 * the interesting one is the middle: cancelling is **not** revoking. Somebody
 * who cancels has paid for the period they are in and keeps it to the end.
 */
class FollowSubscriptionWithEntitlement
{
    public function __construct(protected EntitlementsBridge $bridge) {}

    public function handleRenewed(SubscriptionRenewed $event): void
    {
        $this->bridge->renewFor($event->subscription, $event->payment);
    }

    public function handleCancelled(SubscriptionCancelled $event): void
    {
        $this->bridge->closeFor($event->subscription);
    }

    public function handleEnded(SubscriptionEnded $event): void
    {
        $this->bridge->closeFor($event->subscription);
    }
}
