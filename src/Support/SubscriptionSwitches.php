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
     * In the Control Panel: every recurring product with the same rhythm. In the
     * portal: only what the current product lists under `switch_to`, and only
     * where the site allows switching there at all (`portal.allow_switch`).
     *
     * @return array<string, string>
     */
    public function targetsFor(Subscription $subscription, bool $portal = false): array
    {
        if (! $this->canSwitch($subscription)) {
            return [];
        }

        $candidates = array_keys($this->catalogue->all());

        if ($portal) {
            if (! config('statamic-payments.portal.allow_switch', false)) {
                return [];
            }

            $entry = $this->catalogue->find($subscription->product) ?? [];
            $candidates = array_values(array_filter((array) ($entry['switch_to'] ?? []), 'is_string'));
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
        $preview = $this->preview($subscription, $to);

        if ($preview === null) {
            return false;
        }

        $gateway = $this->subscriptions->gatewayFor($subscription);

        if ($gateway === null) {
            return false;
        }

        $entry = $this->catalogue->find($to) ?? [];
        $proration = null;

        if ($preview['proration_cent'] > 0) {
            $proration = $this->chargeDifference($subscription, $entry, $to, $preview['proration_cent']);

            if ($proration === null) {
                return false;
            }
        }

        $snapshot = $subscription->replicate();
        $snapshot->setAttribute('id', $subscription->getKey());

        $subscription->forceFill([
            'product' => $to,
            'amount_cent' => $preview['to_amount_cent'],
        ]);

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
                    ]),
                );
            }
        } catch (Throwable $e) {
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

            return false;
        }

        $meta = $snapshot->meta ?? [];
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
    protected function chargeDifference(Subscription $subscription, array $entry, string $to, int $cent): ?Payment
    {
        $gateway = $this->subscriptions->gatewayFor($subscription);

        if ($gateway === null) {
            return null;
        }

        $name = (string) ($entry['name'] ?? $to);
        $label = __('statamic-payments::subscriptions.switch_line', ['name' => $name]);

        $payment = DB::transaction(function () use ($subscription, $to, $cent, $label): Payment {
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
            ]);
        } catch (Throwable $e) {
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
