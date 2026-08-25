<?php

namespace Goldnead\StatamicPayments\Gateways;

use Goldnead\StatamicPayments\Contracts\FollowUpGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\CheckoutSession;
use Goldnead\StatamicPayments\Support\RemotePayment;
use Illuminate\Support\Facades\Log;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Types\SequenceType;

/**
 * Mollie, behind the seam.
 *
 * Chosen over Stripe for a German and European audience: SEPA direct debit,
 * Sofort, iDEAL and Bancontact are what people actually reach for here, and
 * Mollie's pricing has no monthly floor — which matters for a client site that
 * takes four payments a month.
 *
 * Built on Mollie's plain PHP SDK rather than their Laravel wrapper. The
 * wrapper pins `illuminate/support` to ^12 at the latest, and Statamic 6 runs
 * on Laravel 12 *or 13* — so the wrapper would have made this addon
 * uninstallable on half the versions its own composer.json promises. Found by
 * installing it, not by reading it.
 */
class MollieGateway implements FollowUpGateway
{
    public function supportsFollowUp(): bool
    {
        return true;
    }

    public function rememberBuyer(array $buyer): string
    {
        $customer = $this->client->customers->create(array_filter([
            'name' => $buyer['name'] ?? null,
            'email' => $buyer['email'] ?? null,
        ]));

        return (string) $customer->id;
    }

    /**
     * Mollie's recurring path.
     *
     * The first payment has to have been made with `sequenceType: first` and a
     * customer attached — that is what leaves a mandate behind. Without one,
     * Mollie refuses, which is the correct outcome: it means the buyer never
     * agreed to be charged again.
     */
    public function chargeAgain(string $customerReference, array $payload): RemotePayment
    {
        $payment = $this->client->customerPayments->createForId($customerReference, $payload + [
            'sequenceType' => SequenceType::RECURRING,
        ]);

        return new RemotePayment(
            providerId: (string) $payment->id,
            status: $this->normalise((string) $payment->status),
            metadata: json_decode(json_encode($payment->metadata) ?: '{}', true) ?: [],
            email: $this->email($payment),
        );
    }

    public function provider(): string
    {
        return 'mollie';
    }

    public function __construct(protected MollieApiClient $client) {}

    public function createPayment(array $payload): CheckoutSession
    {
        $payment = $this->client->payments->create($payload);

        return new CheckoutSession(
            providerId: (string) $payment->id,
            checkoutUrl: (string) $payment->getCheckoutUrl(),
        );
    }

    public function fetch(string $providerId): RemotePayment
    {
        $payment = $this->client->payments->get($providerId);

        return new RemotePayment(
            providerId: (string) $payment->id,
            status: $this->normalise((string) $payment->status),
            metadata: json_decode(json_encode($payment->metadata) ?: '{}', true) ?: [],
            email: $this->email($payment),
        );
    }

    /**
     * Mollie's vocabulary, mapped to ours.
     *
     * Anything unrecognised becomes `open` rather than something decisive: a
     * status this package has not met must never be read as "paid", and reading
     * it as "failed" would cancel an order that is merely pending.
     */
    protected function normalise(string $status): string
    {
        $known = match ($status) {
            'paid' => Payment::STATUS_PAID,
            'failed' => Payment::STATUS_FAILED,
            'expired' => Payment::STATUS_EXPIRED,
            'canceled', 'cancelled' => Payment::STATUS_CANCELED,
            'open', 'pending', 'authorized' => Payment::STATUS_OPEN,
            default => null,
        };

        if ($known === null) {
            // `open` is the safe landing, but it is indistinguishable from a
            // real `open` afterwards. Without this line a provider adding a
            // status would change what this package does and leave no trace.
            Log::warning('statamic-payments: unknown Mollie status, treated as open.', ['status' => $status]);

            return Payment::STATUS_OPEN;
        }

        return $known;
    }

    protected function email(mixed $payment): ?string
    {
        foreach ([
            $payment->details->consumerEmail ?? null,
            $payment->billingEmail ?? null,
            $payment->metadata->email ?? null,
        ] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
