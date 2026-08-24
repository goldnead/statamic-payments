<?php

namespace Goldnead\StatamicPayments\Listeners;

use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Integrations\EntitlementsBridge;

/** Autoloaded by `AddonServiceProvider` off the first parameter type below. */
class GrantEntitlement
{
    public function __construct(protected EntitlementsBridge $bridge) {}

    public function handle(PaymentPaid $event): void
    {
        $this->bridge->grantFor($event->payment);
    }
}
