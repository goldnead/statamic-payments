<?php

namespace Goldnead\StatamicPayments\Events;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An agreement moved to another product: an upgrade or a downgrade.
 *
 * Dispatched after the provider accepted the new amount. `$subscription` already
 * carries the new product and amount.
 *
 * - **Upgrade** (`immediate` true): the new product applies at once. The
 *   difference for the rest of the current period is charged as its own
 *   payment, `$prorationPayment`, and that payment is still open here — it is
 *   settled by the webhook like every other charge.
 * - **Downgrade** (`immediate` false): the new amount applies from the next
 *   charge on. Nothing is charged or refunded now.
 */
class SubscriptionChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly string $fromProduct,
        public readonly string $toProduct,
        public readonly int $fromAmountCent,
        public readonly int $toAmountCent,
        /** What was charged for the rest of the current period. 0 on a downgrade. */
        public readonly int $prorationCent,
        public readonly ?Payment $prorationPayment,
        public readonly bool $immediate,
        /** `cp` or `portal`. */
        public readonly string $by,
    ) {}
}
