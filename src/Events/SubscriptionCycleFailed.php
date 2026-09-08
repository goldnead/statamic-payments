<?php

namespace Goldnead\StatamicPayments\Events;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A cycle of a running agreement was not paid.
 *
 * `SubscriptionStartFailed` covers the other end — money arrived and no
 * agreement came of it. This is the one that happens far more often and used to
 * be invisible: an agreement that ran for months, a card that expired, and a
 * provider status this package mirrored without ever saying anything about it.
 *
 * On a subscription product that is a lost customer who never learns they were
 * one. Which is why the dunning sequence hangs off this event rather than off a
 * scheduled scan: the failure is the thing that starts the clock.
 *
 * **Fired per delivery, not per failure.** A provider may say "not paid" about
 * the same cycle more than once, and a listener that acts on it must claim its
 * own work — `Dunning::begin()` does, with a conditional UPDATE.
 */
class SubscriptionCycleFailed
{
    use Dispatchable;

    public function __construct(
        public readonly Subscription $subscription,
        /** The cycle payment that did not go through. */
        public readonly Payment $payment,
        /** The status the provider gave it: `failed`, `expired`, `open`… */
        public readonly string $status,
    ) {}
}
