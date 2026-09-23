<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Contracts\ListsSubscriptions;
use Goldnead\StatamicPayments\Contracts\PausesSubscriptions;
use Goldnead\StatamicPayments\Contracts\SubscriptionGateway;
use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Claims a dead process left behind (Gauntlet 23.09.2026).
 *
 * Pause, resume, switch and cancel each move the row into a claim
 * (`pausing`, `resuming`, `switching`, `cancelling`) before they talk to the
 * provider, and out of it afterwards. A process that dies in between leaves the
 * row there, and the provider in whatever state the call reached. This class
 * asks the provider and puts the row right, or says loudly what it cannot
 * decide. Only claims older than `Subscription::CLAIM_STALE_MINUTES` are
 * touched: a younger one may still be running.
 *
 * Run from `payments:resume-paused`, which is scheduled with
 * `->withoutOverlapping()`.
 */
class SubscriptionClaims
{
    public function __construct(
        protected Subscriptions $subscriptions,
        protected SubscriptionPauses $pauses,
    ) {}

    /** @return array{settled: int, unresolved: int} */
    public function sweep(): array
    {
        $settled = 0;
        $unresolved = 0;

        Subscription::query()
            ->whereIn('status', Subscription::CLAIMS)
            ->where('updated_at', '<=', Carbon::now()->subMinutes(Subscription::CLAIM_STALE_MINUTES))
            ->orderBy('id')
            ->each(function (Subscription $row) use (&$settled, &$unresolved) {
                try {
                    $this->settle($row) ? $settled++ : $unresolved++;
                } catch (Throwable $e) {
                    $unresolved++;
                    Log::error('statamic-payments: a subscription left in a claim could not be put right.', [
                        'subscription_id' => $row->getKey(),
                        'status' => $row->status,
                        'exception' => $e->getMessage(),
                    ]);
                }
            });

        return ['settled' => $settled, 'unresolved' => $unresolved];
    }

    protected function settle(Subscription $row): bool
    {
        $gateway = $this->subscriptions->gatewayFor($row);

        if ($gateway === null) {
            return $this->alarm($row, 'no provider can answer for it');
        }

        return match ($row->status) {
            Subscription::STATUS_PAUSING => $this->settlePause($row, $gateway),
            Subscription::STATUS_RESUMING => $this->settleResume($row, $gateway),
            Subscription::STATUS_SWITCHING => $this->settleSwitch($row, $gateway),
            Subscription::STATUS_CANCELLING => $this->subscriptions->cancel($row) || $this->alarm($row, 'the cancellation could not be finished'),
            default => true,
        };
    }

    /** Paused at the provider: finish it. Still charging: it never happened. */
    protected function settlePause(Subscription $row, SubscriptionGateway $gateway): bool
    {
        $remote = $gateway->fetchSubscription($row->customer_reference, $row->provider_id);
        $native = $gateway instanceof PausesSubscriptions;

        if ($remote->status === Subscription::STATUS_PAUSED || (! $native && ! $remote->isLive())) {
            $this->pauses->completePause($row, $native, $row->next_payment_at, $row->resumes_at, 'sweep');

            return true;
        }

        if ($remote->isLive()) {
            return $this->pauses->release($row, Subscription::STATUS_PAUSING, Subscription::STATUS_ACTIVE, ['resumes_at' => null]);
        }

        return $this->alarm($row, 'the provider reports "'.$remote->status.'" for a half-finished pause');
    }

    /**
     * Native: whatever the provider says. Re-created: the agreement the dead
     * resume started is adopted; where there is none, the resume runs again,
     * with the same idempotency key.
     */
    protected function settleResume(Subscription $row, SubscriptionGateway $gateway): bool
    {
        $this->pauses->release($row, Subscription::STATUS_RESUMING, Subscription::STATUS_PAUSED);
        $row = $row->fresh() ?? $row;

        if (data_get($row->meta, 'pause.mode') === 'native') {
            $remote = $gateway->fetchSubscription($row->customer_reference, $row->provider_id);

            return $remote->isLive() ? $this->pauses->adoptProviderResume($row, $remote, 'sweep') : true;
        }

        $orphan = $this->orphansOf($row, $gateway)[0] ?? null;

        return $orphan !== null
            ? $this->pauses->adoptProviderResume($row, $orphan, 'sweep')
            : $this->pauses->resume($row, 'sweep');
    }

    /**
     * A switch that re-created the agreement is adopted. One that changed the
     * amount in place cannot be read back (the provider's answer carries no
     * amount here): said loudly, left as it is for a person.
     */
    protected function settleSwitch(Subscription $row, SubscriptionGateway $gateway): bool
    {
        $orphan = $this->orphansOf($row, $gateway)[0] ?? null;

        if ($orphan === null) {
            return $this->alarm($row, 'a switch to ['.$row->product.'] did not finish; check the amount the provider charges, then set the row to active or back to the old product');
        }

        $row->rememberProviderId((string) $row->provider_id);
        $row->forceFill(['status' => $orphan->status, 'provider_id' => $orphan->providerId])->save();

        return true;
    }

    /**
     * Agreements this package started for this row that the row does not
     * name: running, with the row's id in their metadata, under another id.
     *
     * @return list<RemoteSubscription>
     */
    public function orphansOf(Subscription $row, SubscriptionGateway $gateway): array
    {
        if (! $gateway instanceof ListsSubscriptions) {
            return [];
        }

        return array_values(array_filter(
            $gateway->subscriptionsFor((string) $row->customer_reference),
            fn (RemoteSubscription $remote) => $remote->isLive()
                && $remote->providerId !== $row->provider_id
                && ((int) ($remote->meta['resumed_subscription_id'] ?? 0) === (int) $row->getKey()
                    || (int) ($remote->meta['switched_subscription_id'] ?? 0) === (int) $row->getKey()),
        ));
    }

    /** Cancelling a row that was stuck ends what it left at the provider too. */
    public function endOrphans(Subscription $row, SubscriptionGateway $gateway): void
    {
        foreach ($this->orphansOf($row, $gateway) as $orphan) {
            $gateway->cancelSubscription((string) $row->customer_reference, $orphan->providerId);

            Log::warning('statamic-payments: an agreement a dead change had started was ended with its row.', [
                'subscription_id' => $row->getKey(),
                'provider_id' => $orphan->providerId,
            ]);
        }
    }

    protected function alarm(Subscription $row, string $why): bool
    {
        Log::error('statamic-payments: a subscription is stuck in a claim: '.$why.'.', [
            'subscription_id' => $row->getKey(),
            'status' => $row->status,
            'provider_id' => $row->provider_id,
        ]);

        return false;
    }
}
