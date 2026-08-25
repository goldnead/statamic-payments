<?php

namespace Goldnead\StatamicPayments\Support;

/**
 * Something taken off a total, worked out somewhere else.
 *
 * This addon deliberately knows nothing about coupons. What a code is worth, who
 * may use it and how often are questions about *pricing*, and pricing lives in
 * the offers addon. What lives here is the consequence: a total is lower than
 * the lines add up to, and the payment has to say why.
 *
 * The rule that survives: **no amount comes from a request**. A `Discount` is
 * built by server-side code that looked something up, never from input. The
 * checkout enforces the arithmetic anyway — a discount cannot exceed the total
 * and cannot be negative — because a bug upstream should cost a wrong price, not
 * a payment the provider rejects or a refund.
 */
final class Discount
{
    public function __construct(
        public readonly string $code,
        public readonly int $amountCent,
        public readonly ?string $label = null,
    ) {}

    /** What is actually taken off a given total. */
    public function against(int $totalCent): int
    {
        return max(0, min($this->amountCent, $totalCent));
    }
}
