<?php

namespace Goldnead\StatamicPayments\Http\Resources\Cp;

use Goldnead\StatamicPayments\Http\Resources\Cp\Concerns\DescribesProducts;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Portal\Display;
use Goldnead\StatamicPayments\Support\BuyerSubject;
use Goldnead\StatamicPayments\Support\Dunning;
use Goldnead\StatamicPayments\Support\LocalTime;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

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

            // What the provider charges per cycle now: a running coupon comes
            // off. `price` is the agreement's own price.
            'amount' => $this->chargedAmount(),
            'price' => $this->amount(),
            'amount_display' => Display::money($this->chargedCent(), $this->currency),
            'coupon' => Display::coupon($this->resource, short: true),
            'currency' => $this->currency,

            'interval' => $this->interval,
            'rhythm' => $this->rhythm(),

            // `2 / 3` while there is an end to count towards, and the bare
            // count when there is not: `7 / ∞` reads like a broken template,
            // and `7 / null` like a broken one that got shipped.
            'progress' => $this->isPlan()
                ? $this->times_charged.' / '.$this->times
                : (string) $this->times_charged,

            // Ob gerade gemahnt wird, und wie weit.
            //
            // Der Status allein sagt es nicht: Mollie setzt bei einer
            // gescheiterten Abbuchung `suspended`, und das steht auch an einem
            // Abo, dem niemand hinterherschreibt. Wer sehen will, ob eine
            // Strecke laeuft und wie viele Briefe schon raus sind, musste bis
            // hierher in die Datenbank sehen.
            'dunning' => $this->dunning_started_at === null ? null : [
                'label' => __('statamic-payments::messages.dunning_running', [
                    'stage' => (int) $this->dunning_stage,
                    'stages' => count(app(Dunning::class)->stages()),
                ]),
                'started_at' => $this->dunning_started_at->toIso8601String(),
            ],

            'status' => $this->status,
            'status_label' => $this->translatedOrRaw(
                'subscription_status_'.$this->status,
                (string) $this->status
            ),

            'email' => $this->email,
            'name' => $this->name,
            // Für wen das Abo läuft, wenn nicht für die Person selbst
            // (`meta.entitlement_subject`, von der ersten Zahlung übernommen).
            'subject_display' => BuyerSubject::describe($this->meta)['display'] ?? null,

            'starts_at' => $this->starts_at?->toIso8601String(),
            'next_payment_at' => $this->next_payment_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'paused_at' => $this->paused_at?->toIso8601String(),

            // The same moments, formatted on the server: the shop's display
            // time zone and the reader's language, one format for the whole
            // detail. `<date-time>` formats by the browser's locale, which put
            // "9/20/2026, 12:00 PM" next to "01.11.2026" on one screen.
            // "Starts" is when the contract began, not `starts_at`: that is
            // the provider's rhythm (one interval after the first payment,
            // after a trial), and on Mollie a resume starts a new agreement
            // with a new date (adg staging, 1.25.0-rc.1). The earlier of the
            // row's creation and `starts_at`: a backfilled row is younger than
            // the agreement it describes.
            'starts_at_display' => LocalTime::moment(
                $this->created_at && $this->starts_at ? $this->created_at->min($this->starts_at) : ($this->created_at ?? $this->starts_at)
            ),
            // A charge falls on a day; its clock time is the provider's and
            // printed "00:00" on every Mollie agreement.
            'next_payment_at_display' => LocalTime::date($this->next_payment_at),
            'cancelled_at_display' => LocalTime::moment($this->cancelled_at),
            'ended_at_display' => LocalTime::moment($this->ended_at),
            'paused_at_display' => LocalTime::moment($this->paused_at),
            'dunning_started_at_display' => LocalTime::moment($this->dunning_started_at),

            // A day, not a moment: the day in the shop's zone.
            'resumes_at' => LocalTime::date($this->resumes_at),
            // Month and year, the way a card prints it.
            'card_expires_at' => $this->card_expires_at?->format('m/Y'),

            // What changed about this agreement, newest first, already in words.
            'history' => $this->history(),

            'total' => $this->total(),

            'provider' => $this->provider,
            'provider_id' => $this->provider_id,
            'customer_reference' => $this->customer_reference,

            // Whether stopping it is a thing that can still happen. The screen
            // offers the action off this, and the endpoint asks the provider
            // rather than this flag — a row can go stale between the two.
            'can_cancel' => $this->isRunning(),

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

    /**
     * Pauses and switches, as lines a person reads.
     *
     * From `meta`, where `SubscriptionPauses` and `SubscriptionSwitches` keep
     * them. Newest first, and each one says when and from where.
     *
     * @return list<array{at: string|null, text: string}>
     */
    protected function history(): array
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $lines = [];

        foreach ((array) ($meta['switches'] ?? []) as $switch) {
            if (! is_array($switch)) {
                continue;
            }

            $immediate = (bool) ($switch['immediate'] ?? false);

            $lines[] = [
                'at' => $switch['at'] ?? null,
                'text' => __($immediate
                    ? 'statamic-payments::subscriptions.history_switch'
                    : 'statamic-payments::subscriptions.history_switch_later', [
                        'from' => $this->productName((string) ($switch['from'] ?? '')) ?? ($switch['from'] ?? ''),
                        'to' => $this->productName((string) ($switch['to'] ?? '')) ?? ($switch['to'] ?? ''),
                        'amount' => Display::money((int) ($switch['proration_cent'] ?? 0), $this->currency),
                    ]).(isset($switch['proration_failed_payment_id'])
                        // The difference was charged and did not arrive: the
                        // new product runs unpaid for the rest of the period.
                        ? '. '.__('statamic-payments::subscriptions.history_proration_failed')
                        : ''),
                'failed' => isset($switch['proration_failed_payment_id']),
            ];
        }

        foreach ((array) ($meta['pauses'] ?? []) as $pause) {
            if (! is_array($pause)) {
                continue;
            }

            $lines[] = [
                'at' => $pause['resumed_at'] ?? null,
                'text' => __('statamic-payments::subscriptions.history_pause', [
                    'from' => $this->day($pause['paused_at'] ?? null),
                    'to' => $this->day($pause['resumed_at'] ?? null),
                ]),
            ];
        }

        usort($lines, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

        return array_map(fn (array $line) => $line + [
            'at_display' => is_string($line['at']) ? LocalTime::moment(Carbon::parse($line['at'])) : null,
            'failed' => false,
        ], $lines);
    }

    /** A stored moment as a short date in the reader's language. */
    protected function day(mixed $iso): string
    {
        if (! is_string($iso) || $iso === '') {
            return '';
        }

        try {
            return (string) LocalTime::date(Carbon::parse($iso));
        } catch (\Throwable) {
            return substr($iso, 0, 10);
        }
    }

    /** What the whole agreement comes to, when it has an end. */
    protected function total(): ?string
    {
        $total = $this->totalCent();

        return $total === null ? null : number_format($total / 100, 2, '.', '');
    }
}
