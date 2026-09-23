<?php

namespace Goldnead\StatamicPayments\Events;

use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * The card a running agreement is charged against has expired.
 *
 * Once per agreement and expiry date. The next charge will fail unless the buyer
 * puts a new card on file; the mail that goes with it carries the portal link.
 */
class SubscriptionCardExpired
{
    use Dispatchable;

    public function __construct(
        public readonly Subscription $subscription,
        /** The last day the card was valid. */
        public readonly Carbon $expiredAt,
    ) {}
}
