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
 * **Claimed before the provider is asked.** A double click, two tabs, the
 * scheduler next to a portal visit: two requests about one row. The first
 * moves the status (`active` → `pausing`, `paused` → `resuming`) with a
 * conditional UPDATE, the second finds nothing to move and stops. Without the
 * claim two resumes on Mollie start two agreements and charge twice a month. A
 * provider that refuses gets the old status back, so the next try can claim.
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
     * The product decides where it says so (`pausable` in its catalogue entry;
     * an offer has no value of its own and inherits the one of the product it
     * sells, through the `statamic-offers` resolver, like `switch_to`);
     * otherwise the site's default, `portal.allow_pause`, which is off.
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
     *                                  itself, or null: until somebody resumes.
     *                                  A day in the shop's time zone.
     * @param  string  $by  `cp` or `portal`, carried on the event
     */
    public function pause(Subscription $subscription, ?Carbon $resumesOn = null, string $by = 'cp'): bool
    {
        if (! $this->canPause($subscription)) {
            return false;
        }

        if ($resumesOn !== null) {
            // The day the operator or buyer picked, in the shop's zone, kept in
            // the application's zone like every other column.
            $resumesOn = Carbon::parse($resumesOn->toDateString(), LocalTime::zone())
                ->startOfDay()
                ->setTimezone((string) config('app.timezone', 'UTC'));

            if ($resumesOn->lte(LocalTime::today())) {
                return false;
            }
        }

        $gateway = $this->subscriptions->gatewayFor($subscription);

        if ($gateway === null || ! $this->claim($subscription, Subscription::STATUS_ACTIVE, Subscription::STATUS_PAUSING)) {
            return false;
        }

        // The date goes onto the row with the claim, so a sweep that finds the
        // claim left behind still knows when the pause was meant to end.
        Subscription::query()->whereKey($subscription->getKey())->update(['resumes_at' => $resumesOn]);

        $nextBefore = $subscription->next_payment_at;
        $native = $gateway instanceof PausesSubscriptions;

        try {
            $remote = $native
                ? $gateway->pauseSubscription($subscription->customer_reference, $subscription->provider_id, $resumesOn)
                : $gateway->cancelSubscription($subscription->customer_reference, $subscription->provider_id);
        } catch (Throwable $e) {
            // Same rule as a resume: a lost answer leaves the claim for the
            // sweep, which asks the provider what happened.
            if (Transport::isTransient($e)) {
                Log::warning('statamic-payments: the provider did not answer a pause; the row stays claimed until the sweep has asked it.', [
                    'subscription_id' => $subscription->getKey(),
                    'exception' => $e->getMessage(),
                ]);

                return false;
            }

            Log::error('statamic-payments: the provider would not pause this agreement; the row is unchanged.', [
                'subscription_id' => $subscription->getKey(),
                'exception' => $e->getMessage(),
            ]);

            $this->release($subscription, Subscription::STATUS_PAUSING, Subscription::STATUS_ACTIVE, ['resumes_at' => null]);

            return false;
        }

        $confirmed = $native ? $remote->status === Subscription::STATUS_PAUSED : ! $remote->isLive();

        if (! $confirmed) {
            Log::error('statamic-payments: the provider still charges this agreement after a pause.', [
                'subscription_id' => $subscription->getKey(),
                'status' => $remote->status,
            ]);

            $this->release($subscription, Subscription::STATUS_PAUSING, Subscription::STATUS_ACTIVE, ['resumes_at' => null]);

            return false;
        }

        $this->completePause($subscription, $native, $nextBefore, $resumesOn, $by);

        return true;
    }

    /**
     * Write down a pause the provider has confirmed, and say so.
     *
     * Public for `SubscriptionClaims`, which finishes a pause whose process
     * died after the provider had already paused.
     */
    public function completePause(Subscription $subscription, bool $native, ?Carbon $nextBefore, ?Carbon $resumesOn, string $by): void
    {
        $subscription = $subscription->fresh() ?? $subscription;
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

        $this->subscriptions->cancelIfRequested($subscription);
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

        if ($gateway === null || ! $this->claim($subscription, Subscription::STATUS_PAUSED, Subscription::STATUS_RESUMING)) {
            return false;
        }

        // Read again after the claim: a debit that settled during the pause
        // moved `meta.pause.next_payment_at` on (Subscriptions::recordCycle()).
        $subscription = $subscription->fresh() ?? $subscription;
        $pause = is_array($subscription->meta['pause'] ?? null) ? $subscription->meta['pause'] : [];
        $next = $this->nextChargeAfterPause($subscription, $pause);
        $recreated = false;

        try {
            if (($pause['mode'] ?? null) === 'native' && $gateway instanceof PausesSubscriptions) {
                $remote = $gateway->resumeSubscription($subscription->customer_reference, $subscription->provider_id);
            } else {
                $recreated = true;

                // An earlier try whose answer was lost may have started one
                // already. Taken over, not started a second time: two running
                // agreements charge the buyer twice a month.
                $remote = app(SubscriptionClaims::class)->orphansOf($subscription, $gateway)[0] ?? null;

                $remote ??= $gateway->createSubscription(
                    $subscription->customer_reference,
                    $this->subscriptions->agreementPayload($subscription, $next, [
                        'resumed_subscription_id' => $subscription->getKey(),
                    ]) + [
                        // One request, should it reach the provider twice. The
                        // attempt counts up after a refusal: a provider replays
                        // the answer it gave to a key, and a card replaced the
                        // same day deserves a real second try.
                        'idempotencyKey' => 'statamic-payments-resume-'.$subscription->getKey().'-'
                            .($subscription->paused_at?->getTimestamp() ?? 0).'-'.((int) ($pause['attempt'] ?? 0)),
                    ],
                );
            }
        } catch (Throwable $e) {
            // No answer is not "no". The provider may have started the
            // agreement and lost only the reply: the row stays in its claim,
            // the key stays the same, and `payments:resume-paused` asks the
            // provider (SubscriptionClaims) instead of a second request.
            if (Transport::isTransient($e)) {
                Log::warning('statamic-payments: the provider did not answer a resume; the row stays claimed until the sweep has asked it.', [
                    'subscription_id' => $subscription->getKey(),
                    'exception' => $e->getMessage(),
                ]);

                return false;
            }

            Log::error('statamic-payments: the provider would not resume this agreement; it stays paused.', [
                'subscription_id' => $subscription->getKey(),
                'exception' => $e->getMessage(),
            ]);

            $this->refused($subscription);

            return false;
        }

        if (! $remote->isLive()) {
            Log::error('statamic-payments: the provider did not report the agreement running after a resume; it stays paused.', [
                'subscription_id' => $subscription->getKey(),
                'status' => $remote->status,
            ]);

            $this->refused($subscription);

            return false;
        }

        if ($recreated && $remote->providerId !== '' && $remote->providerId !== $subscription->provider_id) {
            $subscription->rememberProviderId((string) $subscription->provider_id);
        }

        $this->markResumed($subscription, $remote, $next, $by);

        return true;
    }

    /**
     * The provider ended the pause by itself: Stripe does on `resumes_at`.
     *
     * Found when its next charge arrives or on a refresh. Nothing is asked of
     * the provider; the row follows it. Claimed like a resume, so a refresh and
     * a webhook arriving together do it once.
     */
    public function adoptProviderResume(Subscription $subscription, RemoteSubscription $remote, string $by = 'provider'): bool
    {
        if (! $subscription->isPaused() || ! $remote->isLive()) {
            return false;
        }

        if (! $this->claim($subscription, Subscription::STATUS_PAUSED, Subscription::STATUS_RESUMING)) {
            return false;
        }

        $subscription = $subscription->fresh() ?? $subscription;
        $pause = is_array($subscription->meta['pause'] ?? null) ? $subscription->meta['pause'] : [];

        // An agreement a dead resume started on Mollie: the row moves to it,
        // and the old id stays findable for late debits.
        if ($remote->providerId !== '' && $remote->providerId !== $subscription->provider_id) {
            $subscription->rememberProviderId((string) $subscription->provider_id);
        }

        $this->markResumed($subscription, $remote, $this->nextChargeAfterPause($subscription, $pause), $by);

        return true;
    }

    /** Write down that it runs again, and say so. */
    protected function markResumed(Subscription $subscription, RemoteSubscription $remote, Carbon $next, string $by): void
    {
        // Read right before the save, not from the object that waited for the
        // provider: a § 312k cancellation noted meanwhile (`cancel_requested`)
        // was written over and lost (Gauntlet 23.09.2026).
        // The ids this resume retired live only on the object so far.
        $retired = (array) ($subscription->meta['previous_provider_ids'] ?? []);
        $subscription = $subscription->fresh() ?? $subscription;
        $pause = is_array($subscription->meta['pause'] ?? null) ? $subscription->meta['pause'] : [];
        $meta = $subscription->meta ?? [];
        unset($meta['pause']);

        if ($retired !== []) {
            $meta['previous_provider_ids'] = array_values(array_unique(array_merge((array) ($meta['previous_provider_ids'] ?? []), $retired)));
        }
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

        // A § 312k cancellation that arrived while this ran is carried out now.
        $this->subscriptions->cancelIfRequested($subscription);
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
                Brands::runFor($subscription->brand_id, fn () => $this->resume($subscription, 'schedule')) ? $resumed++ : $failed++;
            });

        return ['resumed' => $resumed, 'failed' => $failed];
    }

    /**
     * The first charge after a pause: the old billing day, at least tomorrow.
     *
     * Counted from the original date, not step by step: from the 31st a
     * month is the 28th of February, and from there every month would stay on
     * the 28th. `Subscription::addIntervals()` brings the 31st back.
     *
     * @param  array<string, mixed>  $pause
     */
    public function nextChargeAfterPause(Subscription $subscription, array $pause = []): Carbon
    {
        $interval = trim((string) $subscription->interval) ?: '1 month';
        // The anchor is the charge date the pause began with; periods paid
        // during the pause (a late direct debit) move it on, counted in
        // `paid_during`, not by overwriting the day.
        $anchor = $pause['anchor'] ?? $pause['next_payment_at'] ?? null;
        $start = is_string($anchor) ? Carbon::parse($anchor) : ($subscription->paidThroughAt() ?? Carbon::now()->addDay());
        $offset = max(0, (int) ($pause['paid_during'] ?? 0));
        $earliest = LocalTime::today()->addDay();
        $next = Subscription::addIntervals($start, $interval, $offset);

        // Bounded: the bound is for a date years back.
        for ($i = $offset + 1; $next->copy()->setTimezone(LocalTime::zone())->startOfDay()->lt($earliest) && $i <= $offset + 1000; $i++) {
            $next = Subscription::addIntervals($start, $interval, $i);
        }

        return $next;
    }

    /** The conditional UPDATE the whole class hangs on. */
    protected function claim(Subscription $subscription, string $from, string $to): bool
    {
        return Subscription::query()
            ->whereKey($subscription->getKey())
            ->where('status', $from)
            ->update(['status' => $to, 'updated_at' => Carbon::now()]) > 0;
    }

    /** A refused resume: paused again, and the next try gets a new key. */
    protected function refused(Subscription $subscription): void
    {
        $meta = ($subscription->fresh() ?? $subscription)->meta ?? [];
        $meta['pause']['attempt'] = ((int) ($meta['pause']['attempt'] ?? 0)) + 1;

        Subscription::query()
            ->whereKey($subscription->getKey())
            ->where('status', Subscription::STATUS_RESUMING)
            ->update(['status' => Subscription::STATUS_PAUSED, 'meta' => json_encode($meta), 'updated_at' => Carbon::now()]);
    }

    /**
     * Give a claim back after the provider refused. Public for the sweep.
     *
     * @param  array<string, mixed>  $also
     */
    public function release(Subscription $subscription, string $from, string $to, array $also = []): bool
    {
        return Subscription::query()
            ->whereKey($subscription->getKey())
            ->where('status', $from)
            ->update(['status' => $to, 'updated_at' => Carbon::now()] + $also) > 0;
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
