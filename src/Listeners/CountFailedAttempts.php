<?php

namespace Goldnead\StatamicPayments\Listeners;

use Goldnead\StatamicPayments\Events\SubscriptionAttemptFailed;
use Goldnead\StatamicPayments\Events\SubscriptionCycleFailed;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\SubscriptionNotice;

/**
 * Turns "a cycle failed" (per delivery) into "the n-th failure in a row" (once).
 *
 * The claim is the failed payment's id: a provider that reports the same failed
 * cycle three times counts it once. The number is the count of claimed failures
 * since the last paid cycle, so a card that failed in March, worked in April and
 * failed again in May reports 1 in May, not 2.
 */
class CountFailedAttempts
{
    public function handle(SubscriptionCycleFailed $event): void
    {
        $subscription = $event->subscription;

        if (! SubscriptionNotice::claim($subscription, SubscriptionNotice::KIND_FAILED_ATTEMPT, (string) $event->payment->getKey())) {
            return;
        }

        $lastPaid = Payment::query()
            ->where('subscription_id', $subscription->getKey())
            ->where('status', Payment::STATUS_PAID)
            ->whereNotNull('paid_at')
            ->max('paid_at');

        $attempt = SubscriptionNotice::query()
            ->where('subscription_id', $subscription->getKey())
            ->where('kind', SubscriptionNotice::KIND_FAILED_ATTEMPT)
            ->when($lastPaid !== null, fn ($q) => $q->where('created_at', '>', $lastPaid))
            ->count();

        SubscriptionAttemptFailed::dispatch($subscription, $event->payment, max(1, $attempt));
    }
}
