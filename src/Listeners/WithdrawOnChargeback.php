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
        $this->bridge->revokeFor($event->payment->loadMissing('items'), true);
    }
}
