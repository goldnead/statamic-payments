<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * What {@see Cancellations::cancel()} did, in the terms a screen or an API
 * response needs to tell the buyer the truth.
 *
 * Four answers and no fifth. `cancelled()` is true for the first two only: an
 * agreement that is over, either just now or already before the call.
 */
final class CancellationOutcome
{
    /** Ended just now; the provider confirmed. */
    public const CANCELLED = 'cancelled';

    /** Was over before this call. Not an error, and not a second cancellation. */
    public const ALREADY_ENDED = 'already_ended';

    /** A pause, resume or switch is talking to the provider. Try again shortly. */
    public const BUSY = 'busy';

    /** The provider did not confirm. Nothing was written. */
    public const FAILED = 'failed';

    public function __construct(
        public readonly string $status,
        public readonly Subscription $subscription,
        public readonly string $name,
        public readonly ?string $email,
        public readonly ?Carbon $moment = null,
        public readonly ?Carbon $until = null,
        public readonly bool $confirmationSent = false,
    ) {}

    public function cancelled(): bool
    {
        return $this->status === self::CANCELLED || $this->status === self::ALREADY_ENDED;
    }
}
