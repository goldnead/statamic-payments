<?php

namespace Goldnead\StatamicPayments\Integrations\Insights;

use Goldnead\StatamicInsights\Contracts\HasFilterOptions;
use Goldnead\StatamicInsights\Contracts\Metric;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicPayments\Support\Catalogue;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * What every payment figure has in common.
 *
 * The queries below are `RevenueReport` from `statamic-insights`, moved to the
 * side of the fence that owns the tables. That is the whole point of the move:
 * the analytics addon was reading `payments` and `payment_items` directly,
 * which is exactly the coupling its own contract exists to forbid. The SQL is
 * carried over word for word — same columns, same edge cases, same reasons —
 * because a rewritten aggregate is a different number, and a revenue figure
 * that changed during a refactor is indistinguishable from one that broke.
 *
 * Three decisions shape every number in this directory, and all three are
 * inherited rather than reinvented:
 *
 * 1. **Sales count on `paid_at`, refunds on `refunded_at`.** A refund in March
 *    of a January sale belongs to March, because that is the month the money
 *    left. It means "net" mixes this period's sales with this period's refunds,
 *    which is the cash view a person actually asks for.
 * 2. **One currency at a time.** Adding 100 EUR to 100 CHF produces a number
 *    with no meaning. Every query filters to one currency — the one the screen
 *    asked for, or the site's own — rather than summing and being confidently
 *    wrong.
 * 3. **Missing is missing.** A payment with no campaign is a row keyed `null`,
 *    never a dropped one. A report that quietly excludes rows is the hardest
 *    kind of wrong to notice.
 *
 * Read with SQL aggregates rather than through this addon's own models on
 * purpose: an aggregate is a read, not a call, and hydrating ten thousand
 * payment rows to add up a column would be slower and no more correct. Product
 * *names* are the exception — those go through the catalogue, because a handle
 * only becomes a product there.
 *
 * Nothing here imports anything of the sibling's beyond the contract and its
 * two value objects, and the classes in this directory are only ever loaded
 * once the sibling has announced itself (see the guard in the ServiceProvider).
 */
abstract class PaymentMetric implements HasFilterOptions, Metric
{
    public function group(): string
    {
        return __('statamic-payments::messages.metric_group');
    }

    /**
     * Nothing to measure without the tables.
     *
     * False rather than zero, and the difference is the point: "nothing to
     * measure" and "measured nothing" are different statements, and a zero for
     * the first is the quiet kind of wrong.
     */
    public function available(): bool
    {
        return Schema::hasTable('payments');
    }

    /** Most numbers need nothing beyond their unit. Money overrides this. */
    public function meta(MetricQuery $query): array
    {
        return [];
    }

    /**
     * Which currencies this installation has ever taken, busiest first.
     *
     * On the base class rather than on one metric, because the question is the
     * same for all seven: every figure here is filtered to one currency, so every
     * one of them offers the same choice. A screen asking any of them gets the
     * same list.
     *
     * Ordered by number of orders and not alphabetically — the currency the
     * site actually trades in should be the one already selected, and on a shop
     * that took three francs once, "CHF" would otherwise sort above the euro it
     * lives on.
     *
     * Only currencies with a **paid** payment. An abandoned checkout in a
     * currency nobody ever completed is not a choice; offering it would produce
     * a switch that leads to an empty screen.
     *
     * Empty when there is nothing to choose between, which the contract
     * distinguishes from not offering the filter: no switch is the right screen
     * for a shop that has taken no money.
     */
    public function filterOptions(): array
    {
        if (! $this->available()) {
            return ['currency' => []];
        }

        $currencies = DB::table('payments')
            ->where('status', 'paid')
            ->whereNotNull('currency')
            ->groupBy('currency')
            ->orderByRaw('count(*) desc')
            ->pluck('currency')
            ->all();

        return [
            'currency' => array_map(
                fn ($currency) => ['value' => (string) $currency, 'label' => (string) $currency],
                $currencies,
            ),
        ];
    }

    /**
     * The one currency this question is about.
     *
     * The screen may hand one down; otherwise it is the site's own. A metric
     * that summed across currencies would produce a figure that agrees with no
     * bank statement anywhere.
     */
    protected function currencyOf(MetricQuery $query): string
    {
        $currency = $query->filter('currency', config('statamic-payments.currency', 'EUR'));

        return is_string($currency) && $currency !== ''
            ? $currency
            : (string) config('statamic-payments.currency', 'EUR');
    }

    /**
     * Narrow a query to the current brand.
     *
     * This is `Goldnead\BrandContext\Scopes\BrandScope::apply()` transcribed
     * for the query builder, and it must stay a transcription rather than an
     * improvement: these figures read `payments` through `DB::table()`, so
     * Eloquent's global scope never fires, and a tile that filtered by its own
     * rules would disagree with the orders listing beside it on the same
     * screen. The order of the four questions is theirs — bypass, single-brand,
     * unresolved, filter.
     *
     * `TableMetric` in statamic-insights carries the same transcription for the
     * metrics that build on it. This class does not build on it — it predates
     * it and reads two tables and a join — so the code is here as well. Kept
     * word for word with that one on purpose: two spellings of one rule is how
     * a suite ends up green while two tiles count different things.
     *
     * An unresolved brand fails closed to no rows, never to an absent metric.
     * `available()` answers whether the thing exists, and a brand nobody has
     * picked yet is not this addon ceasing to exist. A tile reading zero can be
     * understood; a tile that vanished cannot.
     */
    protected function brandScoped(Builder $rows, string $table = 'payments'): Builder
    {
        if (! app()->bound('brand-context')) {
            return $rows;
        }

        $manager = app('brand-context');

        if ($manager->scopeIsDisabled() || ! $manager->multiBrandEnabled()) {
            return $rows;
        }

        if (! $manager->hasCurrent()) {
            return $manager->failMode() === 'open'
                ? $rows
                : $rows->whereRaw('1 = 0');
        }

        return $rows->where($table.'.brand_id', $manager->currentId());
    }

    /** Every sale in the window, in one currency, in the current brand. */
    protected function paidInPeriod(MetricQuery $query): Builder
    {
        $period = $query->period;

        return $this->brandScoped(
            DB::table('payments')
                ->where('status', 'paid')
                ->where('currency', $this->currencyOf($query))
                ->whereNotNull('paid_at')
                ->when($period->from, fn ($q) => $q->where('paid_at', '>=', $period->from))
                // Half-open: `< midnight`, not `<= 23:59:59.999999`. A binding
                // formats the upper bound as `Y-m-d H:i:s` and drops the
                // fraction, so on a column that stores milliseconds every sale
                // in the last second of the period fell out — silently, and
                // only on some engines, which is why no green suite ever showed
                // it. Midnight is the same instant at every precision.
                ->when($period->toExclusive(), fn ($q) => $q->where('paid_at', '<', $period->toExclusive()))
        );
    }

    /**
     * Money that went back, on its own date.
     *
     * Its own query, never a join onto the sales. Joining would credit a refund
     * to the month the sale happened, which is not when the money left the
     * account. The `status = 'paid'` filter is deliberate and is carried over
     * unchanged: a refunded order keeps its paid status — the refund is a
     * separate axis, an amount and a time — so dropping this condition would
     * start counting repayments of orders that were never taken.
     */
    protected function refundedInPeriod(MetricQuery $query): Builder
    {
        $period = $query->period;

        return $this->brandScoped(
            DB::table('payments')
                ->where('status', 'paid')
                ->where('currency', $this->currencyOf($query))
                ->where('refunded_cent', '>', 0)
                ->whereNotNull('refunded_at')
                ->when($period->from, fn ($q) => $q->where('refunded_at', '>=', $period->from))
                ->when($period->toExclusive(), fn ($q) => $q->where('refunded_at', '<', $period->toExclusive()))
        );
    }

    /**
     * Truncating a timestamp to a day or a month, in the dialect at hand.
     *
     * `strftime` is SQLite's and MySQL has never heard of it. Written for one
     * engine, this would be green in a test suite on SQLite and a 500 on the
     * first production install that runs MySQL — the exact failure this family
     * has already paid for once.
     *
     * Two things differ from the original. The grain comes from the question
     * (`$query->bucket`) instead of being worked out again from the period
     * length: Insights decides the grain and puts it in the query, and a metric
     * that recomputed it could disagree with the axis it is drawn on. And the
     * column is a parameter, because refunds are bucketed on `refunded_at`
     * while the report only ever bucketed sales.
     */
    protected function bucketExpression(MetricQuery $query, string $column = 'paid_at'): string
    {
        $monthly = $query->bucket === MetricQuery::BUCKET_MONTH;
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'mysql', 'mariadb' => $monthly
                ? "date_format({$column}, '%Y-%m')"
                : "date_format({$column}, '%Y-%m-%d')",
            'pgsql' => $monthly
                ? "to_char({$column}, 'YYYY-MM')"
                : "to_char({$column}, 'YYYY-MM-DD')",
            default => $monthly
                ? "strftime('%Y-%m', {$column})"
                : "strftime('%Y-%m-%d', {$column})",
        };
    }

    /**
     * One aggregate per bucket, and only for the buckets that have data.
     *
     * The empty ones are left out on purpose: Insights fills the range in for
     * every metric at once. A metric that invented its own zeroes would be
     * filling them twice, and a metric that invented a bucket outside the range
     * would draw a column the axis has no place for.
     *
     * @return array<string, int|float>
     */
    protected function bucketed(Builder $rows, MetricQuery $query, string $aggregate, string $column = 'paid_at'): array
    {
        return $rows
            ->selectRaw($this->bucketExpression($query, $column).' as bucket, '.$aggregate.' as measured')
            ->groupBy('bucket')
            ->pluck('measured', 'bucket')
            ->all();
    }

    /**
     * Sales split by one column of the payments table.
     *
     * Largest first, and a null is a row. `utm_campaign` is frozen on the
     * payment at the checkout; a sale that carries none is grouped, not hidden.
     *
     * @return array<int, array{key: string|null, value: int}>
     */
    protected function splitByColumn(MetricQuery $query, string $column, string $aggregate, int $limit): array
    {
        $rows = $this->paidInPeriod($query)
            ->selectRaw($column.' as split_key, '.$aggregate.' as measured')
            ->groupBy($column)
            ->orderByRaw($aggregate.' desc')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'key' => ($row->split_key === null || $row->split_key === '') ? null : (string) $row->split_key,
            'value' => (int) $row->measured,
        ])->all();
    }

    /**
     * Sales split by campaign, with the source carried along.
     *
     * Grouped by campaign **and** source in SQL and folded to campaigns here,
     * which is the shape `RevenueReport::byCampaign()` had and the reason it
     * had it: the two facts travel together on the payment, and a split that
     * dropped the source would lose the only thing that tells two identically
     * named campaigns apart.
     *
     * The source reaches the label only when it is unambiguous — every payment
     * of that campaign carrying the same one. A campaign that ran on two
     * channels keeps its bare name, because "sommer · newsletter" on a row that
     * is half Instagram is a caption that contradicts its own number. The
     * unfolded facts go out as `meta` regardless, so a screen that wants to
     * show the source in its own column can.
     *
     * No `limit` in the query, deliberately. The limit applies to campaigns and
     * the rows here are campaign-and-source pairs; cutting before the fold
     * would drop half a campaign and report the remainder as the whole.
     *
     * @return array<int, array{key: string|null, label: string, value: int, meta: array{source: string|null, orders: int}}>
     */
    protected function splitByCampaign(MetricQuery $query, string $aggregate, int $limit): array
    {
        $rows = $this->paidInPeriod($query)
            ->selectRaw('utm_campaign, utm_source, count(*) as orders, '.$aggregate.' as measured')
            ->groupBy('utm_campaign', 'utm_source')
            ->orderByRaw($aggregate.' desc')
            ->get();

        $together = [];

        foreach ($rows as $row) {
            $campaign = ($row->utm_campaign === null || $row->utm_campaign === '') ? null : (string) $row->utm_campaign;
            $source = ($row->utm_source === null || $row->utm_source === '') ? null : (string) $row->utm_source;

            // Keyed on a marker rather than on the campaign itself: a null and
            // the string "" would otherwise collapse into the same array key as
            // a campaign literally named "0".
            $key = $campaign ?? "\0none";

            $together[$key] ??= ['key' => $campaign, 'value' => 0, 'orders' => 0, 'sources' => []];
            $together[$key]['value'] += (int) $row->measured;
            $together[$key]['orders'] += (int) $row->orders;
            $together[$key]['sources'][] = $source;
        }

        $folded = [];

        foreach ($together as $row) {
            $sources = array_values(array_unique($row['sources'], SORT_REGULAR));
            $source = count($sources) === 1 ? $sources[0] : null;
            $label = $row['key'] ?? $this->missingLabel('campaign');

            $folded[] = [
                'key' => $row['key'],
                'label' => $source === null ? $label : $label.' · '.$source,
                'value' => $row['value'],
                'meta' => ['source' => $source, 'orders' => $row['orders']],
            ];
        }

        usort($folded, fn (array $a, array $b) => $b['value'] <=> $a['value']);

        return array_slice($folded, 0, $limit);
    }

    /**
     * Which product earned what, and how many orders it was part of.
     *
     * Over the line items, not the payment: an order bump and its main product
     * are one payment and two products, and crediting the whole amount to the
     * first would overstate one and hide the other. Payments written before
     * line items existed — or by something that does not use the checkout —
     * fall back to their own handle, so nothing is dropped.
     *
     * `amount_cent * quantity - discount_cent` is the line's share of what was
     * charged, discount included, which is what makes the product rows add up
     * to the payment total instead of to a number nobody was ever billed.
     *
     * @return array<int, array{handle: string, orders: int, gross_cent: int, quantity: int}>
     */
    protected function byProduct(MetricQuery $query, int $limit): array
    {
        $rows = $this->productRows($query);

        usort($rows, fn ($a, $b) => $b['gross_cent'] <=> $a['gross_cent']);

        return array_slice($rows, 0, $limit);
    }

    /**
     * What the product rows add up to, or null when there is nothing to check.
     *
     * Reported so a screen can hold it against `value` and say when the two
     * disagree. The payment's `amount_cent` is authoritative — it is what was
     * charged; the line items are a split of it, and a split that does not add
     * up means rows were written past the checkout. That check has found real
     * broken data, which is why the number is offered at all rather than left
     * for somebody to notice.
     *
     * Over the same rows as the product split, **fallback included**, and not
     * over `payment_items` alone. A payment with no line items is the legacy
     * shape and a perfectly sound row; counting only the table would report
     * every one of them as a discrepancy and the warning would cry wolf on the
     * installs that have nothing wrong with them.
     *
     * Null when the table is absent: no lines exist to disagree with anything,
     * and a zero here would read as the largest discrepancy possible.
     */
    protected function productSumCent(MetricQuery $query): ?int
    {
        if (! $this->available() || ! Schema::hasTable('payment_items')) {
            return null;
        }

        return array_sum(array_column($this->productRows($query), 'gross_cent'));
    }

    /**
     * The product rows, unsorted and uncut.
     *
     * @return array<int, array{handle: string, orders: int, gross_cent: int, quantity: int}>
     */
    protected function productRows(MetricQuery $query): array
    {
        if (! $this->available() || ! Schema::hasTable('payment_items')) {
            return [];
        }

        $period = $query->period;

        // The one query in this class that does not go through
        // `paidInPeriod()`, so every condition it shares has to be repeated
        // here by hand — the brand among them. A join is exactly the shape that
        // slips past a filter applied centrally somewhere else, which is why it
        // is spelled out rather than assumed.
        $lines = $this->brandScoped(
            DB::table('payment_items')
                ->join('payments', 'payments.id', '=', 'payment_items.payment_id')
                ->where('payments.status', 'paid')
                ->where('payments.currency', $this->currencyOf($query))
                ->whereNotNull('payments.paid_at')
                ->when($period->from, fn ($q) => $q->where('payments.paid_at', '>=', $period->from))
                ->when($period->toExclusive(), fn ($q) => $q->where('payments.paid_at', '<', $period->toExclusive()))
        )
            ->selectRaw('payment_items.product as handle, count(distinct payments.id) as orders, sum(payment_items.amount_cent * payment_items.quantity - payment_items.discount_cent) as gross_cent, sum(payment_items.quantity) as quantity')
            ->groupBy('payment_items.product')
            ->get();

        $withoutLines = $this->paidInPeriod($query)
            ->whereNotExists(fn (Builder $q) => $q->from('payment_items')->whereColumn('payment_items.payment_id', 'payments.id'))
            ->selectRaw('product as handle, count(*) as orders, sum(amount_cent) as gross_cent, count(*) as quantity')
            ->groupBy('product')
            ->get();

        $together = [];

        foreach ($lines->concat($withoutLines) as $row) {
            $handle = (string) $row->handle;

            $together[$handle] ??= ['handle' => $handle, 'orders' => 0, 'gross_cent' => 0, 'quantity' => 0];
            $together[$handle]['orders'] += (int) $row->orders;
            $together[$handle]['gross_cent'] += (int) $row->gross_cent;
            $together[$handle]['quantity'] += (int) $row->quantity;
        }

        return array_values($together);
    }

    /**
     * What the buyer would recognise.
     *
     * Through the catalogue, never the config array: an offer registers its own
     * resolver there, and a handle sold through one resolves nowhere else. A
     * handle with no catalogue entry keeps its handle rather than vanishing.
     *
     * The original reached for the class by name behind a `class_exists` guard,
     * because it lived in the other addon. Here it is this package's own class
     * and can simply be named; the try/catch stays, because a resolver
     * contributed by a third addon may throw and a broken offer must cost a
     * product name, not the whole tile.
     */
    protected function productName(string $handle): string
    {
        try {
            $product = app(Catalogue::class)->find($handle);
        } catch (Throwable) {
            return $handle;
        }

        return is_array($product) && is_string($product['name'] ?? null) && $product['name'] !== ''
            ? $product['name']
            : $handle;
    }

    /** The words for a row that has no value in the dimension it is split by. */
    protected function missingLabel(string $dimension): string
    {
        return __('statamic-payments::messages.metric_no_'.$dimension);
    }
}
