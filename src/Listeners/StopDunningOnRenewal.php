<?php

namespace Goldnead\StatamicPayments\Listeners;

use Goldnead\StatamicPayments\Events\SubscriptionRenewed;
use Goldnead\StatamicPayments\Support\Dunning;

/**
 * The money arrived. Stop writing.
 *
 * Autoloaded by `AddonServiceProvider` off the first parameter type below.
 *
 * The sequence already asks the provider before every letter, but that question
 * is about the payment that failed — and on Mollie a failed payment stays
 * failed for ever. The retry, and the card a buyer replaces in the portal,
 * produce a **new** payment. Without this listener a customer who fixed their
 * card on day four would still get letters two and three and be cancelled on
 * day twenty-one, with their access withdrawn, while paying.
 *
 * A renewal is the provider's own answer that money moved, arrived through the
 * ordinary fulfilment path. There is nothing to tell anybody: this is the
 * outcome the letters were asking for.
 */
class StopDunningOnRenewal
{
    public function __construct(protected Dunning $dunning) {}

    public function handle(SubscriptionRenewed $event): void
    {
        $this->dunning->stop($event->subscription);
    }
}
