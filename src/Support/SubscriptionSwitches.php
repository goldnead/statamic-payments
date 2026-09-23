<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Contracts\UpdatesSubscriptions;
use Goldnead\StatamicPayments\Events\SubscriptionChanged;
use Goldnead\StatamicPayments\Integrations\EntitlementsBridge;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Moving a running agreement to another product: upgrade or downgrade.
 *
 * **The rule, the same on Stripe and Mollie.**
 *
 * - An **upgrade** (the new amount is higher) applies at once. What is left of
 *   the current period is charged as the difference, pro rata to the second:
 *   `(new − old) × (time left / period length)`. That difference is its own
 *   payment through `chargeAgain()`, so it is settled by the webhook, invoiced
 *   and shown like every other charge. From the next charge on, the new amount.
 * - A **downgrade** (lower or equal) applies from the next charge. Nothing is
 *   charged now and nothing is refunded: the current period was paid at the old
 *   amount and keeps its product until it ends.
 *
 * **Neither provider prorates.** Stripe is told `proration_behavior=none`;
 * Mollie never prorates. Letting one of them do it would make the two behave
 * differently and put a second, unannounced charge on Stripe customers.
 *
 * **Only between rhythms that match.** A monthly plan to a yearly one resets the
 * billing anchor on Stripe and needs a refund rule nobody has decided yet. Such
 * a switch is refused; the way there is cancel and buy.
 */
class SubscriptionSwitches
{
    public function __construct(
        protected Subscriptions $subscriptions,
        protected Catalogue $catalogue,
        protected EntitlementsBridge $bridge,
    ) {}

    /**
     * Where this agreement may go, as handle => name.
     *
     * The product's own list (`switch_to`) where it has one: that is the
     * operator saying where it may go, in the Control Panel and in the portal.
     * Without a list, the Control Panel offers the recurring products with the
     * same rhythm that belong to the agreement's own brand (a catalogue entry
     * naming another `brand_id` is another tenant's product). The portal offers
     * nothing without a list, and nothing unless `portal.allow_switch` is on.
     *
     * @return array<string, string>
     */
    public function targetsFor(Subscription $subscription, bool $portal = false): array
    {
        if (! $this->canSwitch($subscription)) {
            return [];
        }

        if ($portal && ! config('statamic-payments.portal.allow_switch', false)) {
            return [];
        }

        $own = $this->catalogue->find($subscription->product) ?? [];
        $list = array_key_exists('switch_to', $own)
            ? array_values(array_filter((array) $own['switch_to'], 'is_string'))
            : null;

        if ($list !== null) {
            $candidates = $list;
        } elseif ($portal) {
            return [];
        } else {
            $brand = (int) $subscription->brand_id;
            $candidates = array_keys(array_filter(
                $this->catalogue->all(),
                fn (array $entry) => ! is_numeric($entry['brand_id'] ?? null) || (int) $entry['brand_id'] === $brand,
            ));

            // Offers are not in `all()` (the catalogue lists products, and the
            // offer form picks from it). A subscription bought through an offer
            // had no target at all (adg staging, 1.25.0-rc.1).
            $candidates = array_merge($candidates, $this->offerHandles($brand));
        }

        $targets = [];

        foreach ($candidates as $handle) {
            if ($this->fits($subscription, (string) $handle)) {
                $entry = $this->catalogue->find((string) $handle) ?? [];
                $targets[(string) $handle] = (string) ($entry['name'] ?? $handle);
            }
        }

        return $targets;
    }

    /**
     * The active offers of a brand, as catalogue handles, each pricing option
     * with a rhythm on its own. Read from `statamic-offers` where installed;
     * `fits()` and the offer's own resolver decide what is really a target
     * (rhythm, currency, sellable).
     *
     * @return list<string>
     */
    protected function offerHandles(int $brand): array
    {
        $model = '\Goldnead\StatamicOffers\Models\Offer';

        if (! class_exists($model)) {
            return [];
        }

        try {
            $prefix = $model::prefix();
            $handles = [];

            foreach ($model::query()->where('active', true)->where('brand_id', $brand)->orderBy('id')->get() as $offer) {
                $handles[] = $prefix.$offer->handle;

                foreach ($offer->pricingOptions() as $option) {
                    if (($option['interval'] ?? null) !== null) {
                        $handles[] = $prefix.$offer->handle.':'.$option['key'];
                    }
                }
            }

            return $handles;
        } catch (Throwable $e) {
            Log::warning('statamic-payments: the offers could not be listed as switch targets.', ['exception' => $e->getMessage()]);

            return [];
        }
    }

    /** Whether this agreement can move at all. */
    public function canSwitch(Subscription $subscription): bool
    {
        return $subscription->status === Subscription::STATUS_ACTIVE
            && ! $subscription->isPlan()
            && $subscription->dunning_started_at === null
            && $subscription->next_payment_at !== null
            && ! Payment::isPlaceholderProviderId($subscription->provider_id)
            && $this->subscriptions->gatewayFor($subscription) !== null;
    }

    /** Whether `$to` is a product this agreement could move to. */
    public function fits(Subscription $subscription, string $to): bool
    {
        if ($to === '' || $to === $subscription->product) {
            return false;
        }

        $plan = $this->subscriptions->planFor($to);
        $entry = $this->catalogue->find($to);

        return $plan !== null
            && $plan['times'] === null
            && is_array($entry)
            && strcasecmp($plan['interval'], trim((string) $subscription->interval)) === 0
            && strtoupper((string) ($entry['currency'] ?? '')) === strtoupper((string) $subscription->currency);
    }

    /**
     * What a switch would do, without doing it. For the confirmation screens.
     *
     * @return array{from: string, to: string, from_amount_cent: int, to_amount_cent: int, immediate: bool, proration_cent: int, effective_at: Carbon|null}|null
     */
    public function preview(Subscription $subscription, string $to, ?Carbon $now = null): ?array
    {
        if (! $this->canSwitch($subscription) || ! $this->fits($subscription, $to)) {
            return null;
        }

        $entry = $this->catalogue->find($to) ?? [];
        $new = (int) ($entry['amount_cent'] ?? 0);
        $old = (int) $subscription->amount_cent;
        $immediate = $new > $old;

        $proration = $immediate
            ? (int) round(($new - $old) * $subscription->remainingFraction($now))
            : 0;

        // Below the floor nothing is charged. A card fee on 12 cents costs more
        // than it brings, and some methods refuse amounts that small.
        if ($proration < max(1, (int) config('statamic-payments.switch.min_proration_cent', 50))) {
            $proration = 0;
        }

        return [
            'from' => $subscription->product,
            'to' => $to,
            'from_amount_cent' => $old,
            'to_amount_cent' => $new,
            'immediate' => $immediate,
            'proration_cent' => $proration,
            'effective_at' => $immediate ? ($now ?? Carbon::now()) : $subscription->next_payment_at,
        ];
    }

    /**
     * Switch. True when the provider took the new amount.
     *
     * Order, for an upgrade: charge the difference first, then change the
     * agreement. A refused charge then leaves everything as it was. The other
     * order could leave a higher amount agreed and the difference never paid.
     */
    public function switch(Subscription $subscription, string $to, string $by = 'cp'): bool
    {
        // Every "no" before the provider is asked says why: a switch that
        // returned false without a line was the whole staging finding.
        $refuse = function (string $reason) use ($subscription, $to): bool {
            Log::warning('statamic-payments: a subscription switch was not made.', [
                'subscription_id' => $subscription->getKey(),
                'from' => $subscription->product,
                'to' => $to,
                'reason' => $reason,
            ]);

            return false;
        };

        if (! $this->canSwitch($subscription)) {
            return $refuse('this subscription cannot switch (status, plan, dunning, no next charge or no provider)');
        }

        $preview = $this->preview($subscription, $to);

        if ($preview === null) {
            return $refuse('the target does not fit (unknown, same product, not open-ended, other rhythm or currency)');
        }

        if (! array_key_exists($to, $this->targetsFor($subscription))) {
            return $refuse('not a target');
        }

        $gateway = $this->subscriptions->gatewayFor($subscription);

        if ($gateway === null) {
            return $refuse('no provider');
        }

        // Claimed first: the row moves to the new product only where it still
        // holds the old one. A second request about the same row (a double
        // click, two tabs) finds nothing to move and charges nothing.
        $claimed = Subscription::query()
            ->whereKey($subscription->getKey())
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where('product', $preview['from'])
            ->where('amount_cent', $preview['from_amount_cent'])
            ->update([
                // The status too: a pause or cancellation arriving while this
                // runs finds `switching` and waits, instead of ending the
                // agreement this is about to change (Gauntlet 23.09.2026).
                'status' => Subscription::STATUS_SWITCHING,
                'product' => $to,
                'amount_cent' => $preview['to_amount_cent'],
                'updated_at' => Carbon::now(),
            ]);

        if ($claimed === 0) {
            return $refuse('the row changed meanwhile (another switch, pause or cancellation)');
        }

        // Where the row came from, on the row: should this process die, a
        // person releasing the claim in the Control Panel can put it back.
        $claimedRow = $subscription->fresh() ?? $subscription;
        $claimedRow->forceFill(['meta' => array_merge($claimedRow->meta ?? [], ['switching' => [
            'from' => $preview['from'],
            'from_amount_cent' => $preview['from_amount_cent'],
            'to' => $to,
            'to_amount_cent' => $preview['to_amount_cent'],
        ]])])->save();

        $giveBack = fn () => Subscription::query()
            ->whereKey($subscription->getKey())
            ->where('status', Subscription::STATUS_SWITCHING)
            ->update([
                'status' => Subscription::STATUS_ACTIVE,
                'product' => $preview['from'],
                'amount_cent' => $preview['from_amount_cent'],
                'updated_at' => Carbon::now(),
            ]);

        $entry = $this->catalogue->find($to) ?? [];
        $proration = null;

        if ($preview['proration_cent'] > 0) {
            // One switch, one difference: the same switch in the same period
            // (after a lost answer, a release to the old product) uses the
            // difference already charged instead of charging it again.
            $key = 'switch-'.$subscription->getKey().'-'.$preview['from'].'-'.$to.'-'
                .($subscription->next_payment_at?->getTimestamp() ?? 0);
            $proration = $this->chargeDifference($subscription, $entry, $to, $preview['proration_cent'], $key);

            if ($proration === null) {
                $giveBack();

                return false;
            }

            $this->noteOnSwitching($subscription, ['proration_payment_id' => $proration->getKey()]);
        }

        $snapshot = $subscription->replicate();
        $snapshot->setAttribute('id', $subscription->getKey());

        // What the claim wrote, known to the object too, so the save at the end
        // writes the status back and nothing it did not change.
        $subscription->forceFill([
            'status' => Subscription::STATUS_SWITCHING,
            'product' => $to,
            'amount_cent' => $preview['to_amount_cent'],
        ]);
        $subscription->syncOriginalAttributes(['status', 'product', 'amount_cent']);

        try {
            if ($gateway instanceof UpdatesSubscriptions) {
                $remote = $gateway->updateSubscription($subscription->customer_reference, $subscription->provider_id, [
                    'amount' => ['currency' => $subscription->currency, 'value' => $subscription->amount()],
                    'description' => (string) ($entry['name'] ?? $to),
                ]);
            } else {
                // No change in place: end and start again, on the same day.
                $old = $gateway->cancelSubscription($snapshot->customer_reference, $snapshot->provider_id);

                if ($old->isLive()) {
                    throw new \RuntimeException('the provider kept the old agreement running');
                }

                $remote = $gateway->createSubscription(
                    $subscription->customer_reference,
                    $this->subscriptions->agreementPayload($subscription, $snapshot->next_payment_at ?? Carbon::tomorrow(), [
                        'switched_subscription_id' => $subscription->getKey(),
                    ]) + [
                        'idempotencyKey' => 'statamic-payments-switch-'.$subscription->getKey().'-'.$to.'-'.Carbon::now()->getTimestamp(),
                    ],
                );

                if ($remote->providerId !== '' && $remote->providerId !== $snapshot->provider_id) {
                    $subscription->rememberProviderId((string) $snapshot->provider_id);
                }
            }
        } catch (Throwable $e) {
            // No answer is not "no": the provider may charge the new amount
            // already. Putting the row back on the old product would let the
            // next click charge the difference again. The claim stays; the
            // sweep adopts what it can, "Release switch" settles the rest.
            if (Transport::isTransient($e)) {
                Log::warning('statamic-payments: the provider did not answer a switch; the row stays claimed until it is settled.', [
                    'subscription_id' => $subscription->getKey(),
                    'to' => $to,
                    'proration_payment_id' => $proration?->getKey(),
                    'exception' => $e->getMessage(),
                ]);

                return false;
            }

            Log::error('statamic-payments: the provider would not take the new amount; the agreement is unchanged.', [
                'subscription_id' => $subscription->getKey(),
                'to' => $to,
                'proration_payment_id' => $proration?->getKey(),
                'exception' => $e->getMessage(),
            ]);

            // The difference is already charged when an upgrade gets here. Said
            // loudly and kept on the row: somebody has to refund or re-run it.
            if ($proration !== null) {
                $proration->forceFill(['meta' => array_merge($proration->meta ?? [], [
                    'subscription_change_failed' => mb_substr($e->getMessage(), 0, 300),
                ])])->save();
            }

            $giveBack();

            return false;
        }

        // Read again: whatever was written to the row while the provider was
        // asked (a counted charge, a note) stays. The ids this switch retired
        // are added to what is there, not written over it.
        $retired = (array) ($subscription->meta['previous_provider_ids'] ?? []);
        $meta = ($subscription->fresh() ?? $subscription)->meta ?? [];

        unset($meta['switching']);

        if ($retired !== []) {
            $meta['previous_provider_ids'] = array_values(array_unique(array_merge((array) ($meta['previous_provider_ids'] ?? []), $retired)));
        }

        // A coupon applied to the product that was left (statamic-offers O6).
        // The provider now charges the full new price; the row says the same,
        // so `chargedCent()` and `recordCycle()` do not keep taking it off.
        if (is_array($meta['coupon'] ?? null) && ! isset($meta['coupon']['ended'])) {
            $meta['coupon']['current_discount_cent'] = 0;
            $meta['coupon']['ended'] = 'switch';
        }

        $meta['switches'] = array_values(array_merge((array) ($meta['switches'] ?? []), [[
            'from' => $preview['from'],
            'to' => $to,
            'from_amount_cent' => $preview['from_amount_cent'],
            'to_amount_cent' => $preview['to_amount_cent'],
            'proration_cent' => $preview['proration_cent'],
            'proration_payment_id' => $proration?->getKey(),
            'immediate' => $preview['immediate'],
            'by' => $by,
            'at' => Carbon::now()->toIso8601String(),
        ]]));

        $subscription->forceFill([
            'status' => $remote->isLive() ? $remote->status : Subscription::STATUS_ACTIVE,
            'provider_id' => $remote->providerId !== '' ? $remote->providerId : $snapshot->provider_id,
            'meta' => $meta,
        ])->save();

        $subscription = $subscription->fresh() ?? $subscription;

        // The old product keeps what was paid for it; an open-ended grant gets
        // the end of the period. An upgrade opens the new product now.
        $this->bridge->closeFor($snapshot);

        if ($preview['immediate']) {
            $this->bridge->switchFor($subscription);
        }

        try {
            SubscriptionChanged::dispatch(
                $subscription,
                $preview['from'],
                $to,
                $preview['from_amount_cent'],
                $preview['to_amount_cent'],
                $preview['proration_cent'],
                $proration?->fresh(),
                $preview['immediate'],
                $by,
            );
        } catch (Throwable $e) {
            Log::error('statamic-payments: a listener threw on a switch that happened anyway.', [
                'subscription_id' => $subscription->getKey(),
                'exception' => $e->getMessage(),
            ]);
        }

        return true;
    }

    /**
     * The difference for the rest of the period, as its own payment.
     *
     * Row first, provider second, as everywhere a charge starts here.
     *
     * @param  array<string, mixed>  $entry
     */
    /**
     * Add to `meta.switching` on the claimed row, fresh and without touching
     * `updated_at` (the clock by which a claim counts as stuck).
     *
     * @param  array<string, mixed>  $add
     */
    protected function noteOnSwitching(Subscription $subscription, array $add): void
    {
        $meta = ($subscription->fresh() ?? $subscription)->meta ?? [];
        $meta['switching'] = array_merge((array) ($meta['switching'] ?? []), $add);

        Subscription::query()->whereKey($subscription->getKey())->toBase()->update(['meta' => json_encode($meta)]);
    }

    protected function chargeDifference(Subscription $subscription, array $entry, string $to, int $cent, string $key): ?Payment
    {
        $gateway = $this->subscriptions->gatewayFor($subscription);

        if ($gateway === null) {
            return null;
        }

        $name = (string) ($entry['name'] ?? $to);
        $label = __('statamic-payments::subscriptions.switch_line', ['name' => $name]);

        // Charged already for this switch and not used by a finished one.
        $consumed = array_map(
            fn ($s) => (int) (is_array($s) ? ($s['proration_payment_id'] ?? 0) : 0),
            (array) (($subscription->fresh() ?? $subscription)->meta['switches'] ?? []),
        );

        $payment = Payment::query()
            ->where('meta->subscription_change->key', $key)
            ->whereNotIn('status', [Payment::STATUS_FAILED, Payment::STATUS_EXPIRED, Payment::STATUS_CANCELED])
            ->whereNotIn('id', $consumed)
            ->latest('id')
            ->first();

        if ($payment !== null && ! Payment::isPlaceholderProviderId($payment->provider_id)) {
            return $payment;
        }

        // A placeholder is a charge whose answer was lost: asked again below
        // with its own key and amount, so the provider answers with the same
        // charge instead of taking the money twice.
        $payment ??= DB::transaction(function () use ($subscription, $to, $cent, $label, $key): Payment {
            $payment = Payment::create([
                'provider' => $subscription->provider,
                'provider_id' => Payment::PLACEHOLDER_PROVIDER_PREFIX.Str::uuid(),
                'brand_id' => $subscription->brand_id,
                'product' => $to,
                'amount_cent' => $cent,
                'currency' => $subscription->currency,
                'status' => Payment::STATUS_INITIATED,
                'email' => $subscription->email,
                'name' => $subscription->name,
                'customer_reference' => $subscription->customer_reference,
                'meta' => [
                    // Read by statamic-insights: a difference charge, not a new
                    // price. Without it the charge counts as revenue at a rate
                    // nobody sells.
                    'proration' => true,
                    'subscription_change' => [
                        'subscription_id' => $subscription->getKey(),
                        'from' => $subscription->product,
                        'to' => $to,
                        'key' => $key,
                    ],
                ],
            ]);

            PaymentItem::create([
                'payment_id' => $payment->getKey(),
                'product' => $to,
                'name' => $label,
                'amount_cent' => $cent,
                'quantity' => 1,
                'kind' => PaymentItem::KIND_PRIMARY,
            ]);

            return $payment;
        });

        try {
            $remote = $gateway->chargeAgain($subscription->customer_reference, [
                'amount' => ['currency' => $payment->currency, 'value' => $payment->amount()],
                'description' => $label,
                'webhookUrl' => config('statamic-payments.webhook_url') === false
                    ? null
                    : (config('statamic-payments.webhook_url') ?: route('statamic-payments.webhook')),
                'metadata' => [
                    'payment_id' => $payment->getKey(),
                    'product' => $to,
                    'email' => $payment->email,
                ],
                'idempotencyKey' => 'statamic-payments-difference-'.$payment->getKey(),
            ]);
        } catch (Throwable $e) {
            // No answer: the charge may have gone through. The row stays a
            // placeholder, and the next try asks again under the same key.
            if (Transport::isTransient($e)) {
                Log::warning('statamic-payments: the provider did not answer the difference for a switch; nothing was switched, the next try asks again.', [
                    'subscription_id' => $subscription->getKey(),
                    'payment_id' => $payment->getKey(),
                    'exception' => $e->getMessage(),
                ]);

                return null;
            }

            Log::warning('statamic-payments: the difference for a switch was refused; nothing was switched.', [
                'subscription_id' => $subscription->getKey(),
                'payment_id' => $payment->getKey(),
                'exception' => $e->getMessage(),
            ]);

            $payment->forceFill(['status' => Payment::STATUS_FAILED])->save();

            return null;
        }

        $payment->forceFill([
            'provider_id' => $remote->providerId,
            'status' => $remote->status,
        ])->save();

        return $payment->fresh() ?? $payment;
    }
}
