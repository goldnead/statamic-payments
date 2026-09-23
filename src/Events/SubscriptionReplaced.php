<?php

namespace Goldnead\StatamicPayments\Events;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A purchase ended another agreement of the same buyer, as the sold product
 * says it should (`replaces` in the catalogue entry).
 *
 * `SubscriptionCancelled` fires for `$replaced` as well; this event says why.
 */
class SubscriptionReplaced
{
    use Dispatchable;

    public function __construct(
        /** The agreement that was ended. */
        public readonly Subscription $replaced,
        /** The payment that bought the replacement. */
        public readonly Payment $purchase,
        /** The new agreement, when the replacement is one. */
        public readonly ?Subscription $replacement,
        /** Unused value of the old period, in minor units, credited to the new agreement as later start. */
        public readonly int $creditCent,
        /** How many days the new agreement's first charge moved back for it. */
        public readonly int $creditDays,
    ) {}
}
