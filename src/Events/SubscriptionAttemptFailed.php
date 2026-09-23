<?php

namespace Goldnead\StatamicPayments\Events;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A charge of a running agreement failed, and this is the n-th in a row.
 *
 * `SubscriptionCycleFailed` fires per delivery and says nothing about how often
 * it has happened. This one is claimed once per failed payment and carries the
 * count since the last paid cycle: `attempt` 1 is the first failure, 2 the
 * second, and a paid cycle starts the count again. An automation that reacts
 * differently to the third failure than to the first reads it from here.
 *
 * **Counted per payment.** On Mollie every failed cycle is its own payment. On
 * Stripe the provider's own retries of one invoice stay one payment and count
 * once; the next invoice that fails counts as the next attempt.
 */
class SubscriptionAttemptFailed
{
    use Dispatchable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly Payment $payment,
        public readonly int $attempt,
    ) {}
}
