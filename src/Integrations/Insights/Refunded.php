<?php

namespace Goldnead\StatamicPayments\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * Money that went back, counted on the day it left.
 *
 * Its own query on its own date, never a join onto the sales: a refund in March
 * of a January sale belongs to March, and joining the two would file it under
 * the month of the purchase — a month whose totals a bank statement has long
 * since disagreed with.
 */
class Refunded extends PaymentMetric
{
    public function handle(): string
    {
        return 'payments.refunded';
    }

    public function label(): string
    {
        return __('statamic-payments::messages.metric_refunded');
    }

    public function description(): ?string
    {
        return __('statamic-payments::messages.metric_refunded_description');
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

        return (int) $this->refundedInPeriod($query)->sum('refunded_cent');
    }

    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        return array_map(
            fn ($measured) => (int) $measured,
            $this->bucketed($this->refundedInPeriod($query), $query, 'sum(refunded_cent)', 'refunded_at'),
        );
    }
}
