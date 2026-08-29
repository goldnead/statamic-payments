<?php

namespace Goldnead\StatamicPayments\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * How many different people bought something.
 *
 * Distinct by address, which is the only identity a payment carries — the
 * checkout does not require an account. Somebody who bought twice is one buyer
 * here and two orders next door, and the gap between those two numbers is the
 * whole of what "do people come back" looks like from the till.
 *
 * A payment without an address is not a buyer. SQL's `count(distinct …)` skips
 * nulls, which is the behaviour wanted rather than one worked around: a sale
 * with no address cannot be told apart from any other sale with no address.
 */
class Buyers extends PaymentMetric
{
    public function handle(): string
    {
        return 'payments.buyers';
    }

    public function label(): string
    {
        return __('statamic-payments::messages.metric_buyers');
    }

    public function description(): ?string
    {
        return __('statamic-payments::messages.metric_buyers_description');
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

        $row = $this->paidInPeriod($query)
            ->selectRaw('count(distinct email) as buyers')
            ->first();

        return (int) ($row->buyers ?? 0);
    }

    /**
     * Distinct per bucket, and the buckets therefore do not add up to the total.
     *
     * Somebody who bought in January and again in March is one buyer in the
     * headline and one in each of two columns. That is what "distinct" means at
     * two different grains; summing a chart of it is the reader's own mistake
     * to avoid, and inventing a number that did sum would be this one.
     */
    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        return array_map(
            fn ($measured) => (int) $measured,
            $this->bucketed($this->paidInPeriod($query), $query, 'count(distinct email)'),
        );
    }
}
