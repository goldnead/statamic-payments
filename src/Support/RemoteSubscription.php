<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Models\Subscription;

/**
 * A subscription as the provider currently sees it.
 *
 * Same reason as `RemotePayment`: the caller is never the authority on whether
 * an agreement is running. A webhook saying "cancelled" is a request to go and
 * look, not a fact.
 */
final readonly class RemoteSubscription
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $providerId,
        public string $status,
        public ?string $nextPaymentAt = null,
        public ?int $timesCharged = null,
        public array $meta = [],
    ) {}

    public function isLive(): bool
    {
        return in_array($this->status, [Subscription::STATUS_PENDING, Subscription::STATUS_ACTIVE], true);
    }
}
