<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicInsights\Contracts\Metric;
use Goldnead\StatamicInsights\Facades\Insights as InsightsStandIn;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\Unit;
use Goldnead\StatamicPayments\Integrations\Insights\AverageOrder;
use Goldnead\StatamicPayments\Integrations\Insights\Buyers;
use Goldnead\StatamicPayments\Integrations\Insights\Orders;
use Goldnead\StatamicPayments\Integrations\Insights\PaymentMetric;
use Goldnead\StatamicPayments\Integrations\Insights\Refunded;
use Goldnead\StatamicPayments\Integrations\Insights\RefundRate;
use Goldnead\StatamicPayments\Integrations\Insights\RevenueGross;
use Goldnead\StatamicPayments\Integrations\Insights\RevenueNet;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * The seven numbers this addon offers the analytics addon.
 *
 * The queries are a port of `RevenueReport`, which is where they lived while
 * the analytics addon still read these tables itself. **The point of this file
 * is that the port changed no number.** Every expectation below is worked out
 * by hand from the same fixture, following the rules the original wrote down —
 * sales on `paid_at`, refunds on `refunded_at`, one currency, nothing dropped —
 * so a query that drifted shows up as an arithmetic disagreement rather than
 * as a green suite over a different report.
 *
 * Tested against a stand-in for the contract rather than the real package, for
 * the same reason `LeadhubBridgeTest` stands in for the CRM: the sibling is
 * optional, and a test that needed it installed would be proving the opposite
 * of what this addon claims. See `tests/Fakes/insights-contracts.php` for why
 * that is a required file and not an autoload entry.
 *
 * Time is frozen. The buckets are asserted as literal dates, and a suite that
 * ran across midnight would otherwise fail once a night for reasons that have
 * nothing to do with the code.
 */
class InsightsMetricsTest extends TestCase
{
    /** The day everything below is measured from. */
    protected const HEUTE = '2026-08-20 12:00:00';

    /** Collects what the service provider registers. */
    protected object $insights;

    protected function setUp(): void
    {
        // Before the application exists, both of them. The contracts have to be
        // there before a metric class is loaded, and the facade has to be there
        // before the provider's `booted()` callback asks whether it is — a
        // callback that has already run cannot be given a second chance.
        require_once __DIR__.'/../Fakes/insights-contracts.php';
        require_once __DIR__.'/../Fakes/insights-facade.php';

        $this->insights = new class
        {
            /** @var array<string, string> */
            public array $registered = [];

            /**
             * Stricter than the real manager on purpose.
             *
             * The genuine one accepts a metric without a handle and works one
             * out by constructing it. Accepting that here would let the
             * provider drop the handle and still look correct — and the handle
             * is the half that ends up in saved dashboards and URLs.
             */
            public function registerMetric(string|Metric|\Closure $metric, ?string $handle = null): void
            {
                if (! is_string($metric) || $handle === null) {
                    throw new \InvalidArgumentException('This addon registers metrics lazily: a class name and a handle.');
                }

                $this->registered[$handle] = $metric;
            }
        };

        InsightsStandIn::$root = $this->insights;

        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::HEUTE));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        InsightsStandIn::$root = null;

        parent::tearDown();
    }

    // -- The fixture --------------------------------------------------------

    /**
     * Three sales in one currency, one refund, one open checkout, one in another.
     *
     * Small enough to add up in the head, and every awkward case is in it: a
     * buyer who came back, a sale with no campaign, a payment with line items
     * and one without, an order bump, a refund dated after its sale, a checkout
     * that was never paid, and a second currency.
     *
     * In euros: 1900 + 2900 + 1900 = 6700 taken, 500 given back, three orders,
     * two buyers.
     */
    protected function fixture(): void
    {
        $eins = $this->payment([
            'provider_id' => 'tr_eins',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'email' => 'anna@example.com',
            'country' => 'DE',
            'utm_campaign' => 'sommer-2026',
            'utm_source' => 'newsletter',
            'paid_at' => '2026-08-15 10:00:00',
            // Refunded four days later. It belongs to the 19th, which is when
            // the money left, and not to the 15th.
            'refunded_cent' => 500,
            'refunded_at' => '2026-08-19 08:00:00',
        ]);

        $this->item($eins, 'noten-paket', 'Notenpaket', 1900);

        // No campaign and no source. A row in every split, never an omission.
        $zwei = $this->payment([
            'provider_id' => 'tr_zwei',
            'product' => 'kurs',
            'amount_cent' => 2900,
            'email' => 'bruno@example.com',
            'country' => 'AT',
            'utm_campaign' => null,
            'utm_source' => null,
            'paid_at' => '2026-08-15 18:00:00',
        ]);

        // One payment, two products: the course and the bump ticked beside it.
        $this->item($zwei, 'kurs', 'Kurs', 2400);
        $this->item($zwei, 'bump', 'Zusatzheft', 500, PaymentItem::KIND_BUMP);

        // Anna again, and this one has no line items at all — the shape a
        // payment written before line items existed has.
        $this->payment([
            'provider_id' => 'tr_drei',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'email' => 'anna@example.com',
            'country' => 'DE',
            'utm_campaign' => 'sommer-2026',
            'utm_source' => 'newsletter',
            'paid_at' => '2026-08-18 09:00:00',
        ]);

        // An intention, not income. Must not appear in a single figure.
        $this->payment([
            'provider_id' => 'tr_offen',
            'product' => 'kurs',
            'amount_cent' => 9900,
            'email' => 'clara@example.com',
            'status' => Payment::STATUS_OPEN,
            'paid_at' => null,
        ]);

        // Francs. Real money, and none of it belongs in a euro figure.
        $this->payment([
            'provider_id' => 'tr_franken',
            'product' => 'kurs',
            'amount_cent' => 5000,
            'currency' => 'CHF',
            'email' => 'chris@example.ch',
            'country' => 'CH',
            'paid_at' => '2026-08-17 11:00:00',
        ]);
    }

    protected function payment(array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'provider' => 'mollie',
            'provider_id' => 'tr_'.uniqid(),
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'kaeufer@example.com',
            'name' => 'Maria Schneider',
            'paid_at' => now(),
        ], $overrides));
    }

    protected function item(Payment $payment, string $product, string $name, int $amountCent, string $kind = PaymentItem::KIND_PRIMARY, int $quantity = 1, int $discountCent = 0): PaymentItem
    {
        return PaymentItem::create([
            'payment_id' => $payment->getKey(),
            'product' => $product,
            'name' => $name,
            'amount_cent' => $amountCent,
            'quantity' => $quantity,
            'discount_cent' => $discountCent,
            'kind' => $kind,
        ]);
    }

    /** The ten days the fixture lives in, bucketed by day. */
    protected function query(array $filters = [], string $bucket = MetricQuery::BUCKET_DAY): MetricQuery
    {
        return new MetricQuery(
            Period::between(Carbon::parse('2026-08-11')->startOfDay(), Carbon::parse('2026-08-20')->endOfDay()),
            $bucket,
            $filters,
        );
    }

    /**
     * Bind a stand-in for statamic-brand-context's manager.
     *
     * No more permissive than the real one: it answers exactly the four
     * questions `BrandScope::apply()` asks and nothing else, so a metric that
     * invented a fifth would fail here rather than pass against a mock that
     * says yes to everything.
     */
    protected function marke(bool $multi = true, ?int $current = 1, string $failMode = 'closed', bool $disabled = false): void
    {
        $this->app->instance('brand-context', new class($multi, $current, $failMode, $disabled)
        {
            public function __construct(
                protected bool $multi,
                protected ?int $current,
                protected string $mode,
                protected bool $disabled,
            ) {}

            public function scopeIsDisabled(): bool
            {
                return $this->disabled;
            }

            public function multiBrandEnabled(): bool
            {
                return $this->multi;
            }

            public function hasCurrent(): bool
            {
                return $this->current !== null;
            }

            public function currentId(): ?int
            {
                return $this->current;
            }

            public function failMode(): string
            {
                return $this->mode;
            }
        });
    }

    /** A paid row written straight to the table, so the brand and the instant are exactly as stated. */
    protected function verkauf(string $paidAt, int $brand, int $cent = 1000, string $product = 'noten-paket'): int
    {
        return DB::table('payments')->insertGetId([
            'provider' => 'mollie',
            'provider_id' => 'tr_'.uniqid(),
            'product' => $product,
            'amount_cent' => $cent,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'kaeufer@example.com',
            'name' => 'Maria Schneider',
            'brand_id' => $brand,
            'paid_at' => $paidAt,
            'created_at' => $paidAt,
            'updated_at' => $paidAt,
        ]);
    }

    /** @return array<string|int, int|float> */
    protected function keyed(array $rows): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            $keyed[$row['key'] ?? ''] = $row['value'];
        }

        return $keyed;
    }

    // -- The seven numbers --------------------------------------------------

    /**
     * Every figure at once, against hand-worked totals.
     *
     * One test rather than seven, deliberately: they are read side by side on a
     * screen and have to agree with each other. A gross that changed without
     * the net following it is the failure worth catching, and seven separate
     * tests are seven chances to fix one of them and leave the rest.
     */
    #[Test]
    public function the_six_figures_match_what_the_report_would_have_said(): void
    {
        $this->fixture();
        $frage = $this->query();

        $this->assertSame(6700, (new RevenueGross)->value($frage), 'gross: 1900 + 2900 + 1900');
        $this->assertSame(500, (new Refunded)->value($frage), 'refunded: half of the first order');
        $this->assertSame(6200, (new RevenueNet)->value($frage), 'net: 6700 taken less 500 given back');
        $this->assertSame(3, (new Orders)->value($frage), 'orders: the bump is not a second order');
        $this->assertSame(2, (new Buyers)->value($frage), 'buyers: anna bought twice and counts once');

        // intdiv, not round: the unit is minor units and 2233.33 is not a
        // number of cents anybody was charged.
        $this->assertSame(2233, (new AverageOrder)->value($frage), 'average: intdiv(6700, 3)');

        // 500 / 6700 = 7.46268…, and one decimal is all three orders can carry.
        $this->assertSame(7.5, (new RefundRate)->value($frage), 'rate: round(500 / 6700 * 100, 1)');
    }

    /** The handles are a contract. They end up in saved dashboards and in URLs. */
    #[Test]
    public function the_handles_and_units_are_the_ones_that_were_promised(): void
    {
        $erwartet = [
            [RevenueGross::class, 'payments.revenue_gross', Unit::CURRENCY],
            [RevenueNet::class, 'payments.revenue_net', Unit::CURRENCY],
            [Refunded::class, 'payments.refunded', Unit::CURRENCY],
            [RefundRate::class, 'payments.refund_rate', Unit::PERCENT],
            [Orders::class, 'payments.orders', Unit::COUNT],
            [Buyers::class, 'payments.buyers', Unit::COUNT],
            [AverageOrder::class, 'payments.average_order', Unit::CURRENCY],
        ];

        foreach ($erwartet as [$klasse, $handle, $unit]) {
            /** @var PaymentMetric $metrik */
            $metrik = new $klasse;

            $this->assertSame($handle, $metrik->handle());
            $this->assertSame($unit, $metrik->unit());
            $this->assertSame(__('statamic-payments::messages.metric_group'), $metrik->group());
            $this->assertNotSame('', $metrik->label());
            $this->assertNotEmpty($metrik->description());

            // The formatter cannot print money without knowing which money. The
            // gross figure carries one thing more, which is checked on its own
            // below.
            $erwarteteMeta = $unit === Unit::CURRENCY ? ['currency' => 'EUR'] : [];

            $this->assertSame(
                $erwarteteMeta,
                array_diff_key($metrik->meta($this->query()), ['line_item_sum_cent' => null]),
            );
        }
    }

    /** The screen may hand a currency down, and the meta has to follow it. */
    #[Test]
    public function the_currency_in_the_question_reaches_the_formatter(): void
    {
        $this->assertSame(
            'CHF',
            (new RevenueGross)->meta($this->query(['currency' => 'CHF']))['currency'],
        );
    }

    // -- Nothing to measure -------------------------------------------------

    /**
     * No tables, no answer — and not a zero.
     *
     * "Nothing to measure" and "measured nothing" are different statements, and
     * a zero for the first is the quiet kind of wrong: it puts a confident 0 €
     * on a dashboard for a site that has not installed the checkout at all.
     */
    #[Test]
    public function a_metric_cannot_answer_without_the_tables(): void
    {
        $this->assertTrue((new RevenueGross)->available());

        // A second, empty database rather than dropping the tables in this one.
        // Dropping them would leave the suite unable to roll its own migrations
        // back, and a test that breaks its neighbours' teardown reports the
        // wrong failure everywhere afterwards.
        config()->set('database.connections.ohne_zahlungen', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $vorher = DB::getDefaultConnection();
        DB::purge('ohne_zahlungen');
        DB::setDefaultConnection('ohne_zahlungen');

        try {
            foreach ([RevenueGross::class, RevenueNet::class, Refunded::class, RefundRate::class, Orders::class, Buyers::class, AverageOrder::class] as $klasse) {
                $this->assertFalse((new $klasse)->available(), $klasse.' answered without a payments table.');
            }
        } finally {
            DB::setDefaultConnection($vorher);
        }
    }

    // -- One currency at a time ---------------------------------------------

    /**
     * 100 EUR plus 100 CHF is not 200 of anything.
     *
     * The fixture holds 6700 in euros and 5000 in francs. A sum of 11700 would
     * be a number with no meaning that no bank statement anywhere agrees with,
     * and it is the failure a single missing `where` produces.
     */
    #[Test]
    public function two_currencies_are_never_added_together(): void
    {
        $this->fixture();

        $this->assertSame(6700, (new RevenueGross)->value($this->query()));
        $this->assertSame(3, (new Orders)->value($this->query()));

        $this->assertSame(5000, (new RevenueGross)->value($this->query(['currency' => 'CHF'])));
        $this->assertSame(1, (new Orders)->value($this->query(['currency' => 'CHF'])));
    }

    // -- The splits ---------------------------------------------------------

    /**
     * A purchase without a campaign is a row keyed null, not a missing row.
     *
     * A report that quietly excludes rows is the hardest kind of wrong to
     * notice: the columns still add up among themselves, and only the total
     * disagrees — which is the number nobody re-adds.
     */
    #[Test]
    public function a_sale_without_a_campaign_keeps_its_place_in_the_split(): void
    {
        $this->fixture();

        $zeilen = (new RevenueGross)->breakdown($this->query(), 'campaign');

        $this->assertCount(2, $zeilen);

        // Largest first: the campaign brought in 3800, the campaign-less sale 2900.
        $this->assertSame('sommer-2026', $zeilen[0]['key']);
        $this->assertSame(3800, $zeilen[0]['value']);

        $this->assertNull($zeilen[1]['key']);
        $this->assertSame(2900, $zeilen[1]['value']);
        $this->assertSame(__('statamic-payments::messages.metric_no_campaign'), $zeilen[1]['label']);

        // And the split adds up to the figure it splits.
        $this->assertSame(6700, array_sum(array_column($zeilen, 'value')));
    }

    /** The same for the source, and for a country nobody recorded. */
    #[Test]
    public function source_and_country_split_the_same_way(): void
    {
        $this->fixture();

        $this->assertSame(
            ['newsletter' => 3800, '' => 2900],
            $this->keyed((new RevenueGross)->breakdown($this->query(), 'source')),
        );

        $this->assertSame(
            ['DE' => 3800, 'AT' => 2900],
            $this->keyed((new RevenueGross)->breakdown($this->query(), 'country')),
        );
    }

    /**
     * The bump and the product it was ticked beside are two rows of one payment.
     *
     * Crediting the whole 2900 to the course would overstate it by 500 and hide
     * the bump entirely — and the bump is the thing somebody added the split to
     * find out about. The rows add up to the payments' own total, which is the
     * one check that catches a line-item sum drifting away from what was
     * actually charged.
     */
    #[Test]
    public function the_product_split_separates_the_bump_and_still_adds_up(): void
    {
        $this->fixture();

        $zeilen = (new RevenueGross)->breakdown($this->query(), 'product');

        $this->assertSame(
            ['noten-paket' => 3800, 'kurs' => 2400, 'bump' => 500],
            $this->keyed($zeilen),
        );

        $this->assertSame(6700, array_sum(array_column($zeilen, 'value')), 'the lines must add up to what was charged');

        // Through the catalogue, never the raw handle where a name exists.
        $this->assertSame('Notenpaket', $zeilen[0]['label']);

        // And a handle the catalogue does not know keeps its handle rather than
        // vanishing from the report.
        $this->assertSame('kurs', $zeilen[1]['label']);
    }

    /**
     * A payment with no line items falls back to its own handle.
     *
     * `noten-paket` is 3800 above: 1900 from the line items of the first order
     * and 1900 from the third, which has none. Without the fallback the third
     * sale would be missing from every product row while still sitting in the
     * total.
     */
    #[Test]
    public function a_payment_without_line_items_is_not_dropped_from_the_product_split(): void
    {
        $this->fixture();

        $zeilen = $this->keyed((new RevenueGross)->breakdown($this->query(), 'product'));

        $this->assertSame(3800, $zeilen['noten-paket']);
    }

    /**
     * Counting orders per product counts payments, and a payment can be in two rows.
     *
     * Deliberately not equal to the order count: an order containing two
     * products is one order and appears under both. The number answers "how
     * many orders included this", which is the question the split is for.
     */
    #[Test]
    public function the_order_split_counts_payments_not_lines(): void
    {
        $this->fixture();

        $this->assertSame(
            ['noten-paket' => 2, 'kurs' => 1, 'bump' => 1],
            $this->keyed((new Orders)->breakdown($this->query(), 'product')),
        );

        $this->assertSame(
            ['sommer-2026' => 2, '' => 1],
            $this->keyed((new Orders)->breakdown($this->query(), 'campaign')),
        );
    }

    /** A split nobody offers is empty, not an error. */
    #[Test]
    public function an_unknown_split_is_empty(): void
    {
        $this->fixture();

        $this->assertSame([], (new RevenueGross)->breakdown($this->query(), 'weather'));
        $this->assertSame([], (new Orders)->breakdown($this->query(), 'country'));

        $this->assertSame(['campaign', 'source', 'product', 'country'], array_keys((new RevenueGross)->breakdowns()));
        $this->assertSame(['campaign', 'product'], array_keys((new Orders)->breakdowns()));
    }

    /** Largest first, and no more than asked for. */
    #[Test]
    public function a_split_is_ordered_by_size_and_respects_the_limit(): void
    {
        $this->fixture();

        $zeilen = (new RevenueGross)->breakdown($this->query(), 'product', 2);

        $this->assertCount(2, $zeilen);
        $this->assertSame(['noten-paket', 'kurs'], array_column($zeilen, 'key'));

        $this->assertCount(1, (new RevenueGross)->breakdown($this->query(), 'campaign', 1));
    }

    /**
     * A line is `amount_cent * quantity - discount_cent`, and all three matter.
     *
     * Every other fixture here buys one of a thing at full price, where the
     * formula collapses to `amount_cent` and two thirds of it go unproven. Three
     * copies at 10 € with 3 € off the line is 27 €, and it has to agree with
     * what the buyer was actually charged — a split that ignored the quantity
     * would report 10 € and one that ignored the discount 30 €, and both look
     * entirely plausible on a screen.
     */
    #[Test]
    public function it_takes_quantity_and_the_line_discount_into_account(): void
    {
        $zahlung = $this->payment([
            'provider_id' => 'tr_menge',
            'product' => 'noten-paket',
            'amount_cent' => 2700,
            'paid_at' => '2026-08-16 12:00:00',
        ]);

        $this->item($zahlung, 'noten-paket', 'Notenpaket', 1000, PaymentItem::KIND_PRIMARY, 3, 300);

        $metrik = new RevenueGross;
        $frage = $this->query();

        $zeilen = $metrik->breakdown($frage, 'product');

        $this->assertSame(2700, $zeilen[0]['value'], '1000 × 3 − 300');
        $this->assertSame(3, $zeilen[0]['meta']['quantity']);

        // And it still adds up to what was charged, which is the check that
        // makes the formula more than an arbitrary arithmetic choice.
        $this->assertSame(2700, $metrik->value($frage));
        $this->assertSame(2700, $metrik->meta($frage)['line_item_sum_cent']);
    }

    /**
     * A refund on something that was never paid for is not money going back.
     *
     * The `status = 'paid'` filter in `refundedInPeriod()` is the condition that
     * refuses it, and it is easy to read as redundant — a refunded order keeps
     * its paid status, so the filter looks like it never excludes anything.
     * It excludes exactly this: a failed or cancelled checkout carrying a
     * refund amount it should never have had. Without the filter that amount
     * would be subtracted from a month's takings it was never part of.
     */
    #[Test]
    public function a_refund_on_a_payment_that_was_never_paid_is_ignored(): void
    {
        $this->fixture();

        $this->payment([
            'provider_id' => 'tr_nie_bezahlt',
            'amount_cent' => 5000,
            'status' => Payment::STATUS_FAILED,
            'paid_at' => null,
            'refunded_cent' => 5000,
            'refunded_at' => '2026-08-17 10:00:00',
        ]);

        $frage = $this->query();

        $this->assertSame(500, (new Refunded)->value($frage), 'only the refund of a real sale');
        $this->assertSame(['2026-08-19' => 500], (new Refunded)->series($frage), 'and no bucket on the 17th');
        $this->assertSame(6200, (new RevenueNet)->value($frage), 'net is untouched by it');
        $this->assertSame(7.5, (new RefundRate)->value($frage), 'and so is the rate');
    }

    // -- Over time ----------------------------------------------------------

    /**
     * Only the buckets that have something in them.
     *
     * The empty days are Insights' job — it fills the range for every metric at
     * once. A metric that filled its own would be filled twice, and a metric
     * that invented a bucket outside the range would draw a column the axis has
     * no place for.
     */
    #[Test]
    public function a_series_returns_only_the_buckets_that_have_data(): void
    {
        $this->fixture();
        $frage = $this->query();

        $this->assertSame(
            ['2026-08-15' => 4800, '2026-08-18' => 1900],
            (new RevenueGross)->series($frage),
        );

        // The refund sits on the day it went back, not on the day of the sale.
        $this->assertSame(['2026-08-19' => 500], (new Refunded)->series($frage));

        // Which is why net has a third bucket the gross series does not: a day
        // on which money only moved outwards is still a day money moved.
        $this->assertSame(
            ['2026-08-15' => 4800, '2026-08-18' => 1900, '2026-08-19' => -500],
            (new RevenueNet)->series($frage),
        );

        $this->assertSame(['2026-08-15' => 2, '2026-08-18' => 1], (new Orders)->series($frage));
        $this->assertSame(['2026-08-15' => 2, '2026-08-18' => 1], (new Buyers)->series($frage));
        $this->assertSame(['2026-08-15' => 2400, '2026-08-18' => 1900], (new AverageOrder)->series($frage));
    }

    /**
     * The grain comes from the question, not from the period.
     *
     * The report worked it out again from the period length; here Insights has
     * already decided and put it in the query. A metric that recomputed it
     * could disagree with the axis it is drawn on.
     */
    #[Test]
    public function a_monthly_question_gets_monthly_buckets(): void
    {
        $this->fixture();

        $this->assertSame(
            ['2026-08' => 6700],
            (new RevenueGross)->series($this->query([], MetricQuery::BUCKET_MONTH)),
        );
    }

    // -- Nothing sold -------------------------------------------------------

    /**
     * No orders, no average — and null rather than 0.
     *
     * "The average order was 0 €" is a statement about orders that did not
     * happen, sitting on the screen beside a count of zero that contradicts it.
     */
    #[Test]
    public function the_average_is_null_rather_than_zero_when_nothing_sold(): void
    {
        $this->fixture();

        $leer = new MetricQuery(
            Period::between(Carbon::parse('2025-01-01')->startOfDay(), Carbon::parse('2025-01-31')->endOfDay()),
        );

        $this->assertNull((new AverageOrder)->value($leer));

        // Its neighbours do answer, because "nothing was sold" is an answer to
        // what they ask.
        $this->assertSame(0, (new RevenueGross)->value($leer));
        $this->assertSame(0, (new Orders)->value($leer));
        $this->assertSame(0, (new Buyers)->value($leer));
        $this->assertSame([], (new AverageOrder)->series($leer));
    }

    // -- The refund rate ----------------------------------------------------

    /**
     * A rate against nothing is a question, not a small number.
     *
     * The period holds a refund and no sales, which is what a January order
     * repaid in March looks like from March. "0 %" would sit on the screen
     * directly beside the refunded amount that disproves it.
     */
    #[Test]
    public function the_refund_rate_is_null_when_nothing_came_in(): void
    {
        // Sold before the window, refunded inside it.
        $zahlung = $this->payment([
            'provider_id' => 'tr_alt',
            'amount_cent' => 4000,
            'paid_at' => '2026-07-02 10:00:00',
            'refunded_cent' => 4000,
            'refunded_at' => '2026-08-14 10:00:00',
        ]);

        $this->assertNotNull($zahlung->refunded_at);

        $frage = $this->query();

        $this->assertSame(0, (new RevenueGross)->value($frage), 'nothing was sold in the window');
        $this->assertSame(4000, (new Refunded)->value($frage), 'and yet money went back');
        $this->assertNull((new RefundRate)->value($frage), 'so there is no rate to state');
    }

    /**
     * Per bucket, and only where there is a denominator.
     *
     * The 19th holds the refund of the first order and, after the second sale
     * below, a sale of its own — so it goes from having no rate at all to
     * having one, which is the whole distinction under test.
     */
    #[Test]
    public function the_refund_rate_series_skips_buckets_with_no_revenue(): void
    {
        $this->fixture();

        $this->assertSame(
            ['2026-08-15' => 0.0, '2026-08-18' => 0.0],
            (new RefundRate)->series($this->query()),
            'the 19th holds only a refund and therefore no rate',
        );

        $this->payment([
            'provider_id' => 'tr_neunzehnter',
            'amount_cent' => 2000,
            'email' => 'dora@example.com',
            'paid_at' => '2026-08-19 15:00:00',
        ]);

        $this->assertSame(
            ['2026-08-15' => 0.0, '2026-08-18' => 0.0, '2026-08-19' => 25.0],
            (new RefundRate)->series($this->query()),
            '500 given back against 2000 taken in on the same day',
        );
    }

    // -- The source in the campaign label -----------------------------------

    /**
     * The campaign carries its source, but only where there is one to carry.
     *
     * The split is by campaign alone — two dimensions in one row would appear
     * under both names and add up under neither — but the source must not fall
     * out of the report on the way. It rides in the label where every payment
     * of that campaign agrees on it, and in `meta` always, so a screen can put
     * it in its own column instead.
     */
    #[Test]
    public function the_campaign_label_carries_the_source_when_it_is_unambiguous(): void
    {
        $this->fixture();

        $zeilen = (new RevenueGross)->breakdown($this->query(), 'campaign');

        $this->assertSame('sommer-2026', $zeilen[0]['key']);
        $this->assertSame('sommer-2026 · newsletter', $zeilen[0]['label']);
        $this->assertSame(['source' => 'newsletter', 'orders' => 2], $zeilen[0]['meta']);

        // A campaign-less sale has no source either, so nothing is appended.
        $this->assertNull($zeilen[1]['key']);
        $this->assertSame(__('statamic-payments::messages.metric_no_campaign'), $zeilen[1]['label']);
        $this->assertSame(['source' => null, 'orders' => 1], $zeilen[1]['meta']);
    }

    /**
     * Two channels, and the caption goes back to the bare name.
     *
     * "sommer-2026 · newsletter" on a row that is a third Instagram is a
     * caption contradicting its own number, which is worse than no caption.
     */
    #[Test]
    public function a_campaign_on_two_sources_keeps_its_bare_name(): void
    {
        $this->fixture();

        $this->payment([
            'provider_id' => 'tr_instagram',
            'amount_cent' => 1000,
            'email' => 'eva@example.com',
            'utm_campaign' => 'sommer-2026',
            'utm_source' => 'instagram',
            'paid_at' => '2026-08-16 12:00:00',
        ]);

        $zeilen = (new RevenueGross)->breakdown($this->query(), 'campaign');

        $this->assertSame('sommer-2026', $zeilen[0]['key']);
        $this->assertSame('sommer-2026', $zeilen[0]['label']);
        $this->assertNull($zeilen[0]['meta']['source']);
        $this->assertSame(3, $zeilen[0]['meta']['orders']);
        $this->assertSame(4800, $zeilen[0]['value'], 'and the campaign still adds up: 1900 + 1900 + 1000');
    }

    /** The order split names a campaign exactly as the revenue split does. */
    #[Test]
    public function both_campaign_splits_use_the_same_label(): void
    {
        $this->fixture();

        $this->assertSame(
            array_column((new RevenueGross)->breakdown($this->query(), 'campaign'), 'label'),
            array_column((new Orders)->breakdown($this->query(), 'campaign'), 'label'),
        );
    }

    // -- The line-item check ------------------------------------------------

    /**
     * What the product lines add up to, handed over so a screen can disagree.
     *
     * The payment's own amount is authoritative; the lines are a split of it.
     * On sound data the two are equal — including for the payment that has no
     * lines at all, which falls back to its own handle rather than counting as
     * a shortfall. Counting `payment_items` alone would report every legacy
     * payment as broken and the warning would cry wolf everywhere.
     */
    #[Test]
    public function the_line_item_sum_agrees_with_the_total_on_sound_data(): void
    {
        $this->fixture();

        $metrik = new RevenueGross;
        $frage = $this->query();

        $this->assertSame(6700, $metrik->value($frage));
        $this->assertSame(6700, $metrik->meta($frage)['line_item_sum_cent']);
    }

    /**
     * And disagrees the moment somebody writes lines past the checkout.
     *
     * A payment of 30 € carrying a single 10 € line: the split is 20 € short of
     * what was charged, and that gap is the whole point of reporting the
     * number. This is the check that found real broken data on the old screen.
     */
    #[Test]
    public function the_line_item_sum_diverges_when_the_lines_do_not_add_up(): void
    {
        $this->fixture();

        $kaputt = $this->payment([
            'provider_id' => 'tr_kaputt',
            'product' => 'kurs',
            'amount_cent' => 3000,
            'email' => 'frank@example.com',
            'paid_at' => '2026-08-16 12:00:00',
        ]);

        $this->item($kaputt, 'kurs', 'Kurs', 1000);

        $metrik = new RevenueGross;
        $frage = $this->query();

        $this->assertSame(9700, $metrik->value($frage), 'what was charged');
        $this->assertSame(7700, $metrik->meta($frage)['line_item_sum_cent'], 'what the lines say');
    }

    /** No line-item table, no number — rather than a zero that reads as total disagreement. */
    #[Test]
    public function the_line_item_sum_is_absent_without_the_table(): void
    {
        $this->fixture();

        // Renamed rather than dropped, and put back afterwards. A test that
        // destroys a table leaves the suite unable to roll its own migrations
        // back, and every later failure then points at the wrong place.
        Schema::rename('payment_items', 'payment_items_beiseite');

        try {
            $this->assertSame(['currency' => 'EUR'], (new RevenueGross)->meta($this->query()));

            // And the product split is empty rather than wrong.
            $this->assertSame([], (new RevenueGross)->breakdown($this->query(), 'product'));

            // The figure itself is unaffected: it never needed the lines.
            $this->assertSame(6700, (new RevenueGross)->value($this->query()));
        } finally {
            Schema::rename('payment_items_beiseite', 'payment_items');
        }
    }

    // -- What a filter may be set to ----------------------------------------

    /**
     * Which currencies exist here, busiest first.
     *
     * Not alphabetically: three euro orders against one in francs, and CHF
     * sorts before EUR in the alphabet. A screen that opened on the currency a
     * shop took once and never again would show an almost empty dashboard as
     * its front page.
     */
    #[Test]
    public function the_currencies_on_offer_are_ordered_by_how_much_was_sold_in_them(): void
    {
        $this->fixture();

        $this->assertSame(
            ['currency' => [
                ['value' => 'EUR', 'label' => 'EUR'],
                ['value' => 'CHF', 'label' => 'CHF'],
            ]],
            (new RevenueGross)->filterOptions(),
        );

        // The same question for all seven, so the answer comes from the base and
        // every metric gives it.
        foreach ([RevenueNet::class, Refunded::class, RefundRate::class, Orders::class, Buyers::class, AverageOrder::class] as $klasse) {
            $this->assertSame(
                (new RevenueGross)->filterOptions(),
                (new $klasse)->filterOptions(),
                $klasse.' offers a different choice than its siblings.',
            );
        }
    }

    /**
     * A currency nobody ever completed a payment in is not a choice.
     *
     * Offering it would build a switch that leads to an empty screen, which
     * reads as a broken report rather than as an abandoned checkout.
     */
    #[Test]
    public function a_currency_without_a_paid_payment_is_not_on_offer(): void
    {
        $this->fixture();

        $this->payment([
            'provider_id' => 'tr_dollar',
            'currency' => 'USD',
            'amount_cent' => 12000,
            'status' => Payment::STATUS_OPEN,
            'paid_at' => null,
        ]);

        $this->assertSame(
            ['EUR', 'CHF'],
            array_column((new RevenueGross)->filterOptions()['currency'], 'value'),
        );
    }

    /** Nothing taken, nothing to choose between — and no switch on the screen. */
    #[Test]
    public function there_is_no_currency_to_choose_from_before_the_first_sale(): void
    {
        $this->assertSame(['currency' => []], (new RevenueGross)->filterOptions());
    }

    // -- The wiring ---------------------------------------------------------

    /**
     * The provider hands all seven to the sibling, lazily and by handle.
     *
     * By class name rather than instance, so booting this addon does not build
     * seven metric objects on a request that renders none of them.
     */
    #[Test]
    public function the_service_provider_offers_every_metric_to_the_sibling(): void
    {
        $this->assertSame([
            'payments.revenue_gross' => RevenueGross::class,
            'payments.revenue_net' => RevenueNet::class,
            'payments.refunded' => Refunded::class,
            'payments.refund_rate' => RefundRate::class,
            'payments.orders' => Orders::class,
            'payments.buyers' => Buyers::class,
            'payments.average_order' => AverageOrder::class,
        ], $this->insights->registered);
    }

    // -- The brand ----------------------------------------------------------

    /**
     * The defect this was built for, in the smallest form that shows it.
     *
     * A tile summed four brands while the switcher said one, so the figure was
     * not merely wrong: it disclosed one customer's turnover on another's
     * screen. Checked on all three queries this class runs — the figure, the
     * split over `payments`, and the product split, which reaches the table
     * through a join and is therefore the one that slips past a filter applied
     * anywhere else.
     */
    #[Test]
    public function every_query_stops_at_the_brand_boundary(): void
    {
        $this->marke(current: 2);

        $meins = $this->verkauf('2026-08-12 10:00:00', brand: 2, cent: 1000, product: 'noten-paket');
        $this->verkauf('2026-08-13 10:00:00', brand: 3, cent: 5000, product: 'fremd-paket');
        $this->verkauf('2026-08-14 10:00:00', brand: 4, cent: 7000, product: 'fremd-paket');

        DB::table('payment_items')->insert([
            'payment_id' => $meins,
            'product' => 'noten-paket',
            'name' => 'Notenpaket',
            'amount_cent' => 1000,
            'quantity' => 1,
            'discount_cent' => 0,
            'kind' => PaymentItem::KIND_PRIMARY,
            'created_at' => '2026-08-12 10:00:00',
            'updated_at' => '2026-08-12 10:00:00',
        ]);

        $this->assertSame(1000, (new RevenueGross)->value($this->query()));
        $this->assertSame(1, (new Orders)->value($this->query()));
        $this->assertSame(
            ['noten-paket' => 1000],
            $this->keyed((new RevenueGross)->breakdown($this->query(), 'product')),
        );
    }

    /**
     * Fail closed reads zero; it does not make the metric disappear.
     *
     * Two sibling addons had answered this in `available()`, which removed
     * twelve tiles from the screen the moment a brand went unresolved. A reader
     * can understand a zero. An absence he cannot even notice.
     */
    #[Test]
    public function an_unresolved_brand_reads_zero_and_the_metric_stays(): void
    {
        $this->marke(current: null);
        $this->verkauf('2026-08-12 10:00:00', brand: 1);

        $metrik = new RevenueGross;

        $this->assertSame(0, $metrik->value($this->query()));
        $this->assertTrue($metrik->available());
    }

    #[Test]
    public function a_single_brand_install_never_sees_a_filter(): void
    {
        $this->marke(multi: false, current: null);
        $this->verkauf('2026-08-12 10:00:00', brand: 0);
        $this->verkauf('2026-08-13 10:00:00', brand: 0);

        $this->assertSame(2, (new Orders)->value($this->query()));
    }

    #[Test]
    public function a_deliberately_bypassed_scope_is_not_reapplied_here(): void
    {
        $this->marke(current: 2, disabled: true);
        $this->verkauf('2026-08-12 10:00:00', brand: 2);
        $this->verkauf('2026-08-13 10:00:00', brand: 3);

        $this->assertSame(2, (new Orders)->value($this->query()));
    }

    #[Test]
    public function without_brand_context_nothing_is_filtered(): void
    {
        $this->assertFalse($this->app->bound('brand-context'));

        $this->verkauf('2026-08-12 10:00:00', brand: 2);
        $this->verkauf('2026-08-13 10:00:00', brand: 3);

        $this->assertSame(2, (new Orders)->value($this->query()));
    }

    // -- The edge of the window ---------------------------------------------

    /**
     * The last second of the period is inside the period.
     *
     * `Period::to` is 23:59:59.999999, and a binding formats it as
     * `Y-m-d H:i:s` — the fraction is dropped. Against `<=` on a column that
     * stores milliseconds, every sale in the final second therefore vanished:
     * wrong on SQLite always, accidentally right on a plain MySQL timestamp
     * column, and invisible in both. No green suite could have shown it,
     * because no test wrote a fractional second. This one does.
     */
    #[Test]
    public function a_sale_in_the_last_fraction_of_the_period_still_counts(): void
    {
        $this->verkauf('2026-08-20 23:59:59.500', brand: 0);
        $this->verkauf('2026-08-20 12:00:00', brand: 0);

        $this->assertSame(2, (new Orders)->value($this->query()));
    }

    #[Test]
    public function a_sale_one_moment_after_the_period_does_not(): void
    {
        $this->verkauf('2026-08-21 00:00:00', brand: 0);

        $this->assertSame(0, (new Orders)->value($this->query()));
    }
}
