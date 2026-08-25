<?php

namespace Goldnead\StatamicPayments\Events;

use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody stopped it, and the provider agreed.
 *
 * Dispatched only after the provider confirmed. A row marked cancelled while the
 * provider keeps charging is the worst outcome this package can produce, so the
 * event follows the provider rather than the intent.
 */
class SubscriptionCancelled
{
    use Dispatchable;

    public function __construct(public readonly Subscription $subscription) {}
}
