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
    ) {}

    public function isPaid(): bool
    {
        return $this->status === Payment::STATUS_PAID;
    }
}
