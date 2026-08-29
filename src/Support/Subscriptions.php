<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Contracts\SubscriptionGateway;
use Goldnead\StatamicPayments\Events\SubscriptionCancelled;
use Goldnead\StatamicPayments\Events\SubscriptionEnded;
use Goldnead\StatamicPayments\Events\SubscriptionRenewed;
use Goldnead\StatamicPayments\Events\SubscriptionStarted;
use Goldnead\StatamicPayments\Events\SubscriptionStartFailed;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Starting, stopping and following an agreement to be charged again.
 *
 * The awkward truth this class has to live with: **a subscription needs a
 * mandate, and a mandate needs a payment.** No provider will store a card
 * because a page asked nicely. So starting one is two steps, in this order:
 *
 * 1. An ordinary checkout, with the buyer attached to the provider and marked
 *    as a first payment. The buyer sees a normal payment page.
 * 2. When *that* payment is confirmed paid — by the webhook, never by the
 *    browser coming back — the subscription is created against the mandate it
 *    left behind.
 *
 * Doing it the other way round, creating the agreement first and hoping the
 * payment lands, produces subscriptions with no mandate that the provider will
 * refuse forever, silently, on a rhythm.
 *
 * The first payment's **amount is the trade a site has to make**, and this
 * package refuses to make it for them. On Mollie there is no way to store a card
 * without charging something: no SetupIntent, no zero-amount authorisation. So a
 * free trial is one of two things, and the config says which:
 *
 * - a small charge now (`trial_amount_cent`), the card on file, the trial real
 *   for everything after it; or
 * - no charge and no card, which means the buyer has to come back and pay by
 *   hand — honest, and most of them will not.
 *
 * Hiding that behind the word "trial" would be the wrong kind of convenience.
 */
class Subscriptions
{
    public function __construct(
        protected PaymentGateway $gateway,
        protected Catalogue $catalogue,
        protected Checkout $checkout,
    ) {}

    /** Whether this site can run subscriptions at all. */
    public function available(): bool
    {
        return $this->gateway instanceof SubscriptionGateway
            && $this->gateway->supportsSubscriptions();
    }

    /**
     * What a product says about its rhythm, or null if it has none.
     *
     * @return array{interval: string, times: int|null, trial_days: int, trial_amount_cent: int|null}|null
     */
    public function planFor(string $handle): ?array
    {
        $product = $this->catalogue->find($handle);

        if (! $product) {
            return null;
        }

        $interval = $product['interval'] ?? null;

        if (! is_string($interval) || trim($interval) === '') {
            return null;
        }

        $times = $product['times'] ?? null;

        return [
            'interval' => trim($interval),
            // Zero would mean "charge nothing, ever", which is a typo rather
            // than an instruction.
            'times' => is_int($times) && $times > 0 ? $times : null,
            'trial_days' => max(0, (int) ($product['trial_days'] ?? 0)),
            // Null means "the ordinary amount". A site wanting a cheap trial
            // says so with a number, in minor units, and knows it is charging.
            'trial_amount_cent' => isset($product['trial_amount_cent']) && is_int($product['trial_amount_cent'])
                ? max(0, $product['trial_amount_cent'])
                : null,
        ];
    }

    /**
     * Begin one. Hands back the checkout for the first payment.
     *
     * Null when the product is not recurring, the provider cannot do it, or the
     * site has not turned mandate collection on — a subscription without a
     * stored card is not a subscription.
     *
     * @param  array<string, mixed>  $buyer
     * @param  array<string, mixed>|PaymentDetails  $details  Was die aufrufende
     *                                                        Strecke an die erste Zahlung heften will. Siehe
     *                                                        {@see PaymentDetails}.
     *
     * @throws \InvalidArgumentException wenn $details etwas enthält, das dem Paket gehört
     */
    public function start(string $product, array $buyer = [], ?string $returnUrl = null, array|PaymentDetails $details = []): ?CheckoutResult
    {
        $details = PaymentDetails::from($details);

        $plan = $this->planFor($product);

        if (! $plan || ! $this->available()) {
            return null;
        }

        if (! config('statamic-payments.follow_up.collect_mandate', false)) {
            Log::warning('statamic-payments: a subscription was asked for while mandate collection is off; nothing was started.', [
                'product' => $product,
            ]);

            return null;
        }

        // The first payment. An ordinary checkout in every respect except that
        // it carries the intention: what it establishes is the agreement, and
        // the row it leaves behind is what the webhook later turns into one.
        //
        // Die Absicht geht **in** den Checkout hinein und wird nicht danach
        // nachgetragen. Nachgetragen war sie zweimal zu spät: der Anbieter war
        // gerufen, bevor sie in der Datenbank stand, und bei einem Testzeitraum
        // ohne Betrag ist die Zahlung noch innerhalb von `start()` erfüllt —
        // `startFromPayment()` sah dann kein `subscription_intent`, tat nichts,
        // und niemand erfuhr, dass ein bezahltes Abo keines wurde.
        return $this->checkout->start(
            $product,
            $buyer,
            $returnUrl,
            $this->trialDiscount($product, $plan),
            $details->plus([
                'subscription_intent' => [
                    'product' => $product,
                    'interval' => $plan['interval'],
                    'times' => $plan['times'],
                    'trial_days' => $plan['trial_days'],
                ],
            ]),
        );
    }

    /**
     * The difference between the ordinary price and what a trial charges today.
     *
     * Expressed as a `Discount` rather than a second amount, so the payment row
     * still says what the thing costs and what came off it — a receipt for
     * "1 € instead of 19 €" that only records the 1 € loses the reason.
     *
     * **A trial and a coupon cannot both apply today.** `Checkout::start()` takes
     * one `Discount`, and the trial takes it. That is a real limitation rather
     * than an oversight: two reductions on one line need a rule about which
     * comes off first, and inventing that rule quietly is how a receipt ends up
     * saying something nobody can reproduce.
     *
     * @param  array{interval: string, times: int|null, trial_days: int, trial_amount_cent: int|null}  $plan
     */
    protected function trialDiscount(string $product, array $plan): ?Discount
    {
        if ($plan['trial_days'] === 0 || $plan['trial_amount_cent'] === null) {
            return null;
        }

        $full = $this->catalogue->find($product)['amount_cent'] ?? null;

        if (! is_int($full) || $plan['trial_amount_cent'] >= $full) {
            return null;
        }

        return new Discount(
            code: 'trial',
            amountCent: $full - $plan['trial_amount_cent'],
            label: __('statamic-payments::messages.trial_discount', ['days' => $plan['trial_days']]),
        );
    }

    /**
     * Turn a paid first payment into a running agreement.
     *
     * Called from the fulfilment path, so it happens exactly once per payment
     * and only after the provider confirmed the money. A failure here does not
     * throw: the buyer paid, the row says so, and an agreement that could not
     * be created is a thing to fix rather than a reason to unwind a sale and
     * make the provider redeliver.
     */
    public function startFromPayment(Payment $payment): ?Subscription
    {
        $intent = $payment->meta['subscription_intent'] ?? null;

        if (! is_array($intent)) {
            return null;
        }

        // The intention outlives the conditions it was made under: a provider
        // swapped, a config switched off between the checkout and the webhook.
        // Money was taken for a subscription either way, so it is said out loud
        // rather than returned as a quiet null.
        if (! $this->available()) {
            return $this->startFailed($payment, 'this provider cannot run subscriptions');
        }

        if ($payment->subscription_id) {
            return Subscription::find($payment->subscription_id);
        }

        if (! $payment->customer_reference) {
            Log::error('statamic-payments: a first payment for a subscription left no mandate behind; no agreement was created.', [
                'payment_id' => $payment->getKey(),
                'product' => $intent['product'] ?? null,
            ]);

            return null;
        }

        $product = (string) ($intent['product'] ?? $payment->product);
        $plan = $this->planFor($product);

        if (! $plan) {
            return null;
        }

        $catalogue = $this->catalogue->find($product) ?? [];

        // The first cycle is already paid, so the provider's rhythm starts one
        // interval later — or after the trial, when there is one.
        $startsAt = $plan['trial_days'] > 0
            ? Carbon::now()->addDays($plan['trial_days'])
            : $this->afterOneInterval($plan['interval']);

        // A plan of N instalments has already taken one. Asking the provider
        // for N more would charge N+1 in total, which is the kind of arithmetic
        // that ends up in a chargeback.
        $remaining = $plan['times'] === null ? null : max(0, $plan['times'] - 1);

        if ($remaining === 0) {
            // A one-instalment plan is a single payment wearing a costume.
            return null;
        }

        // The row first, committed, *then* the provider. This package says so
        // twice already — `Checkout::start()` and `FollowUp::accept()` both do
        // it — and doing the opposite here would be the one place where the
        // loss repeats: a provider-side agreement with no local row charges
        // somebody every month, forever, and nothing on this site ever hears
        // about it, because a cycle for an unknown agreement is indistinguishable
        // from a stray webhook.
        //
        // So: no transaction around the remote call. A row in `initiated` with
        // no provider id is a thing to notice and repair. A subscription at the
        // provider with no row is not.
        $subscription = Subscription::create([
            // Von der ersten Zahlung geerbt. Sie entstand im Browser des
            // Käufers, wo die Marke gesetzt war; dieses Abo entsteht im
            // Webhook, wo sie es nicht ist.
            'brand_id' => $payment->brand_id,
            'provider' => $this->gateway->provider(),
            // Unique per payment, so a redelivery cannot make a second.
            'provider_id' => 'pending-'.$payment->getKey(),
            'customer_reference' => $payment->customer_reference,
            'product' => $product,
            'amount_cent' => (int) ($catalogue['amount_cent'] ?? $payment->amount_cent),
            'currency' => (string) ($catalogue['currency'] ?? $payment->currency),
            'interval' => $plan['interval'],
            'times' => $remaining,
            'times_charged' => 0,
            'status' => Subscription::STATUS_INITIATED,
            'starts_at' => $startsAt,
            'email' => $payment->email,
            'name' => $payment->name,
        ]);

        /** @var SubscriptionGateway $gateway */
        $gateway = $this->gateway;

        try {
            $remote = $gateway->createSubscription($payment->customer_reference, array_filter([
                'amount' => [
                    'currency' => $subscription->currency,
                    'value' => $subscription->amount(),
                ],
                'interval' => $subscription->interval,
                'times' => $remaining,
                'startDate' => $startsAt->toDateString(),
                'description' => (string) ($catalogue['name'] ?? $product),
                'webhookUrl' => config('statamic-payments.webhook_url') === false
                    ? null
                    : (config('statamic-payments.webhook_url') ?: route('statamic-payments.webhook')),
                'metadata' => [
                    'product' => $product,
                    'first_payment_id' => $payment->getKey(),
                ],
            ], fn ($v) => $v !== null));
        } catch (Throwable $e) {
            $subscription->delete();

            return $this->startFailed($payment, $e->getMessage());
        }

        $subscription->forceFill([
            'provider_id' => $remote->providerId,
            'status' => $remote->status,
            'next_payment_at' => $remote->nextPaymentAt ? Carbon::parse($remote->nextPaymentAt) : $startsAt,
        ])->save();

        // The first payment belongs to the agreement it created, so a report
        // over one subscription shows what was actually paid for it rather than
        // starting at cycle two.
        $payment->forceFill(['subscription_id' => $subscription->getKey()])->save();

        $subscription = $subscription->fresh() ?? $subscription;

        // Outside everything above. A listener that throws must not be able to
        // undo an agreement the provider has already accepted.
        try {
            SubscriptionStarted::dispatch($subscription, $payment->fresh() ?? $payment);
        } catch (Throwable $e) {
            Log::error('statamic-payments: a listener threw on a subscription that was created anyway.', [
                'subscription_id' => $subscription->getKey(),
                'exception' => $e->getMessage(),
            ]);
        }

        return $subscription;
    }

    /**
     * A first payment that was taken and an agreement that was not created.
     *
     * The customer paid. Not saying so anywhere would leave the only trace in a
     * log file nobody reads, and `subscription_intent` sitting in `meta` looking
     * exactly like one that is still to be processed. So the payment is marked,
     * and an event goes out for anybody who wants to be told.
     */
    protected function startFailed(Payment $payment, string $why): null
    {
        $payment->forceFill([
            'meta' => array_merge($payment->meta ?? [], [
                'subscription_start_failed_at' => now()->toIso8601String(),
                'subscription_start_error' => mb_substr($why, 0, 500),
            ]),
        ])->save();

        Log::error('statamic-payments: the first payment was taken and the agreement was not created.', [
            'payment_id' => $payment->getKey(),
            'reason' => $why,
        ]);

        SubscriptionStartFailed::dispatch($payment->fresh() ?? $payment, $why);

        return null;
    }

    /**
     * Note a cycle that the provider charged on its own.
     *
     * Every cycle after the first arrives as a plain payment with the
     * subscription's id on it. The payment fulfils through the ordinary path —
     * same claim, same event, so an entitlement is granted or extended per cycle
     * without this class knowing anything about entitlements.
     */
    public function recordCycle(Payment $payment, string $providerSubscriptionId): ?Subscription
    {
        $subscription = Subscription::query()
            ->where('provider', $this->gateway->provider())
            ->where('provider_id', $providerSubscriptionId)
            ->first();

        if (! $subscription) {
            Log::warning('statamic-payments: a cycle arrived for an agreement this site does not know.', [
                'provider_subscription_id' => $providerSubscriptionId,
                'payment_id' => $payment->getKey(),
            ]);

            return null;
        }

        // Claimed with a conditional update rather than read-then-write: a
        // redelivered webhook must not count the same cycle twice, and the
        // count is what decides when a payment plan is finished.
        $counted = Payment::query()
            ->whereKey($payment->getKey())
            ->whereNull('subscription_id')
            ->update(['subscription_id' => $subscription->getKey(), 'updated_at' => now()]);

        if ($counted === 0) {
            return $subscription;
        }

        // A straggler for an agreement that is already over must not count.
        // Without this a late cycle on a finished plan fires `SubscriptionEnded`
        // a second time and overwrites the date it ended.
        if (! $subscription->isLive()) {
            Log::warning('statamic-payments: a cycle arrived for an agreement that is already over.', [
                'subscription_id' => $subscription->getKey(),
                'status' => $subscription->status,
                'payment_id' => $payment->getKey(),
            ]);

            return $subscription;
        }

        $subscription->increment('times_charged');
        $subscription = $subscription->fresh() ?? $subscription;

        // What the provider says about the agreement itself, taken while we are
        // already talking to it. Without this a suspension after failed charges
        // never reaches the row, and the screen keeps saying "active" for
        // somebody whose card stopped working.
        $this->refresh($subscription);
        $subscription = $subscription->fresh() ?? $subscription;

        SubscriptionRenewed::dispatch($subscription, $payment);

        // A plan that has paid its last instalment is over. The provider stops
        // by itself; this is about the row saying so, so a report does not show
        // a finished plan as still running.
        if ($subscription->times !== null && $subscription->times_charged >= $subscription->times) {
            $subscription->forceFill([
                'status' => Subscription::STATUS_COMPLETED,
                'ended_at' => now(),
                'next_payment_at' => null,
            ])->save();

            SubscriptionEnded::dispatch($subscription->fresh() ?? $subscription);
        }

        return $subscription;
    }

    /**
     * Ask the provider what this agreement's state really is, and write it down.
     *
     * The rule the whole package rests on, applied to agreements and not only to
     * payments: the status comes from the provider. Quiet on failure — a
     * provider that will not answer right now is not a reason to change what the
     * row says.
     */
    public function refresh(Subscription $subscription): ?Subscription
    {
        if (! $this->available() || str_starts_with($subscription->provider_id, 'pending-')) {
            return null;
        }

        /** @var SubscriptionGateway $gateway */
        $gateway = $this->gateway;

        try {
            $remote = $gateway->fetchSubscription($subscription->customer_reference, $subscription->provider_id);
        } catch (Throwable $e) {
            Log::warning('statamic-payments: the provider would not say how this agreement is doing.', [
                'subscription_id' => $subscription->getKey(),
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        // A completed or cancelled row is not walked backwards by a provider
        // answer this package cannot read; `normaliseSubscription()` already
        // lands the unknown on `suspended`, and re-opening a finished agreement
        // would be worse than leaving it.
        $update = ['status' => $remote->status];

        if ($remote->nextPaymentAt) {
            $update['next_payment_at'] = Carbon::parse($remote->nextPaymentAt);
        }

        if (! $remote->isLive() && ! $subscription->ended_at) {
            $update['ended_at'] = now();
        }

        $subscription->forceFill($update)->save();

        return $subscription->fresh() ?? $subscription;
    }

    /**
     * Stop one.
     *
     * The provider is told first and its answer is what gets written. Marking
     * the row cancelled and hoping is how somebody keeps being charged for a
     * thing their account says they cancelled.
     */
    public function cancel(Subscription $subscription): bool
    {
        if (! $this->available()) {
            return false;
        }

        /** @var SubscriptionGateway $gateway */
        $gateway = $this->gateway;

        try {
            $remote = $gateway->cancelSubscription($subscription->customer_reference, $subscription->provider_id);
        } catch (Throwable $e) {
            Log::error('statamic-payments: the provider would not cancel this agreement; the row is unchanged.', [
                'subscription_id' => $subscription->getKey(),
                'exception' => $e->getMessage(),
            ]);

            return false;
        }

        if ($remote->isLive()) {
            Log::error('statamic-payments: the provider still reports this agreement as running after a cancellation.', [
                'subscription_id' => $subscription->getKey(),
                'status' => $remote->status,
            ]);

            return false;
        }

        $subscription->forceFill([
            'status' => Subscription::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'ended_at' => now(),
            'next_payment_at' => null,
        ])->save();

        SubscriptionCancelled::dispatch($subscription->fresh() ?? $subscription);

        return true;
    }

    /**
     * One interval from now, in the provider's vocabulary.
     *
     * `"1 month"`, `"12 weeks"`, `"2 days"` — the same words the provider takes,
     * which is why they are stored as typed rather than parsed into a unit
     * enum. Anything unrecognised falls back to a month rather than throwing:
     * getting the next date slightly wrong is recoverable, refusing to record a
     * subscription somebody has already paid for is not.
     */
    protected function afterOneInterval(string $interval): Carbon
    {
        // A month, without falling off the end of one. `add('1 month')` on the
        // 31st of January lands on the 3rd of March: February is skipped and the
        // provider then bills on the 3rd forever after. Measured, not assumed.
        if (preg_match('/^(\d+)\s*months?$/i', trim($interval), $m)) {
            return Carbon::now()->addMonthsNoOverflow((int) $m[1]);
        }

        try {
            return Carbon::now()->add($interval);
        } catch (Throwable) {
            Log::warning('statamic-payments: an interval this package cannot read; the next date is a guess.', [
                'interval' => $interval,
            ]);

            return Carbon::now()->addMonth();
        }
    }
}
