<?php

namespace Goldnead\StatamicPayments\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * What an order was worth on average.
 *
 * Null when nothing was sold, never zero. "The average order was 0 €" is a
 * statement about orders that did not happen, and it sits on the screen next to
 * a count of zero that contradicts it. No value means the question does not
 * apply.
 *
 * Integer division, as upstream: the unit is minor units and always an integer,
 * so a half-cent average is not a thing that can be printed. The remainder is
 * dropped rather than rounded, which keeps the average from ever exceeding what
 * was actually taken.
 */
class AverageOrder extends PaymentMetric
{
    public function handle(): string
    {
        return 'payments.average_order';
    }

    public function label(): string
    {
        return __('statamic-payments::messages.metric_average_order');
    }

    public function description(): ?string
    {
        return __('statamic-payments::messages.metric_average_order_description');
    }

    public function unit(): string
    {
        return Unit::CURRENCY;
    }

    public function meta(MetricQuery $query): array
    {
        return ['currency' => $this->currencyOf($query)];
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        $row = $this->paidInPeriod($query)
            ->selectRaw('coalesce(sum(amount_cent), 0) as gross_cent, count(*) as orders')
            ->first();

        $orders = (int) ($row->orders ?? 0);

        return $orders > 0 ? intdiv((int) ($row->gross_cent ?? 0), $orders) : null;
    }

    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        $rows = $this->paidInPeriod($query)
            ->selectRaw($this->bucketExpression($query).' as bucket, coalesce(sum(amount_cent), 0) as gross_cent, count(*) as orders')
            ->groupBy('bucket')
            ->get();

        $buckets = [];

        foreach ($rows as $row) {
            $orders = (int) $row->orders;

            // A bucket with no orders cannot appear here — the group only
            // exists because rows fell into it — so there is no division by
            // zero to guard and no zero-order bucket to leave out.
            if ($orders > 0) {
                $buckets[(string) $row->bucket] = intdiv((int) $row->gross_cent, $orders);
            }
        }

        ksort($buckets);

        return $buckets;
    }
}
