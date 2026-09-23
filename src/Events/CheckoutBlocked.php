<?php

namespace Goldnead\StatamicPayments\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A checkout was refused before it started (P7): block list, rate limit or
 * captcha. Nothing was written and no provider was called.
 */
class CheckoutBlocked
{
    use Dispatchable;

    public function __construct(
        /** `blocked_email`, `blocked_domain`, `blocked_ip`, `rate_limited` or `captcha`. */
        public readonly string $reason,
        public readonly ?string $email,
        public readonly ?string $ip,
    ) {}
}
