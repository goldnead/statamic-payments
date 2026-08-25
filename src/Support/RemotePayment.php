<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Models\Payment;

/**
 * A payment as the provider currently sees it.
 *
 * The only source of truth about whether money moved. Everything else in this
 * package treats a caller's claim as a hint that a status *may* have changed,
 * never as the status itself.
 */
final readonly class RemotePayment
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $providerId,
        public string $status,
        public array $metadata = [],
        public ?string $email = null,
        /**
         * The agreement this payment is a cycle of, if it is one.
         *
         * Read from the provider, never from the caller: it is what decides
         * whether a payment extends somebody's access or is a one-off, and a
         * webhook that could assert it would be a way to extend a subscription
         * nobody is paying for.
         */
        public ?string $subscriptionId = null,
    ) {}

    public function isPaid(): bool
    {
        return $this->status === Payment::STATUS_PAID;
    }
}
