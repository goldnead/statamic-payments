<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Contracts\PausesSubscriptions;
use Goldnead\StatamicPayments\Events\SubscriptionPaused;
use Goldnead\StatamicPayments\Events\SubscriptionResumed;
use Goldnead\StatamicPayments\Integrations\EntitlementsBridge;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pausing an agreement and taking it up again.
 *
 * **Two providers, two mechanisms, one behaviour.** Stripe pauses natively
 * (`pause_collection` with `void`): the agreement stays, its invoices during the
 * pause are voided, and it bills on its old anchor afterwards. Mollie has no
 * pause. There this class ends the running agreement and, on resume, starts a
 * new one against the same mandate, on the old billing day. The buyer sees the
 * same thing on both: no charge during the pause, no charge at the moment of
 * resuming, the next charge on the day it always fell on.
 *
 * **What is not paused.** A payment plan (a fixed number of instalments): its
 * end date is part of what was agreed, and a pause would move it. An agreement
 * in dunning: the money for the last period is still open, and a pause would
 * hide that. A trial that has not been charged yet (`pending`): there is no
 * running rhythm to pause.
 *
 * **The provider first, the row second.** Same rule as `Subscriptions::cancel()`:
 * nothing is written unless the provider confirmed.
 */
class SubscriptionPauses
{
    public function __construct(
        protected Subscriptions $subscriptions,
        protected EntitlementsBridge $bridge,
        protected Catalogue $catalogue,
    ) {}

    /** Whether this agreement can be paused at all, by anyone. */
    public function canPause(Subscription $subscription): bool
    {
        return $subscription->status === Subscription::STATUS_ACTIVE
            && ! $subscription->isPlan()
            && $subscription->dunning_started_at === null
            && ! Payment::isPlaceholderProviderId($subscription->provider_id)
            && $this->subscriptions->gatewayFor($subscription) !== null;
    }

    /**
     * Whether the buyer may pause it from the portal.
     *
     * The product decides where it says so (`pausable` in its catalogue entry,
     * which `statamic-offers` fills from the offer); otherwise the site's
     * default, `portal.allow_pause`, which is off.
     */
    public function portalMayPause(Subscription $subscription): bool
    {
        return $this->portalAllows($subscription) && $this->canPause($subscription);
    }

    /** Whether pausing is the buyer's to do for this product at all, paused or not. */
    public function portalAllows(Subscription $subscription): bool
    {
        $entry = $this->catalogue->find($subscription->product) ?? [];

        return is_bool($entry['pausable'] ?? null)
            ? $entry['pausable']
            : (bool) config('statamic-payments.portal.allow_pause', false);
    }

    /**
     * Pause. True when the provider confirmed and the row says so.
     *
     * @param  Carbon|null  $resumesOn  a day in the future to resume on by
     *                                  itself, or null: until somebody resumes
     * @param  string  $by  `cp` or `portal`, carried on the event
     */
    public function pause(Subscription $subscription, ?Carbon $resumesOn = null, string $by = 'cp'): bool
    {
        if (! $this->canPause($subscription)) {
            return false;
        }

        if ($resumesOn !== null && $resumesOn->copy()->startOfDay()->lte(Carbon::today())) {
            return false;
        }

        $resumesOn = $resumesOn?->copy()->startOfDay();
        $gateway = $this->subscriptions->gatewayFor($subscription);

        if ($gateway === null) {
            return false;
        }

        $nextBefore = $subscription->next_payment_at;
        $native = $gateway instanceof PausesSubscriptions;

        try {
            $remote = $native
                ? $gateway->pauseSubscription($subscription->customer_reference, $subscription->provider_id, $resumesOn)
                : $gateway->cancelSubscription($subscription->customer_reference, $subscription->provider_id);
        } catch (Throwable $e) {
            Log::error('statamic-payments: the provider would not pause this agreement; the row is unchanged.', [
                'subscription_id' => $subscription->getKey(),
                'exception' => $e->getMessage(),
            ]);

            return false;
        }

        $confirmed = $native ? $remote->status === Subscription::STATUS_PAUSED : ! $remote->isLive();

        if (! $confirmed) {
            Log::error('statamic-payments: the provider still charges this agreement after a pause.', [
                'subscription_id' => $subscription->getKey(),
                'status' => $remote->status,
            ]);

            return false;
        }

        $meta = $subscription->meta ?? [];
        $meta['pause'] = [
            'mode' => $native ? 'native' : 'recreate',
            'provider_id' => $subscription->provider_id,
            'next_payment_at' => $nextBefore?->toIso8601String(),
            'by' => $by,
            'at' => Carbon::now()->toIso8601String(),
        ];

        $subscription->forceFill([
            'status' => Subscription::STATUS_PAUSED,
            'paused_at' => Carbon::now(),
            'resumes_at' => $resumesOn,
            'next_payment_at' => null,
            'meta' => $meta,
        ])->save();

        $subscription = $subscription->fresh() ?? $subscription;

        $this->bridge->pauseFor(
            $subscription,
            $this->accessMode(),
            $nextBefore,
            $resumesOn,
        );

        $this->announce(fn () => SubscriptionPaused::dispatch($subscription, $resumesOn, $by), $subscription);

        return true;
    }

    /**
     * Take it up again. True when the provider charges it again.
     *
     * The next charge falls on the old billing day, the first one that is at
     * least a day away. Nothing is charged at the moment of resuming.
     */
    public function resume(Subscription $subscription, string $by = 'cp'): bool
    {
        if (! $subscription->isPaused()) {
            return false;
        }

        $gateway = $this->subscriptions->gatewayFor($subscription);

        if ($gateway === null) {
            return false;
        }

        $pause = is_array($subscription->meta['pause'] ?? null) ? $subscription->meta['pause'] : [];
        $next = $this->nextChargeAfterPause($subscription, $pause);

        try {
            if (($pause['mode'] ?? null) === 'native' && $gateway instanceof PausesSubscriptions) {
                $remote = $gateway->resumeSubscription($subscription->customer_reference, $subscription->provider_id);
            } else {
                $remote = $gateway->createSubscription(
                    $subscription->customer_reference,
                    $this->subscriptions->agreementPayload($subscription, $next, [
                        'resumed_subscription_id' => $subscription->getKey(),
                    ]),
                );
            }
        } catch (Throwable $e) {
            Log::error('statamic-payments: the provider would not resume this agreement; it stays paused.', [
                'subscription_id' => $subscription->getKey(),
                'exception' => $e->getMessage(),
            ]);

            return false;
        }

        if (! $remote->isLive()) {
            Log::error('statamic-payments: the provider did not report the agreement running after a resume; it stays paused.', [
                'subscription_id' => $subscription->getKey(),
                'status' => $remote->status,
            ]);

            return false;
        }

        $meta = $subscription->meta ?? [];
        unset($meta['pause']);
        $meta['pauses'] = array_values(array_merge((array) ($meta['pauses'] ?? []), [[
            'paused_at' => $subscription->paused_at?->toIso8601String(),
            'resumed_at' => Carbon::now()->toIso8601String(),
            'mode' => $pause['mode'] ?? null,
            'by' => $by,
        ]]));

        $subscription->forceFill([
            'provider_id' => $remote->providerId !== '' ? $remote->providerId : $subscription->provider_id,
            'status' => $remote->status,
            'paused_at' => null,
            'resumes_at' => null,
            // A running agreement has no end date, whatever wrote one before.
            'ended_at' => null,
            'next_payment_at' => $remote->nextPaymentAt ? Carbon::parse($remote->nextPaymentAt) : $next,
            'meta' => $meta,
        ])->save();

        $subscription = $subscription->fresh() ?? $subscription;

        $this->bridge->resumeFor($subscription);

        $this->announce(fn () => SubscriptionResumed::dispatch($subscription, $by), $subscription);

        return true;
    }

    /**
     * Resume what was paused until a day that has come. For the scheduler.
     *
     * @return array{resumed: int, failed: int}
     */
    public function resumeDue(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $resumed = 0;
        $failed = 0;

        Subscription::query()
            ->where('status', Subscription::STATUS_PAUSED)
            ->whereNotNull('resumes_at')
            ->where('resumes_at', '<=', $now)
            ->orderBy('id')
            ->each(function (Subscription $subscription) use (&$resumed, &$failed) {
                $this->resume($subscription, 'schedule') ? $resumed++ : $failed++;
            });

        return ['resumed' => $resumed, 'failed' => $failed];
    }

    /**
     * The first charge after a pause: the old billing day, at least tomorrow.
     *
     * @param  array<string, mixed>  $pause
     */
    public function nextChargeAfterPause(Subscription $subscription, array $pause = []): Carbon
    {
        $interval = trim((string) $subscription->interval) ?: '1 month';
        $before = is_string($pause['next_payment_at'] ?? null) ? Carbon::parse($pause['next_payment_at']) : null;
        $next = $before ?? $subscription->paidThroughAt() ?? Carbon::now()->addDay();
        $earliest = Carbon::tomorrow();

        // Bounded: an unreadable interval falls back to a month in
        // `addInterval()`, so this ends; the bound is for a date years back.
        for ($i = 0; $next->lt($earliest) && $i < 1000; $i++) {
            $next = Subscription::addInterval($next, $interval);
        }

        return $next;
    }

    protected function accessMode(): string
    {
        $mode = (string) config('statamic-payments.pause.access', 'period_end');

        return in_array($mode, ['period_end', 'immediate', 'keep'], true) ? $mode : 'period_end';
    }

    /** A listener that throws must not undo what the provider already did. */
    protected function announce(callable $dispatch, Subscription $subscription): void
    {
        try {
            $dispatch();
        } catch (Throwable $e) {
            Log::error('statamic-payments: a listener threw on a pause or resume that happened anyway.', [
                'subscription_id' => $subscription->getKey(),
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
