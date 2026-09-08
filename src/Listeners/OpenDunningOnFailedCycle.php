<?php

namespace Goldnead\StatamicPayments\Listeners;

use Goldnead\StatamicPayments\Events\SubscriptionCycleFailed;
use Goldnead\StatamicPayments\Support\Dunning;

/**
 * A failed cycle starts the clock.
 *
 * Autoloaded by `AddonServiceProvider` off the first parameter type below.
 *
 * Nothing is sent here. The first letter is due days later, and a sequence that
 * wrote on the spot would be writing before the provider has made its own
 * retry — which is how somebody gets told their payment failed an hour before
 * it goes through.
 */
class OpenDunningOnFailedCycle
{
    public function __construct(protected Dunning $dunning) {}

    public function handle(SubscriptionCycleFailed $event): void
    {
        $this->dunning->begin($event->subscription, $event->payment);
    }
}
