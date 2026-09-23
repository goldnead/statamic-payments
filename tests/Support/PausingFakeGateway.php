<?php

namespace Goldnead\StatamicPayments\Tests\Support;

use Goldnead\StatamicPayments\Contracts\PausesSubscriptions;
use Goldnead\StatamicPayments\Contracts\ReadsCardExpiry;
use Goldnead\StatamicPayments\Contracts\UpdatesSubscriptions;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\ProviderUnavailable;
use Goldnead\StatamicPayments\Support\RemoteSubscription;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The fake, with the three capabilities Stripe has and the plain fake lacks.
 *
 * The plain `FakeGateway` stands for Mollie here: no native pause, so the
 * package has to end and re-create. This one stands for Stripe: the agreement
 * stays and changes state in place. Each records what it was asked, so a test
 * can say which path was taken rather than only that something happened.
 */
class PausingFakeGateway extends FakeGateway implements PausesSubscriptions, ReadsCardExpiry, UpdatesSubscriptions
{
    /** @var list<array{id: string, resumes_at: ?string}> */
    public array $paused = [];

    /** @var list<string> */
    public array $resumed = [];

    /** @var list<array{id: string, payload: array<string, mixed>}> */
    public array $updated = [];

    public bool $refuseToPause = false;

    public bool $refuseToUpdate = false;

    /** `updateSubscription()` takes the new amount, then the answer is lost. */
    public bool $loseTheUpdateAnswer = false;

    /** @var array<string, string|null> customer => Y-m-d */
    public array $cardExpiries = [];

    public int $cardAsked = 0;

    public function pauseSubscription(string $customerReference, string $subscriptionId, ?Carbon $resumesAt = null): RemoteSubscription
    {
        if ($this->refuseToPause) {
            throw new RuntimeException('the provider would not pause '.$subscriptionId);
        }

        $this->paused[] = ['id' => $subscriptionId, 'resumes_at' => $resumesAt?->toDateString()];
        $this->subscriptions[$subscriptionId]['status'] = Subscription::STATUS_PAUSED;

        return new RemoteSubscription($subscriptionId, Subscription::STATUS_PAUSED);
    }

    public function resumeSubscription(string $customerReference, string $subscriptionId): RemoteSubscription
    {
        $this->resumed[] = $subscriptionId;
        $this->subscriptions[$subscriptionId]['status'] = Subscription::STATUS_ACTIVE;

        return new RemoteSubscription($subscriptionId, Subscription::STATUS_ACTIVE);
    }

    public function updateSubscription(string $customerReference, string $subscriptionId, array $payload): RemoteSubscription
    {
        if ($this->refuseToUpdate) {
            throw new RuntimeException('the provider would not update '.$subscriptionId);
        }

        $this->updated[] = ['id' => $subscriptionId, 'payload' => $payload];

        if ($this->loseTheUpdateAnswer) {
            $this->loseTheUpdateAnswer = false;

            throw new ProviderUnavailable('timed out after the provider took the new amount');
        }

        return new RemoteSubscription($subscriptionId, $this->subscriptions[$subscriptionId]['status'] ?? Subscription::STATUS_ACTIVE);
    }

    public function cardExpiry(string $customerReference): ?Carbon
    {
        $this->cardAsked++;
        $date = $this->cardExpiries[$customerReference] ?? null;

        return $date === null ? null : Carbon::parse($date);
    }
}
