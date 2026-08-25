<?php

namespace Goldnead\StatamicPayments\Events;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An agreement now exists, and the first payment is behind it.
 *
 * Dispatched after the provider confirmed **both**: the money for the first
 * cycle arrived, and the mandate it left behind was accepted. Neither on its own
 * is a subscription.
 *
 * A listener here is the right place for "welcome, here is what happens next".
 * It is the wrong place for granting access: that already happened, per payment,
 * on `PaymentPaid` — and it will happen again on every cycle, which is what a
 * subscription is for.
 */
class SubscriptionStarted
{
    use Dispatchable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly Payment $payment,
    ) {}
}
