<?php

namespace Goldnead\StatamicPayments\Http\Resources\Cp;

use Goldnead\StatamicPayments\Http\Resources\Cp\Concerns\DescribesProducts;
use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One agreement.
 *
 * @mixin Subscription
 *
 * Everything the screen shows is worked out here: the money is formatted from
 * the integer, the rhythm is put into words, and the difference between a
 * subscription and a payment plan is decided by `isPlan()` rather than by a
 * template asking whether `times` is null. A row that arrives pre-answered is a
 * row no template can answer differently on the next screen.
 *
 * The cycles come along with the row. They are what the detail slide-over
 * shows, and fetching them per row when it opens would mean a second endpoint
 * and a loading state for a handful of records that were one eager load away.
 */
class ListedSubscription extends JsonResource
{
    use DescribesProducts;

    /**
     * How many cycles travel with a row.
     *
     * A payment plan has a handful. A monthly subscription running since 2019
     * has eighty, on every one of fifteen rows, and nobody scrolls that far in
     * a slide-over. The newest are the ones being asked about.
     */
    private const MAX_PAYMENTS = 50;

    public function toArray($request)
    {
        return [
            'id' => $this->id,

            'product' => $this->product,
            'product_name' => $this->productName($this->product),

            // What a site calls this: same table, same mechanism, and the one
            // column that separates the two is `times`.
            'is_plan' => $this->isPlan(),
            'kind' => $this->isPlan()
                ? __('statamic-payments::messages.subscription_kind_plan')
                : __('statamic-payments::messages.subscription_kind_subscription'),

            'amount' => $this->amount(),
            'currency' => $this->currency,

            'interval' => $this->interval,
            'rhythm' => $this->rhythm(),

            // `2 / 3` while there is an end to count towards, and the bare
            // count when there is not: `7 / ∞` reads like a broken template,
            // and `7 / null` like a broken one that got shipped.
            'progress' => $this->isPlan()
                ? $this->times_charged.' / '.$this->times
                : (string) $this->times_charged,

            'status' => $this->status,
            'status_label' => $this->translatedOrRaw(
                'subscription_status_'.$this->status,
                (string) $this->status
            ),

            'email' => $this->email,
            'name' => $this->name,

            'starts_at' => $this->starts_at?->toIso8601String(),
            'next_payment_at' => $this->next_payment_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),

            'total' => $this->total(),

            'provider' => $this->provider,
            'provider_id' => $this->provider_id,
            'customer_reference' => $this->customer_reference,

            // Whether stopping it is a thing that can still happen. The screen
            // offers the action off this, and the endpoint asks the provider
            // rather than this flag — a row can go stale between the two.
            'can_cancel' => $this->isLive(),

            'payments' => ListedPayment::collection(
                $this->whenLoaded('payments', fn () => $this->payments->take(self::MAX_PAYMENTS))
            ),
        ];
    }

    /**
     * The interval in words.
     *
     * `interval` is the provider's own vocabulary — "1 month", "12 weeks" — and
     * is stored as typed because the set of units belongs to the provider. What
     * this package can do is recognise the ordinary shapes and say them in the
     * reader's language; anything else is shown exactly as the provider wrote
     * it, which is more use than a wrong guess.
     */
    protected function rhythm(): string
    {
        $interval = trim((string) $this->interval);

        if (! preg_match('/^(\d+)\s+(day|week|month|year)s?$/i', $interval, $matches)) {
            return $interval;
        }

        return trans_choice(
            'statamic-payments::messages.subscription_rhythm_'.strtolower($matches[2]),
            (int) $matches[1],
            ['count' => (int) $matches[1]]
        );
    }

    /** What the whole agreement comes to, when it has an end. */
    protected function total(): ?string
    {
        $total = $this->totalCent();

        return $total === null ? null : number_format($total / 100, 2, '.', '');
    }
}
