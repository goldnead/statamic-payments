<?php

namespace Goldnead\StatamicPayments\Contracts;

use Goldnead\StatamicPayments\Support\RemoteSubscription;

/**
 * A provider that can list a customer's agreements, with their metadata.
 *
 * Needed for one thing: finding an agreement this package started and never
 * got to write down, because the process died between the provider's answer
 * and the row (a resume on Mollie, a switch without an in-place change). Every
 * agreement this package creates carries its row's id in its metadata
 * (`resumed_subscription_id`, `switched_subscription_id`), and that is how it
 * is recognised.
 */
interface ListsSubscriptions
{
    /** @return list<RemoteSubscription> */
    public function subscriptionsFor(string $customerReference): array;
}
