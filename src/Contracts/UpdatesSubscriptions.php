<?php

namespace Goldnead\StatamicPayments\Contracts;

use Goldnead\StatamicPayments\Support\RemoteSubscription;

/**
 * A provider that can change the amount of a running agreement in place.
 *
 * Mollie (`PATCH /customers/{id}/subscriptions/{id}`) and Stripe (a new price on
 * the subscription item) both can. The change applies from the next charge on
 * and **never** prorates on the provider's side: when an upgrade is charged for
 * the rest of the current period, this package charges that difference itself,
 * as its own payment, so the amount is the same on both providers and appears on
 * an invoice like every other charge.
 *
 * A provider without this is changed by ending the agreement and starting a new
 * one on the same billing day.
 */
interface UpdatesSubscriptions
{
    /**
     * @param  array<string, mixed>  $payload  `amount` (currency, value) and `description`
     */
    public function updateSubscription(string $customerReference, string $subscriptionId, array $payload): RemoteSubscription;
}
