<?php

namespace Goldnead\StatamicPayments\Listeners;

use Goldnead\StatamicPayments\Events\PaymentRefunded;
use Goldnead\StatamicPayments\Integrations\EntitlementsBridge;

/** Autoloaded by `AddonServiceProvider` off the first parameter type below. */
class WithdrawOnRefund
{
    public function __construct(protected EntitlementsBridge $bridge) {}

    public function handle(PaymentRefunded $event): void
    {
        $this->bridge->revokeFor($event->payment->loadMissing('items'), $event->isFull);
    }
}
