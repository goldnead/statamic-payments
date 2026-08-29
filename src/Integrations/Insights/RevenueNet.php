<?php

namespace Goldnead\StatamicPayments\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * What was taken in, minus what went back.
 *
 * Deliberately the cash view: this period's sales less this period's refunds,
 * which mixes a refund of an older sale into a newer month. That is what a
 * person means when they ask what a month brought in, and it is said on the
 * screen rather than left for somebody to discover — a "net" that reached back
 * and reduced a month already closed would disagree with every statement ever
 * exported from it.
 */
class RevenueNet extends PaymentMetric
{
    public function handle(): string
    {
        return 'payments.revenue_net';
    }

    public function label(): string
    {
        return __('statamic-payments::messages.metric_revenue_net');
    }

    public function description(): ?string
    {
        return __('statamic-payments::messages.metric_revenue_net_description');
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

        $sales = $this->paidInPeriod($query)
            ->selectRaw('coalesce(sum(amount_cent), 0) as gross_cent')
            ->first();

        $refunded = $this->refundedInPeriod($query)->sum('refunded_cent');

        return (int) ($sales->gross_cent ?? 0) - (int) $refunded;
    }

    /**
     * Two series subtracted bucket by bucket.
     *
     * A bucket that holds only a refund stays in, with a negative value. It is
     * a day on which money moved, and dropping it would hide the one kind of
     * day a reader is looking for.
     */
    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        $sales = $this->bucketed($this->paidInPeriod($query), $query, 'sum(amount_cent)');
        $refunds = $this->bucketed($this->refundedInPeriod($query), $query, 'sum(refunded_cent)', 'refunded_at');

        $buckets = [];

        foreach (array_keys($sales + $refunds) as $bucket) {
            $buckets[$bucket] = (int) ($sales[$bucket] ?? 0) - (int) ($refunds[$bucket] ?? 0);
        }

        ksort($buckets);

        return $buckets;
    }
}
