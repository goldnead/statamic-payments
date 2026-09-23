<?php

namespace Goldnead\StatamicPayments\Listeners;

use Goldnead\StatamicPayments\Events\PaymentFailed;
use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Support\Facades\Log;

/**
 * The difference an upgrade charged did not arrive (Gauntlet 23.09.2026).
 *
 * The switch applied at once, as upgrades do; the charge for the rest of the
 * period is settled later by the webhook and can fail, a SEPA debit days later.
 * Then the new product runs unpaid until the next charge. Written onto the
 * switch in `meta.switches` (`proration_failed_payment_id`), so the Control
 * Panel shows it in the agreement's history, and said in the log.
 */
class MarkFailedSwitchDifference
{
    public function handle(PaymentFailed $event): void
    {
        $change = data_get($event->payment->meta, 'subscription_change');

        if (! is_array($change) || ! is_numeric($change['subscription_id'] ?? null)) {
            return;
        }

        $subscription = Subscription::find((int) $change['subscription_id']);

        if ($subscription === null) {
            return;
        }

        $meta = $subscription->meta ?? [];
        $switches = (array) ($meta['switches'] ?? []);

        foreach ($switches as $i => $switch) {
            if (is_array($switch) && (int) ($switch['proration_payment_id'] ?? 0) === (int) $event->payment->getKey()) {
                $switches[$i]['proration_failed_payment_id'] = (int) $event->payment->getKey();
            }
        }

        $meta['switches'] = $switches;
        $subscription->forceFill(['meta' => $meta])->save();

        Log::warning('statamic-payments: the difference for a switch was not paid; the new product runs unpaid until the next charge.', [
            'subscription_id' => $subscription->getKey(),
            'payment_id' => $event->payment->getKey(),
        ]);
    }
}
