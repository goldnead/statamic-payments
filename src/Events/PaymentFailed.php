<?php

namespace Goldnead\StatamicPayments\Events;

use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The seam. What a payment *means* belongs to the site: granting access,
 * sending a file, notifying someone. This addon takes money and says so.
 *
 * Dispatched **once per payment**, guaranteed by a conditional update on
 * `failed_notified_at` rather than by a read-then-write check — so two
 * deliveries arriving together cannot both announce the same failure, and a
 * listener need not carry its own idempotency to avoid mailing twice.
 *
 * Not dispatched at all for a payment that was already fulfilled: an unfamiliar
 * provider status must not revoke what somebody paid for.
 */
class PaymentFailed
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
