<?php

namespace Goldnead\StatamicPayments\Listeners;

use Goldnead\StatamicPayments\Events\PaymentChargedBack;
use Goldnead\StatamicPayments\Integrations\EntitlementsBridge;

/**
 * Somebody who has their money back does not keep the thing.
 *
 * Autoloaded by `AddonServiceProvider` off the first parameter type below, the
 * same way {@see WithdrawOnRefund} is. Always a full withdrawal: a partial
 * refund is a negotiation, a chargeback is the whole sale being reversed by
 * somebody who was not asked.
 */
class WithdrawOnChargeback
{
    public function __construct(protected EntitlementsBridge $bridge) {}

    public function handle(PaymentChargedBack $event): void
    {
        // The reason is written into the entitlement's own history, and it has
        // to be the true one. Sharing the refund wording would tell a support
        // reader the opposite story: that somebody here decided to give the
        // money back, when in fact it was taken.
        $this->bridge->revokeFor($event->payment->loadMissing('items'), true, 'Rückbuchung');
    }
}
