<?php

namespace Goldnead\StatamicPayments\Listeners;

use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Events\PaymentRefunded;
use Goldnead\StatamicPayments\Integrations\LeadhubBridge;

/**
 * Two facts about money, on their way to the CRM.
 *
 * Both in one class with `handle*` methods, because Statamic registers a
 * listener per public `handle…` method by the type of its first parameter —
 * the same reason `FollowSubscriptionWithEntitlement` is shaped this way.
 */
class RecordPurchaseInLeadhub
{
    public function __construct(protected LeadhubBridge $bridge) {}

    public function handlePaid(PaymentPaid $event): void
    {
        $this->bridge->recordPurchase($event->payment);
    }

    public function handleRefunded(PaymentRefunded $event): void
    {
        $this->bridge->recordRefund($event->payment, $event->amountCent, $event->isFull);
    }
}
