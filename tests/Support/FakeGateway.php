<?php

namespace Goldnead\StatamicPayments\Tests\Support;

use Closure;
use Goldnead\StatamicPayments\Contracts\SubscriptionGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\CheckoutSession;
use Goldnead\StatamicPayments\Support\RemotePayment;
use Goldnead\StatamicPayments\Support\RemoteSubscription;
use RuntimeException;

/**
 * Stands in for the provider, and answers only what it was told to answer.
 *
 * This is what makes the central property testable: a test can post a webhook
 * claiming "paid" while the gateway still says "open", which is precisely the
 * forgery the design is meant to survive.
 */
class FakeGateway implements SubscriptionGateway
{
    /**
     * Was in dem Augenblick gilt, in dem der Anbieter gerufen wird.
     *
     * Der einzige Weg, die Reihenfolge zu belegen statt sie zu behaupten: ein
     * Test schaut von hier aus in die Datenbank und sieht genau den Zustand,
     * den auch ein Webhook sähe, der jetzt einträfe.
     *
     * Typisiert und nicht nullbar, also im Konstruktor gesetzt: eine Closure
     * lässt sich nicht als Vorgabewert einer Eigenschaft schreiben, und ein
     * `null` mit Prüfung an jeder Aufrufstelle wäre dasselbe mit mehr
     * Gelegenheit, eine davon zu vergessen.
     */
    public Closure $whileCalling;

    public function __construct()
    {
        $this->whileCalling = fn (array $payload) => null;
    }

    /** Agreements this fake believes exist, keyed by id. */
    public array $subscriptions = [];

    public array $cancelled = [];

    public bool $refuseSubscriptions = false;

    /** The provider can do subscriptions and refuses this one. */
    public bool $refuseThisSubscription = false;

    /** A cancel that the provider accepts but that leaves the thing running. */
    public bool $cancelLies = false;

    /** The provider is unreachable, or refuses. What a 500 or a timeout is. */
    public bool $refuseToCancel = false;

    /** For the case where the provider is still waiting for the mandate. */
    public bool $subscriptionsArePending = false;

    public int $subscriptionsCreated = 0;

    public array $lastSubscriptionPayload = [];

    public function supportsSubscriptions(): bool
    {
        return ! $this->refuseSubscriptions;
    }

    public function createSubscription(string $customerReference, array $payload): RemoteSubscription
    {
        if ($this->refuseSubscriptions || $this->refuseThisSubscription) {
            throw new RuntimeException('this provider would not create the subscription');
        }

        $this->subscriptionsCreated++;
        $this->lastSubscriptionPayload = $payload;

        $id = 'sub_'.$this->subscriptionsCreated;

        $this->subscriptions[$id] = [
            'customer' => $customerReference,
            // Active, even when the first charge is a month away: the
            // agreement exists because the mandate does. A provider reports
            // `pending` only while it is still waiting for that mandate, which
            // in this package's flow has already happened.
            'status' => $this->subscriptionsArePending ? Subscription::STATUS_PENDING : Subscription::STATUS_ACTIVE,
        ];

        return new RemoteSubscription(
            providerId: $id,
            status: $this->subscriptions[$id]['status'],
            nextPaymentAt: $payload['startDate'] ?? null,
        );
    }

    public function cancelSubscription(string $customerReference, string $subscriptionId): RemoteSubscription
    {
        if ($this->refuseToCancel) {
            // Thrown before anything is recorded, like a provider that never
            // received the request. A fake that noted the attempt and then threw
            // would let a caller believe the cancellation had been seen.
            throw new RuntimeException('the provider would not cancel '.$subscriptionId);
        }

        $this->cancelled[] = $subscriptionId;

        if ($this->cancelLies) {
            return new RemoteSubscription($subscriptionId, Subscription::STATUS_ACTIVE);
        }

        $this->subscriptions[$subscriptionId]['status'] = Subscription::STATUS_CANCELLED;

        return new RemoteSubscription($subscriptionId, Subscription::STATUS_CANCELLED);
    }

    public function fetchSubscription(string $customerReference, string $subscriptionId): RemoteSubscription
    {
        return new RemoteSubscription(
            providerId: $subscriptionId,
            status: $this->subscriptions[$subscriptionId]['status'] ?? Subscription::STATUS_CANCELLED,
        );
    }

    /** Make the provider report this payment as a cycle of an agreement. */
    public function markAsCycle(string $providerId, string $subscriptionId): void
    {
        $before = $this->remote[$providerId];

        $this->remote[$providerId] = new RemotePayment(
            providerId: $before->providerId,
            status: $before->status,
            metadata: $before->metadata,
            email: $before->email,
            subscriptionId: $subscriptionId,
        );
    }

    /** Customer ids the provider will accept a second charge for. */
    public array $mandates = [];

    /**
     * Die Mandate, die dieser Anbieter kennt.
     *
     * Leer heisst „nicht geprueft", damit die vorhandenen Tests, die von
     * Mandaten nichts wissen, unveraendert durchlaufen. Wer die Prueung will,
     * traegt hier ein, was gelten soll.
     *
     * @var list<string>
     */
    public array $knownMandates = [];

    /**
     * Mandate, die es gab und die nicht mehr gelten.
     *
     * Getrennt von `knownMandates` gefuehrt, weil widerrufen etwas anderes ist
     * als nie dagewesen: der Kaeufer hat einmal zugestimmt und sie
     * zurueckgezogen. Fuer den Aufrufer endet beides gleich — der Anbieter
     * lehnt ab —, aber ein Test soll den Unterschied ausdruecken koennen.
     *
     * @var list<string>
     */
    public array $revokedMandates = [];

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
        ($this->whileCalling)($payload);

        if ($this->refuseFollowUp || ! in_array($customerReference, $this->mandates, true)) {
            // What Mollie does when there is no mandate: it refuses. Which is
            // correct — no mandate means the buyer never agreed to this.
            throw new RuntimeException('no mandate for '.$customerReference);
        }

        // Ein ausdruecklich benanntes Mandat wird ausdruecklich geprueft.
        //
        // So streng wie das Original: Mollie lehnt ein `mandateId` ab, das dem
        // Kunden nicht gehoert, widerrufen oder abgelaufen ist — es weicht
        // NICHT auf ein anderes gueltiges Mandat aus. Ein Fake, der den
        // Schluessel bloss durchwinkt, wuerde genau den Fehler verstecken, den
        // die Spalte `mandate_id` verhindern soll, und der Test darueber waere
        // eine Selbstbestaetigung.
        if (array_key_exists('mandateId', $payload)) {
            $verlangt = $payload['mandateId'];

            if (! is_string($verlangt) || trim($verlangt) === '') {
                throw new RuntimeException('mandateId was set but empty');
            }

            if (in_array($verlangt, $this->revokedMandates, true)) {
                throw new RuntimeException('mandate revoked: '.$verlangt);
            }

            if ($this->knownMandates !== [] && ! in_array($verlangt, $this->knownMandates, true)) {
                throw new RuntimeException('unknown mandate: '.$verlangt);
            }
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

    /** What the last `createPayment()` was handed, so a test can look at it. */
    public array $lastPayload = [];

    /** @var array<string, array<string, mixed>> */
    public array $metadata = [];

    public bool $throwOnFetch = false;

    public function provider(): string
    {
        return 'fake';
    }

    public function createPayment(array $payload): CheckoutSession
    {
        ($this->whileCalling)($payload);

        $this->lastPayload = $payload;
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

    /**
     * Let the provider say the payment is paid. Only the provider may.
     *
     * `$mandateId` ist das Einzugsrecht, das diese Zahlung hinterlassen hat —
     * bei Mollie gesetzt auf einer Zahlung mit `sequenceType: first`, leer bei
     * einer einmaligen. Getrennt angebbar, weil eine Folgeabbuchung ihr eigenes
     * Mandat zurueckmeldet und der Test genau das ausdruecken koennen muss.
     */
    public function markPaid(string $providerId, ?string $email = null, ?string $cardLast4 = null, ?string $cardLabel = null, ?string $mandateId = null): void
    {
        $this->remote[$providerId] = new RemotePayment(
            providerId: $providerId,
            status: Payment::STATUS_PAID,
            metadata: $this->metadata[$providerId] ?? [],
            email: $email,
            cardLast4: $cardLast4,
            cardLabel: $cardLabel,
            mandateId: $mandateId,
        );
    }

    public function markStatus(string $providerId, string $status): void
    {
        $this->remote[$providerId] = new RemotePayment(
            $providerId, $status, $this->metadata[$providerId] ?? []
        );
    }

    /**
     * A payment the provider made on its own: a cycle of a running agreement.
     *
     * There is no local row for it, which is the whole point — the site never
     * asked for this payment and only learns of it from the webhook.
     */
    public function arrive(string $product, int $amountCent, string $subscriptionId): string
    {
        $this->created++;
        $id = 'tr_zyklus_'.$this->created;

        $this->metadata[$id] = ['product' => $product];
        $this->remote[$id] = new RemotePayment(
            providerId: $id,
            status: Payment::STATUS_PAID,
            metadata: $this->metadata[$id],
            email: null,
            subscriptionId: $subscriptionId,
        );

        return $id;
    }

    /** Let the provider know a payment the site has lost the id for. */
    public function knows(string $providerId, string $status, array $metadata): void
    {
        $this->metadata[$providerId] = $metadata;
        $this->remote[$providerId] = new RemotePayment($providerId, $status, $metadata);
    }
}
