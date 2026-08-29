<?php

namespace Goldnead\StatamicPayments\Contracts;

use Goldnead\StatamicPayments\Support\CheckoutSession;

/**
 * Letting a buyer put a different card on file.
 *
 * A fourth interface rather than more methods on `SubscriptionGateway`, for the
 * third time and the same reason: a provider that cannot do this should not have
 * to declare a stub that throws, and the portal should be able to ask before it
 * offers a button rather than find out when somebody presses it.
 *
 * **What "changing a payment method" actually is.** No provider stores a card
 * because a page asked politely. Mollie has no zero-amount authorisation and no
 * hosted "update your card" screen; Stripe has a SetupIntent; a fourth provider
 * will have a fifth mechanism. What they have in common is the only thing this
 * interface promises: somewhere to send the buyer, after which the provider
 * holds a new mandate for them. Everything else is the implementation's problem.
 *
 * **It may cost the buyer money, and the implementation has to say so.** Where a
 * provider can only establish a mandate through a real payment, the buyer is
 * charged a small verification amount. That is a fact about the provider, not a
 * choice this package makes, and the portal tells the buyer the amount before
 * the button — see `portal.mandate_note` in the translations.
 */
interface MandateGateway extends PaymentGateway
{
    /** Whether this provider can take a new payment method for an existing buyer. */
    public function supportsMandateUpdate(): bool;

    /**
     * Send the buyer somewhere to establish a new mandate.
     *
     * @param  string  $customerReference  what the first payment left behind —
     *                                     the same value `chargeAgain()` takes
     * @param  array<string, mixed>  $payload  `amount`, `description`,
     *                                         `redirectUrl`, `metadata`
     */
    public function startMandateUpdate(string $customerReference, array $payload): CheckoutSession;

    /**
     * What the buyer is charged to establish the mandate, in minor units.
     *
     * Zero where the provider can do it without taking money. The portal shows
     * this number to the buyer, so a provider that guesses here is a provider
     * that lies to somebody on a page about their own bank details.
     */
    public function mandateVerificationCent(): int;
}
