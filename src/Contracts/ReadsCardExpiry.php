<?php

namespace Goldnead\StatamicPayments\Contracts;

use Illuminate\Support\Carbon;

/**
 * A provider that can say when the card on file expires.
 *
 * Null when there is no card (SEPA, PayPal) or the provider does not say. The
 * reminder run treats null as "nothing to remind about", never as "expired".
 */
interface ReadsCardExpiry
{
    /** The last day the buyer's card is valid, or null. */
    public function cardExpiry(string $customerReference): ?Carbon;
}
