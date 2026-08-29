<?php

namespace Goldnead\StatamicPayments\Integrations\Insights;

use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * How many confirmed payments there were.
 *
 * Payments, not line items: an order bump is a second product on one payment
 * and one order, not two. The product split below is the exception and says so
 * — there a payment is counted once per product it contains, which is what the
 * question "how many orders included this" means, and it is why those rows can
 * add up to more than the total.
 */
class Orders extends PaymentMetric implements HasBreakdowns
{
    public function handle(): string
    {
        return 'payments.orders';
    }

    public function label(): string
    {
        return __('statamic-payments::messages.metric_orders');
    }

    public function description(): ?string
    {
        return __('statamic-payments::messages.metric_orders_description');
    }

    public function unit(): string
    {
        return Unit::COUNT;
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        return (int) $this->paidInPeriod($query)->count();
    }

    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        return array_map(
            fn ($measured) => (int) $measured,
            $this->bucketed($this->paidInPeriod($query), $query, 'count(*)'),
        );
    }

    public function breakdowns(): array
    {
        return [
            'campaign' => __('statamic-payments::messages.metric_breakdown_campaign'),
            'product' => __('statamic-payments::messages.metric_breakdown_product'),
        ];
    }

    public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array
    {
        if (! $this->available()) {
            return [];
        }

        if ($dimension === 'product') {
            $rows = $this->byProduct($query, $limit);

            // Ordered by revenue upstream, because that is what the split of a
            // money figure is sorted by. Here the value is a count, and the
            // contract asks for largest first — so it is sorted again on what
            // is actually being shown.
            usort($rows, fn (array $a, array $b) => $b['orders'] <=> $a['orders']);

            return array_map(fn (array $row) => [
                'key' => $row['handle'],
                'label' => $this->productName($row['handle']),
                'value' => $row['orders'],
                'meta' => ['gross_cent' => $row['gross_cent'], 'quantity' => $row['quantity']],
            ], $rows);
        }

        if ($dimension !== 'campaign') {
            return [];
        }

        // The same fold as the revenue split, counting payments instead of
        // adding cents — so the two screens name a campaign identically rather
        // than one saying "sommer · newsletter" and the other "sommer".
        return $this->splitByCampaign($query, 'count(*)', $limit);
    }
}
