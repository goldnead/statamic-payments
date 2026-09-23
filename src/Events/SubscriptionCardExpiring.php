<?php

namespace Goldnead\StatamicPayments\Events;

use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * The card a running agreement is charged against expires soon.
 *
 * Once per agreement and expiry date. Only where the provider says when a card
 * expires: Stripe for cards, Mollie for credit card mandates. A SEPA mandate has
 * no expiry and never fires this.
 */
class SubscriptionCardExpiring
{
    use Dispatchable;

    public function __construct(
        public readonly Subscription $subscription,
        /** The last day the card is valid. */
        public readonly Carbon $expiresAt,
    ) {}
}
