<?php

namespace Goldnead\StatamicPayments\Gateways;

use Goldnead\StatamicPayments\Contracts\MandateGateway;
use Goldnead\StatamicPayments\Contracts\SubscriptionGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\CheckoutSession;
use Goldnead\StatamicPayments\Support\RemotePayment;
use Goldnead\StatamicPayments\Support\RemoteSubscription;
use Illuminate\Support\Facades\Log;
use Mollie\Api\Exceptions\ApiException;
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
class MollieGateway implements MandateGateway, SubscriptionGateway
{
    public function supportsFollowUp(): bool
    {
        return true;
    }

    public function supportsMandateUpdate(): bool
    {
        return true;
    }

    /**
     * A new card for a buyer Mollie already knows.
     *
     * Mollie's only mechanism, and it is worth being blunt about it: there is no
     * SetupIntent here and no zero-amount authorisation. A mandate comes from a
     * payment made with `sequenceType: first` against the customer, and nothing
     * else. So changing a payment method **charges the buyer**, once, a small
     * amount — the same trade `Subscriptions` refuses to hide behind the word
     * "trial", refused again here.
     *
     * **No webhook on this payment, deliberately.** It buys a mandate, not a
     * product: there is no local row for it, nothing to fulfil, and no
     * entitlement to grant. Handing Mollie a webhook URL would deliver a paid
     * payment this site has no record of into the fulfilment path, which logs it
     * as a phantom purchase — a loud alarm about something that went exactly to
     * plan.
     *
     * What makes it work afterwards is that this package never pins a `mandateId`
     * when it creates a subscription. Mollie charges the customer's valid mandate,
     * so the newest one wins from the next cycle onwards without anything here
     * having to rewrite the agreement.
     */
    public function startMandateUpdate(string $customerReference, array $payload): CheckoutSession
    {
        $payment = $this->client->customerPayments->createForId($customerReference, $payload + [
            'sequenceType' => SequenceType::FIRST,
        ]);

        return new CheckoutSession(
            providerId: (string) $payment->id,
            checkoutUrl: (string) $payment->getCheckoutUrl(),
        );
    }

    /**
     * What the buyer pays to put a new card on file.
     *
     * From configuration, because the floor is a property of the payment method
     * rather than of Mollie: a card accepts a cent, SEPA and some local methods
     * do not. Clamped to at least one minor unit — Mollie rejects a zero amount,
     * and a site that set it to zero would get an error on the buyer's screen
     * instead of a mandate.
     */
    public function mandateVerificationCent(): int
    {
        return max(1, (int) config('statamic-payments.portal.mandate_verification_cent', 1));
    }

    public function supportsSubscriptions(): bool
    {
        return true;
    }

    public function createSubscription(string $customerReference, array $payload): RemoteSubscription
    {
        $subscription = $this->client->subscriptions->createForId($customerReference, $payload);

        return $this->asRemote($subscription);
    }

    public function cancelSubscription(string $customerReference, string $subscriptionId): RemoteSubscription
    {
        // Mollie answers 410 for one that is already gone. That is the outcome
        // the caller wanted, so it is read as such rather than thrown: a second
        // cancel click must not look like a failure.
        try {
            $subscription = $this->client->subscriptions->cancelForId($customerReference, $subscriptionId);
        } catch (ApiException $e) {
            if ($e->getCode() === 410) {
                return new RemoteSubscription($subscriptionId, Subscription::STATUS_CANCELLED);
            }

            throw $e;
        }

        return $this->asRemote($subscription);
    }

    public function fetchSubscription(string $customerReference, string $subscriptionId): RemoteSubscription
    {
        return $this->asRemote($this->client->subscriptions->getForId($customerReference, $subscriptionId));
    }

    protected function asRemote(mixed $subscription): RemoteSubscription
    {
        return new RemoteSubscription(
            providerId: (string) $subscription->id,
            status: $this->normaliseSubscription((string) $subscription->status),
            nextPaymentAt: isset($subscription->nextPaymentDate) ? (string) $subscription->nextPaymentDate : null,
            timesCharged: isset($subscription->timesRemaining, $subscription->times)
                ? max(0, (int) $subscription->times - (int) $subscription->timesRemaining)
                : null,
            meta: json_decode(json_encode($subscription->metadata) ?: '{}', true) ?: [],
        );
    }

    /**
     * Mollie's subscription vocabulary, mapped to ours.
     *
     * Same rule as for payments and for the same reason: an unknown status must
     * not be read as running. `suspended` is the safe landing here rather than
     * `active`, because acting on a suspended agreement as if it were live is
     * the error that keeps somebody's access open after their card stopped
     * working.
     */
    protected function normaliseSubscription(string $status): string
    {
        $known = match ($status) {
            'active' => Subscription::STATUS_ACTIVE,
            'pending' => Subscription::STATUS_PENDING,
            'canceled', 'cancelled' => Subscription::STATUS_CANCELLED,
            'completed' => Subscription::STATUS_COMPLETED,
            'suspended' => Subscription::STATUS_SUSPENDED,
            default => null,
        };

        if ($known === null) {
            Log::warning('statamic-payments: unknown Mollie subscription status, treated as suspended.', ['status' => $status]);

            return Subscription::STATUS_SUSPENDED;
        }

        return $known;
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
            country: $this->country($payment),
            cardLast4: $this->cardLast4($payment),
            cardLabel: $this->cardLabel($payment),
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
            country: $this->country($payment),
            cardLast4: $this->cardLast4($payment),
            cardLabel: $this->cardLabel($payment),
            // Present only on a payment Mollie made on its own, on a rhythm.
            subscriptionId: isset($payment->subscriptionId) && $payment->subscriptionId
                ? (string) $payment->subscriptionId
                : null,
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

    /**
     * The buyer's country, wherever Mollie happens to record it.
     *
     * Different payment methods put it in different places: a card carries the
     * issuer's country, a bank transfer the account's, iDEAL implies NL. This
     * reads the ones that exist and returns nothing rather than guessing —
     * a wrong country produces a wrong VAT rate, which looks like an answer.
     */
    protected function country(mixed $payment): ?string
    {
        foreach ([
            $payment->details->cardCountryCode ?? null,
            $payment->details->consumerCountryCode ?? null,
            $payment->details->countryCode ?? null,
            $payment->billingAddress->country ?? null,
        ] as $kandidat) {
            if (is_string($kandidat) && preg_match('/^[A-Za-z]{2}$/', $kandidat) === 1) {
                return strtoupper($kandidat);
            }
        }

        return null;
    }

    /**
     * Die letzten vier Stellen der Karte, wenn es eine Karte war.
     *
     * Mollie liefert unter `details.cardNumber` bereits nur die letzten vier
     * Stellen — hier wird trotzdem gefiltert statt vertraut: was nicht wie vier
     * Ziffern aussieht, wird verworfen, damit auf keiner Seite etwas landet,
     * das nach einer Kartennummer aussehen koennte.
     */
    protected function cardLast4(mixed $payment): ?string
    {
        $kandidat = $payment->details->cardNumber ?? null;

        return is_string($kandidat) && preg_match('/^\d{4}$/', $kandidat) === 1
            ? $kandidat
            : null;
    }

    /** Die Marke der Karte („Mastercard"), soweit der Anbieter sie nennt. */
    protected function cardLabel(mixed $payment): ?string
    {
        $kandidat = $payment->details->cardLabel ?? null;

        return is_string($kandidat) && $kandidat !== ''
            ? mb_substr($kandidat, 0, 32)
            : null;
    }
}
