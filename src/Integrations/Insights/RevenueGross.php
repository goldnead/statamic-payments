<?php

namespace Goldnead\StatamicPayments\Integrations\Insights;

use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * What was taken in, before anything went back.
 *
 * The headline figure, and the one every other money number here is measured
 * against. Counted on `paid_at`, in one currency, over payments the provider
 * has confirmed — never over open checkouts, which are intentions and not
 * income.
 *
 * The four splits are the questions somebody actually asks of a revenue
 * figure: which campaign produced it, which source, which product, and which
 * country — the last of those being the one an invoice's VAT rate hangs off.
 */
class RevenueGross extends PaymentMetric implements HasBreakdowns
{
    public function handle(): string
    {
        return 'payments.revenue_gross';
    }

    public function label(): string
    {
        return __('statamic-payments::messages.metric_revenue_gross');
    }

    public function description(): ?string
    {
        return __('statamic-payments::messages.metric_revenue_gross_description');
    }

    public function unit(): string
    {
        return Unit::CURRENCY;
    }

    /**
     * The currency, and what the product lines add up to.
     *
     * `line_item_sum_cent` is a check somebody else runs: the payment's own
     * `amount_cent` is authoritative — it is what was charged — while the line
     * items are a split of it, and a split that does not add up means rows were
     * written past the checkout. A screen holds this against `value` and says
     * so when the two disagree, which is how the old revenue screen found real
     * broken data. Handed over rather than judged here, because the metric owns
     * the query and the screen owns the wording.
     *
     * Absent, not zero, when there is no line-item table: nothing exists to
     * disagree with anything, and a zero would read as total disagreement.
     */
    public function meta(MetricQuery $query): array
    {
        $meta = ['currency' => $this->currencyOf($query)];

        if (($lines = $this->productSumCent($query)) !== null) {
            $meta['line_item_sum_cent'] = $lines;
        }

        return $meta;
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        $row = $this->paidInPeriod($query)
            ->selectRaw('coalesce(sum(amount_cent), 0) as gross_cent')
            ->first();

        return (int) ($row->gross_cent ?? 0);
    }

    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        return array_map(
            fn ($measured) => (int) $measured,
            $this->bucketed($this->paidInPeriod($query), $query, 'sum(amount_cent)'),
        );
    }

    public function breakdowns(): array
    {
        return [
            'campaign' => __('statamic-payments::messages.metric_breakdown_campaign'),
            'source' => __('statamic-payments::messages.metric_breakdown_source'),
            'product' => __('statamic-payments::messages.metric_breakdown_product'),
            'country' => __('statamic-payments::messages.metric_breakdown_country'),
        ];
    }

    /**
     * One split, largest first, nulls included.
     *
     * Campaign and source are two separate splits here where the report had one
     * combined row per campaign-and-source pair. The contract asks one
     * dimension at a time, and a combined row would have appeared under both
     * names and added up to more than the total under neither.
     */
    public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array
    {
        if (! $this->available()) {
            return [];
        }

        if ($dimension === 'product') {
            return array_map(fn (array $row) => [
                'key' => $row['handle'],
                'label' => $this->productName($row['handle']),
                'value' => $row['gross_cent'],
                'meta' => ['orders' => $row['orders'], 'quantity' => $row['quantity']],
            ], $this->byProduct($query, $limit));
        }

        // Its own path: the campaign split carries the source along, in the
        // label where it is unambiguous and in `meta` always.
        if ($dimension === 'campaign') {
            return $this->splitByCampaign($query, 'sum(amount_cent)', $limit);
        }

        $column = match ($dimension) {
            'source' => 'utm_source',
            'country' => 'country',
            default => null,
        };

        if ($column === null) {
            return [];
        }

        return array_map(fn (array $row) => [
            'key' => $row['key'],
            'label' => $row['key'] ?? $this->missingLabel($dimension),
            'value' => $row['value'],
        ], $this->splitByColumn($query, $column, 'sum(amount_cent)', $limit));
    }
}
