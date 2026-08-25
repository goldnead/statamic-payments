<?php

namespace Goldnead\StatamicPayments\Tests\Support;

use Goldnead\StatamicPayments\Contracts\FollowUpGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\CheckoutSession;
use Goldnead\StatamicPayments\Support\RemotePayment;
use RuntimeException;

/**
 * Stands in for the provider, and answers only what it was told to answer.
 *
 * This is what makes the central property testable: a test can post a webhook
 * claiming "paid" while the gateway still says "open", which is precisely the
 * forgery the design is meant to survive.
 */
class FakeGateway implements FollowUpGateway
{
    /** Customer ids the provider will accept a second charge for. */
    public array $mandates = [];

    public bool $refuseFollowUp = false;

    public function supportsFollowUp(): bool
    {
        return true;
    }

    public bool $refuseToRemember = false;

    public function rememberBuyer(array $buyer): string
    {
        if ($this->refuseToRemember) {
            throw new RuntimeException('the provider would not remember this buyer');
        }

        $reference = 'cst_'.count($this->mandates + [1]);
        $this->mandates[] = $reference;

        return $reference;
    }

    public function chargeAgain(string $customerReference, array $payload): RemotePayment
    {
        if ($this->refuseFollowUp || ! in_array($customerReference, $this->mandates, true)) {
            // What Mollie does when there is no mandate: it refuses. Which is
            // correct — no mandate means the buyer never agreed to this.
            throw new RuntimeException('no mandate for '.$customerReference);
        }

        $this->created++;
        $id = 'tr_folge_'.$this->created;
        $this->metadata[$id] = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        // Deliberately not `paid`. A recurring charge is usually accepted
        // first and confirmed later; a fake that answered `paid` straight away
        // would hide every mistake that treats acceptance as payment.
        $this->remote[$id] = new RemotePayment($id, Payment::STATUS_OPEN, $this->metadata[$id]);

        return $this->remote[$id];
    }

    /** @var array<string, RemotePayment> */
    public array $remote = [];

    /** @var list<string> */
    public array $fetched = [];

    public int $created = 0;

    /** @var array<string, array<string, mixed>> */
    public array $metadata = [];

    public bool $throwOnFetch = false;

    public function provider(): string
    {
        return 'fake';
    }

    public function createPayment(array $payload): CheckoutSession
    {
        $this->created++;
        $id = 'tr_'.($this->created);

        $this->metadata[$id] = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $this->remote[$id] = new RemotePayment($id, Payment::STATUS_OPEN, $this->metadata[$id]);

        return new CheckoutSession($id, 'https://checkout.example/'.$id);
    }

    public function fetch(string $providerId): RemotePayment
    {
        $this->fetched[] = $providerId;

        if ($this->throwOnFetch) {
            throw new RuntimeException('the provider is unreachable');
        }

        // An id this account never issued is a 404 at Mollie, not an `open`
        // payment. The fake says so too, because the difference is exactly what
        // the unknown-id path has to survive.
        return $this->remote[$providerId]
            ?? throw new RuntimeException('no such payment: '.$providerId);
    }

    /** Let the provider say the payment is paid. Only the provider may. */
    public function markPaid(string $providerId, ?string $email = null): void
    {
        $this->remote[$providerId] = new RemotePayment(
            $providerId, Payment::STATUS_PAID, $this->metadata[$providerId] ?? [], $email
        );
    }

    public function markStatus(string $providerId, string $status): void
    {
        $this->remote[$providerId] = new RemotePayment(
            $providerId, $status, $this->metadata[$providerId] ?? []
        );
    }

    /** Let the provider know a payment the site has lost the id for. */
    public function knows(string $providerId, string $status, array $metadata): void
    {
        $this->metadata[$providerId] = $metadata;
        $this->remote[$providerId] = new RemotePayment($providerId, $status, $metadata);
    }
}
