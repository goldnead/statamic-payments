<?php

namespace Goldnead\StatamicPayments\Events;

use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody started a checkout and did not finish it.
 *
 * Dispatched **once per payment**, claimed by a conditional update on
 * `abandoned_notified_at` rather than a read-then-write check — the sweep runs
 * on a schedule and may overlap itself, and the visible result of a missing
 * claim is a customer receiving the same reminder twice.
 *
 * Never dispatched for a payment that was fulfilled, and never for one that is
 * still within the waiting period: a person filling in their card details is
 * not an abandoned checkout.
 *
 * **What this event is not.** It is not permission to send mail. The address on
 * an unfinished checkout was given to complete a purchase, not to receive
 * advertising; whether a reminder may go out is a question of consent and of
 * the suppression list, and it is the listener's to answer, not this addon's.
 * `Payment::$email` is here because the listener needs it to ask.
 */
class CheckoutAbandoned
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
