<?php

namespace Goldnead\StatamicPayments\Events;

use Goldnead\StatamicPayments\Listeners\WithdrawOnChargeback;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The bank took the money back.
 *
 * Not a refund, and the difference is not bookkeeping pedantry. A refund is a
 * decision somebody here made; a chargeback is one made against them. It costs
 * a fee on top of the amount, it has a deadline for evidence, and it may still
 * be won.
 *
 * **The invoice is not cancelled**, here or anywhere downstream. A cancellation
 * says the sale did not happen, and that is a human decision — the same reason
 * this package refuses to make refunds and only records them. What does happen
 * automatically is the access: somebody who has their money back does not keep
 * the thing they bought.
 *
 * @see WithdrawOnChargeback
 */
class PaymentChargedBack
{
    use Dispatchable;

    public function __construct(
        public readonly Payment $payment,
        /** The provider's own id for the dispute — what makes a redelivery a no-op. */
        public readonly string $reference,
        /** In minor units, and zero where the provider did not say. */
        public readonly int $amountCent,
        /** The provider's own word for why, untranslated. Absent on Mollie. */
        public readonly ?string $reason = null,
    ) {}
}
