<?php

namespace Goldnead\StatamicPayments\Contracts;

use Goldnead\StatamicPayments\Support\RemoteSubscription;

/**
 * Charging a buyer on a rhythm.
 *
 * A third interface rather than more methods on `PaymentGateway`, for the same
 * reason `FollowUpGateway` is separate: a provider that cannot do this should
 * not have to declare stubs that throw, and a site should be able to ask
 * instead of finding out at the till.
 *
 * **Every method takes the customer reference.** That is not decoration: a
 * subscription only exists against a stored agreement, and a provider that was
 * handed a subscription id without the customer it belongs to would be asked to
 * trust the id alone. Passing both means a mixed-up id cannot reach into
 * somebody else's account.
 *
 * **What this is not.** It is not permission to charge. In Germany a recurring
 * agreement has to be announced on the page where it is entered into, with the
 * rhythm, the amount and the way out stated before the button (§ 312j BGB).
 * What a provider stores is the card; what a site needs is the consent, and no
 * interface can supply that.
 */
interface SubscriptionGateway extends FollowUpGateway
{
    /** Whether this provider can run a subscription at all. */
    public function supportsSubscriptions(): bool;

    /**
     * Start one against a stored agreement.
     *
     * @param  array<string, mixed>  $payload  amount, interval, times,
     *                                         description, startDate, webhookUrl, metadata
     */
    public function createSubscription(string $customerReference, array $payload): RemoteSubscription;

    /** Stop one. Idempotent as far as the caller is concerned: already stopped is not an error. */
    public function cancelSubscription(string $customerReference, string $subscriptionId): RemoteSubscription;

    /** Ask the provider what this agreement's state really is. */
    public function fetchSubscription(string $customerReference, string $subscriptionId): RemoteSubscription;
}
