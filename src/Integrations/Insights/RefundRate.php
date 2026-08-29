<?php

namespace Goldnead\StatamicPayments\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * How much of what came in went back out again.
 *
 * Null, not zero per cent. Was nothing taken in during the period and something
 * refunded anyway — a January sale repaid in March — then every rate is a false
 * statement standing directly beside its own disproof, because the screen is
 * showing the refunded amount next to it. No value means the question does not
 * apply, which is the honest answer and the one `RevenueReport::totals()`
 * already gave.
 *
 * Both halves keep their own dates, as everywhere here: sales on `paid_at`,
 * refunds on `refunded_at`. The rate is therefore about a period's cash and not
 * about a cohort of orders — a rate of "how many of the things sold in March
 * came back" would need to follow those orders forward through time and would
 * change every month after the fact.
 */
class RefundRate extends PaymentMetric
{
    public function handle(): string
    {
        return 'payments.refund_rate';
    }

    public function label(): string
    {
        return __('statamic-payments::messages.metric_refund_rate');
    }

    public function description(): ?string
    {
        return __('statamic-payments::messages.metric_refund_rate_description');
    }

    public function unit(): string
    {
        return Unit::PERCENT;
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        $sales = $this->paidInPeriod($query)
            ->selectRaw('coalesce(sum(amount_cent), 0) as gross_cent')
            ->first();

        $gross = (int) ($sales->gross_cent ?? 0);

        if ($gross <= 0) {
            return null;
        }

        $refunded = (int) $this->refundedInPeriod($query)->sum('refunded_cent');

        // One decimal. A refund rate is read to compare months, and "7.4629 %"
        // asserts a precision that three orders cannot carry.
        return round($refunded / $gross * 100, 1);
    }

    /**
     * A rate per bucket, and only where there is something to divide by.
     *
     * A bucket that took nothing in is left out rather than shown as zero — the
     * same rule as the headline, applied per column. It means a day on which
     * only a refund happened has no bar at all, which is correct: a rate needs
     * a denominator, and that day has none.
     */
    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        $sales = $this->bucketed($this->paidInPeriod($query), $query, 'sum(amount_cent)');
        $refunds = $this->bucketed($this->refundedInPeriod($query), $query, 'sum(refunded_cent)', 'refunded_at');

        $buckets = [];

        foreach ($sales as $bucket => $gross) {
            if ((int) $gross > 0) {
                $buckets[$bucket] = round((int) ($refunds[$bucket] ?? 0) / (int) $gross * 100, 1);
            }
        }

        ksort($buckets);

        return $buckets;
    }
}
