<?php

namespace Goldnead\StatamicPayments\Support;

/** What a provider gives back when a payment is created: an id and somewhere to send the buyer. */
final readonly class CheckoutSession
{
    public function __construct(
        public string $providerId,
        public string $checkoutUrl,
    ) {}
}
