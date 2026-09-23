<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Events\SubscriptionReplaced;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A purchase that ends another agreement of the same buyer.
 *
 * Said on the sold product, in its catalogue entry:
 *
 *     'jahresabo' => [
 *         'name' => 'Jahresabo', 'amount_cent' => 19000, 'interval' => '1 year',
 *         'replaces' => ['monatsabo'],   // ends a running `monatsabo`
 *         'replaces_credit' => true,     // default: credit what is left of it
 *     ],
 *
 * `statamic-offers` fills these keys from the offer's own fields.
 *
 * **Who "the same buyer" is.** The address on the paid order, compared without
 * case. That is the only identity a checkout has, and it is what ThriveCart
 * uses for the same feature. What protects it is that the purchase is paid:
 * somebody typing another person's address pays for that person's replacement,
 * and the credit goes to that same new agreement.
 *
 * **The credit is time, not money.** What is left of the replaced period, in
 * minor units, is turned into days by which the new agreement's first charge
 * moves back. No refund, no provider call beyond the cancellation, the same on
 * Stripe and Mollie. When the purchase is not an agreement (a one-off), there is
 * nothing to credit against and the old one simply ends.
 *
 * **Ending is cancelling.** `Subscriptions::cancel()` is called for each, with
 * its rules: provider first, the paid period keeps its access.
 */
class SubscriptionReplacements
{
    public function __construct(
        protected Subscriptions $subscriptions,
        protected Catalogue $catalogue,
    ) {}

    /**
     * The running agreements this payment ends.
     *
     * @return Collection<int, Subscription>
     */
    public function replacedBy(Payment $payment): Collection
    {
        if (is_array(data_get($payment->meta, 'subscription_change'))) {
            return collect();
        }

        $handles = $this->handles($payment);
        $email = is_string($payment->email) ? mb_strtolower(trim($payment->email)) : '';

        if ($handles === [] || $email === '') {
            return collect();
        }

        return Subscription::query()
            ->whereIn('product', $handles)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PENDING, Subscription::STATUS_PAUSED])
            ->orderBy('id')
            ->get();
    }

    /**
     * How many days the new agreement starts later, for what is left of the old ones.
     *
     * Read-only: called before the new agreement is created, so its first date
     * can carry the credit.
     */
    public function creditDays(Payment $payment): int
    {
        return $this->credit($payment)[1];
    }

    /**
     * End what this payment replaces. Called once the new agreement exists (or
     * not, for a one-off).
     */
    public function apply(Payment $payment, ?Subscription $replacement): int
    {
        $replaced = $this->replacedBy($payment)
            ->reject(fn (Subscription $s) => $replacement !== null && $s->getKey() === $replacement->getKey());

        if ($replaced->isEmpty()) {
            return 0;
        }

        [$creditCent, $creditDays] = $replacement !== null ? $this->credit($payment) : [0, 0];
        $ended = 0;

        foreach ($replaced as $subscription) {
            $subscription->forceFill(['meta' => array_merge($subscription->meta ?? [], [
                'replaced_by_payment' => $payment->getKey(),
            ])])->save();

            if (! $this->subscriptions->cancel($subscription)) {
                Log::error('statamic-payments: a purchase should have ended an agreement and the provider refused; both are running.', [
                    'subscription_id' => $subscription->getKey(),
                    'payment_id' => $payment->getKey(),
                ]);

                continue;
            }

            $ended++;

            try {
                SubscriptionReplaced::dispatch(
                    $subscription->fresh() ?? $subscription,
                    $payment,
                    $replacement,
                    $creditCent,
                    $creditDays,
                );
            } catch (Throwable $e) {
                Log::error('statamic-payments: a listener threw on a replacement that happened anyway.', [
                    'subscription_id' => $subscription->getKey(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        if ($replacement !== null && $creditDays > 0) {
            $replacement->forceFill(['meta' => array_merge($replacement->meta ?? [], [
                'credit' => ['cent' => $creditCent, 'days' => $creditDays, 'from' => $replaced->map(fn (Subscription $s) => $s->getKey())->values()->all()],
            ])])->save();
        }

        return $ended;
    }

    /** @return list<string> */
    protected function handles(Payment $payment): array
    {
        $entry = $this->catalogue->find((string) $payment->product) ?? [];
        $handles = $entry['replaces'] ?? [];

        if (is_string($handles)) {
            $handles = [$handles];
        }

        return array_values(array_filter((array) $handles, fn ($h) => is_string($h) && $h !== ''));
    }

    /**
     * What is left of the replaced periods, and the days it buys on the new one.
     *
     * @return array{0: int, 1: int}
     */
    protected function credit(Payment $payment): array
    {
        $entry = $this->catalogue->find((string) $payment->product) ?? [];

        if (($entry['replaces_credit'] ?? true) === false) {
            return [0, 0];
        }

        $plan = $this->subscriptions->planFor((string) $payment->product);
        $newCent = (int) ($entry['amount_cent'] ?? 0);

        if ($plan === null || $newCent <= 0) {
            return [0, 0];
        }

        $cent = 0;

        foreach ($this->replacedBy($payment) as $old) {
            // Only what was paid is worth anything. A trial or an agreement whose
            // last cycle failed has no unused paid time to hand on.
            if ($old->status !== Subscription::STATUS_ACTIVE || $old->paidThroughAt() === null) {
                continue;
            }

            $cent += (int) round($old->amount_cent * $old->remainingFraction());
        }

        if ($cent <= 0) {
            return [0, 0];
        }

        $now = Carbon::now();
        $periodDays = max(1, (int) $now->diffInDays(Subscription::addInterval($now, $plan['interval'])));
        $days = (int) floor($cent / ($newCent / $periodDays));

        return [$cent, max(0, $days)];
    }
}
