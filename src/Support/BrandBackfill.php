<?php

namespace Goldnead\StatamicPayments\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Which brand sold a row that was written before the column existed.
 *
 * **Derived, never guessed.** The first version of the `brand_id` migration
 * took `DB::table('brands')->orderBy('id')->value('id')` and stamped it onto
 * every existing payment and subscription. On the demo playground that turned
 * eleven payments into "nordlicht" and `invoices:brand-check` found seven
 * invoices sitting in a different brand's number series than the payment they
 * belong to. The invoices were right. Since `goldnead/statamic-invoices`
 * `0d66f59` the invoice writer reads this very column, so the guess was about
 * to be handed forward into new documents.
 *
 * The truth is recoverable for most rows, it was simply never looked for. Three
 * routes, strongest first:
 *
 * 1. **A payment with an invoice** takes the invoice's brand. The invoice
 *    recorded the selling brand at the moment it was written, and it recorded
 *    it in a number it can never take back.
 * 2. **A subscription** takes the brand of its first payment.
 * 3. **A payment that belongs to a row** — a subscription cycle, a follow-up
 *    charge — takes the brand of the row it belongs to.
 *
 * Applied to a fixed point, because route 2 feeds route 3 and the other way
 * round: a cycle resolved from its agreement can be the first payment that
 * resolves a second agreement.
 *
 * **What is left over stays at zero.** Zero already means "belongs to no
 * brand", the customer portal treats it fail-closed ({@see Brands::only()}),
 * and a reported gap is worth more than a quiet wrong answer. Nothing here
 * ever writes {@see Brands::defaultId()}.
 *
 * One class, two callers: the migration ({@see self::fillGaps()}) and
 * `payments:brand-backfill` ({@see self::correct()}). The derivation must not
 * exist twice, or the repair and the thing it repairs will drift.
 */
final class BrandBackfill
{
    public const PAYMENTS = 'payments';

    public const SUBSCRIPTIONS = 'subscriptions';

    /** The payment's own invoice said so. */
    public const FROM_INVOICE = 'rechnung';

    /** The agreement took the brand of the first payment made against it. */
    public const FROM_FIRST_PAYMENT = 'erste-zahlung';

    /** A cycle or a follow-up charge took the brand of the row it belongs to. */
    public const FROM_RELATED_ROW = 'zugehoerige-zeile';

    /**
     * A ceiling on the fixed point.
     *
     * `parent_payment_id` is a link a host could in principle make circular,
     * and a backfill that spins forever inside a migration is worse than one
     * that stops early with rows left at zero.
     */
    private const MAX_PASSES = 20;

    /** Ids per `whereIn`. SQLite's variable limit is the binding constraint. */
    private const CHUNK = 500;

    /** @var array<int, int> payment id => stored brand id */
    private array $paymentBrand = [];

    /** @var array<int, int> payment id => subscription it is a cycle of */
    private array $paymentSubscription = [];

    /** @var array<int, int> payment id => payment it follows */
    private array $paymentParent = [];

    /** @var array<int, int> subscription id => stored brand id */
    private array $subscriptionBrand = [];

    /** @var array<int, int> subscription id => lowest payment id charged against it */
    private array $firstPaymentOf = [];

    /** @var array<int, list<int>> subscription id => every payment charged against it, by id */
    private array $paymentsOf = [];

    /** @var array<int, true> brand ids that actually exist */
    private array $brandIds = [];

    /** @var array<int, int> payment id => brand id its invoice says */
    private array $invoiceBrand = [];

    /** @var array<int, true> payment id => two invoices, two brands, no answer */
    private array $invoiceConflict = [];

    /** @var list<array{table: string, id: int, reason: string}> */
    private array $ambiguous = [];

    private bool $loaded = false;

    /**
     * Whether there is a question here at all.
     *
     * Four ways there is not, and none of them is an error:
     *
     * - `brand-context` is not installed. Then this install has one seller,
     *   every row is zero, and zero is the right answer. This is the great
     *   majority of installs and the reason the column has no foreign key.
     * - It is installed but multi-brand is off. Same outcome for the same
     *   reason: {@see Brands::stampId()} writes zero on new rows too, so a
     *   backfill would put the old rows out of step with the new ones.
     * - It would not say ({@see Brands::UNKNOWN}). Then nothing may be
     *   inferred, least of all by a batch job.
     * - The tables or the column are not there yet.
     */
    public static function possible(): bool
    {
        if (Brands::mode() !== Brands::MULTI) {
            return false;
        }

        if (! Schema::hasTable('brands')) {
            return false;
        }

        foreach ([self::PAYMENTS, self::SUBSCRIPTIONS] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'brand_id')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fill rows that have no brand at all. The migration's half.
     *
     * Only rows standing at zero are touched, and the `UPDATE` says so as well
     * as the `SELECT` did: a row that acquired a brand between the read and the
     * write is a row somebody answered, and an answer is not a gap.
     */
    public function fillGaps(bool $dryRun = false): BrandBackfillReport
    {
        return $this->run(onlyGaps: true, dryRun: $dryRun);
    }

    /**
     * Correct rows a stronger source contradicts. The repair command's half.
     *
     * The one deliberate exception to "a set brand is an answer". The broken
     * migration is committed and has already run on more than one install;
     * there the rows do not stand at zero, they stand at the wrong brand, and
     * nothing but this will move them. It still only writes where a *derived*
     * source disagrees — a row nothing can be derived for keeps whatever it
     * has, because "I have no evidence" is not evidence.
     */
    public function correct(bool $dryRun = true): BrandBackfillReport
    {
        return $this->run(onlyGaps: false, dryRun: $dryRun);
    }

    /**
     * What each row would get, from where, and whether anything backs it.
     *
     * **`proven` is the load-bearing field.** An answer is proven when its
     * chain starts at an invoice — the only source outside these two tables,
     * and the only one the guessing backfill could not have written. An answer
     * is unproven when somewhere along the chain it fell back on a `brand_id`
     * that is simply already in the row: a related row saying "three" is worth
     * repeating onto a row saying nothing, and worth nothing at all against a
     * row that says "four". Without the distinction the repair command pulls a
     * hand-corrected agreement back onto the brand its first payment was
     * guessed into, and calls that a correction.
     *
     * @return array{payments: array<int, array{brand: int, source: string, proven: bool}>, subscriptions: array<int, array{brand: int, source: string, proven: bool}>}
     */
    public function derive(): array
    {
        $this->load();

        /** @var array<int, array{brand: int, source: string, proven: bool}> $payments */
        $payments = [];

        /** @var array<int, array{brand: int, source: string, proven: bool}> $subscriptions */
        $subscriptions = [];

        // Route 1 is the only one that does not depend on another row, so it
        // seeds the loop and is the only place `proven` starts true. An invoice
        // on brand zero says nothing — it is the same missing answer one table
        // further along, not a second opinion.
        foreach ($this->invoiceBrand as $paymentId => $brandId) {
            if (! isset($this->paymentBrand[$paymentId])) {
                continue;
            }

            $payments[$paymentId] = ['brand' => $brandId, 'source' => self::FROM_INVOICE, 'proven' => true];
        }

        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $changed = false;

            foreach (array_keys($this->subscriptionBrand) as $id) {
                $changed = $this->deriveSubscription($id, $payments, $subscriptions) || $changed;
            }

            foreach (array_keys($this->paymentBrand) as $id) {
                $changed = $this->derivePayment($id, $payments, $subscriptions) || $changed;
            }

            if (! $changed) {
                break;
            }
        }

        return ['payments' => $payments, 'subscriptions' => $subscriptions];
    }

    /**
     * Route 2. The agreement carried no brand, but money charged against it did.
     *
     * The ticket says "the brand of its first payment", and where the first
     * payment has an invoice that is exactly what happens. Where it has none
     * and a later charge does, the later one answers instead: the evidence is
     * no weaker for arriving second, and refusing it would leave the agreement
     * on zero and block every cycle behind it. Two charges of one agreement
     * invoiced under two brands is a question for a person.
     *
     * @param  array<int, array{brand: int, source: string, proven: bool}>  $payments
     * @param  array<int, array{brand: int, source: string, proven: bool}>  $subscriptions
     */
    private function deriveSubscription(int $id, array $payments, array &$subscriptions): bool
    {
        if (isset($subscriptions[$id]) && $subscriptions[$id]['proven']) {
            return false;
        }

        if (! isset($this->firstPaymentOf[$id])) {
            return false;
        }

        $proven = [];

        foreach ($this->paymentsOf[$id] ?? [] as $paymentId) {
            $known = $this->knownPaymentBrand($paymentId, $payments);

            if ($known !== null && $known['proven']) {
                $proven[$known['brand']] = true;
            }
        }

        if (count($proven) > 1) {
            $this->noteAmbiguity(self::SUBSCRIPTIONS, $id, 'seine Zahlungen sind unter verschiedenen Marken abgerechnet');

            return false;
        }

        if ($proven !== []) {
            return $this->remember($subscriptions, $id, (int) array_key_first($proven), self::FROM_FIRST_PAYMENT, true);
        }

        // Nothing proven anywhere. The first payment's own answer then, which
        // is worth repeating onto a row that says nothing and nothing more.
        $known = $this->knownPaymentBrand($this->firstPaymentOf[$id], $payments);

        if ($known === null) {
            return false;
        }

        return $this->remember($subscriptions, $id, $known['brand'], self::FROM_FIRST_PAYMENT, false);
    }

    /**
     * Route 3. A cycle or a follow-up charge belongs to a row.
     *
     * A webhook is precisely where no brand was current, which is why these are
     * the rows that ended up at zero in the first place.
     *
     * @param  array<int, array{brand: int, source: string, proven: bool}>  $payments
     * @param  array<int, array{brand: int, source: string, proven: bool}>  $subscriptions
     */
    private function derivePayment(int $id, array &$payments, array $subscriptions): bool
    {
        if (isset($payments[$id]) && $payments[$id]['proven']) {
            return false;
        }

        if (isset($this->invoiceConflict[$id])) {
            return false;
        }

        $candidates = [];

        if (isset($this->paymentSubscription[$id])) {
            $candidates[] = $this->knownSubscriptionBrand($this->paymentSubscription[$id], $subscriptions);
        }

        if (isset($this->paymentParent[$id])) {
            $candidates[] = $this->knownPaymentBrand($this->paymentParent[$id], $payments);
        }

        $candidates = array_values(array_filter($candidates));

        if ($candidates === []) {
            return false;
        }

        if (count(array_unique(array_column($candidates, 'brand'))) > 1) {
            // It follows a payment of one brand and belongs to an agreement of
            // another. Somebody has to look at that; a batch job picking the
            // lower id would bury it.
            $this->noteAmbiguity(self::PAYMENTS, $id, 'gehört zu Zeilen zweier verschiedener Marken');

            return false;
        }

        return $this->remember(
            $payments,
            $id,
            $candidates[0]['brand'],
            self::FROM_RELATED_ROW,
            in_array(true, array_column($candidates, 'proven'), true),
        );
    }

    /**
     * Write an answer into the map, and say whether that moved anything.
     *
     * Monotone on purpose: an answer is set once and afterwards may only be
     * upgraded from unproven to proven. That is what makes the fixed point
     * terminate rather than oscillate between two equally weak answers.
     *
     * @param  array<int, array{brand: int, source: string, proven: bool}>  $map
     */
    private function remember(array &$map, int $id, int $brandId, string $source, bool $proven): bool
    {
        if (isset($map[$id]) && ! ($proven && ! $map[$id]['proven'])) {
            return false;
        }

        $map[$id] = ['brand' => $brandId, 'source' => $source, 'proven' => $proven];

        return true;
    }

    /** @return list<array{table: string, id: int, reason: string}> */
    public function ambiguities(): array
    {
        $this->load();

        return $this->ambiguous;
    }

    private function run(bool $onlyGaps, bool $dryRun): BrandBackfillReport
    {
        if (! self::possible()) {
            return BrandBackfillReport::notApplicable();
        }

        $derived = $this->derive();

        /** @var list<array{table: string, id: int, from: int, to: int, source: string}> $changes */
        $changes = [];

        // Grouped by the pair (what it says now, what it should say) so the
        // write can carry its own precondition. Reading a row and then writing
        // it unconditionally is how a concurrent checkout gets overwritten by a
        // console command that read the table a second earlier.
        /** @var array<string, array<int, array<int, list<int>>>> $updates */
        $updates = [];

        foreach ($derived['payments'] as $id => $answer) {
            $current = $this->paymentBrand[$id] ?? 0;

            if ($current === $answer['brand'] || ($onlyGaps && $current !== 0)) {
                continue;
            }

            // An unproven answer may fill a gap and may never overrule an
            // answer. Both rows would then be carrying a value nothing outside
            // these two tables backs, and the newer one is not the better one.
            if (! $answer['proven'] && $current !== 0) {
                continue;
            }

            $changes[] = ['table' => self::PAYMENTS, 'id' => $id, 'from' => $current, 'to' => $answer['brand'], 'source' => $answer['source']];
            $updates[self::PAYMENTS][$current][$answer['brand']][] = $id;
        }

        foreach ($derived['subscriptions'] as $id => $answer) {
            $current = $this->subscriptionBrand[$id] ?? 0;

            if ($current === $answer['brand'] || ($onlyGaps && $current !== 0)) {
                continue;
            }

            if (! $answer['proven'] && $current !== 0) {
                continue;
            }

            $changes[] = ['table' => self::SUBSCRIPTIONS, 'id' => $id, 'from' => $current, 'to' => $answer['brand'], 'source' => $answer['source']];
            $updates[self::SUBSCRIPTIONS][$current][$answer['brand']][] = $id;
        }

        $missed = $dryRun ? [] : $this->write($updates);

        if ($missed !== []) {
            // Somebody answered the row between the read and the write. The
            // row is theirs; it is only reported, never overwritten.
            $changes = array_values(array_filter(
                $changes,
                fn (array $change) => ! $this->wasMissed($change, $missed)
            ));
        }

        return new BrandBackfillReport(
            changes: $changes,
            stillZero: $this->stillZero($changes, $dryRun),
            unconfirmed: $this->unconfirmed($derived),
            ambiguous: $this->ambiguous,
            missed: $missed,
            dryRun: $dryRun,
        );
    }

    /**
     * @param  array<string, array<int, array<int, list<int>>>>  $updates
     * @return list<array{table: string, id: int, expected: int, found: int}> rows that moved underneath
     */
    private function write(array $updates): array
    {
        /** @var array<string, array<int, int>> $intended */
        $intended = [];

        foreach ($updates as $table => $byCurrent) {
            foreach ($byCurrent as $current => $byTarget) {
                foreach ($byTarget as $target => $ids) {
                    foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                        // The precondition travels with the write. Reading a
                        // row and then writing it unconditionally is how a
                        // console command overwrites a checkout that finished
                        // while it was thinking.
                        DB::table($table)
                            ->whereIn('id', $chunk)
                            ->where('brand_id', $current)
                            ->update(['brand_id' => $target]);
                    }

                    foreach ($ids as $id) {
                        $intended[$table][$id] = $target;
                    }
                }
            }
        }

        return $this->reread($intended);
    }

    /**
     * Read back what the writes actually produced.
     *
     * A conditional `UPDATE` can match nothing, and assuming otherwise would
     * make the summary claim a row it never moved. So the in-memory picture is
     * refreshed from the database rather than from intent, and anything that
     * did not land is handed back to be said out loud.
     *
     * @param  array<string, array<int, int>>  $intended
     * @return list<array{table: string, id: int, expected: int, found: int}>
     */
    private function reread(array $intended): array
    {
        $missed = [];

        foreach ($intended as $table => $targets) {
            foreach (array_chunk(array_keys($targets), self::CHUNK) as $chunk) {
                foreach (DB::table($table)->select(['id', 'brand_id'])->whereIn('id', $chunk)->cursor() as $row) {
                    $id = (int) $row->id;
                    $found = (int) $row->brand_id;

                    if ($table === self::PAYMENTS) {
                        $this->paymentBrand[$id] = $found;
                    } else {
                        $this->subscriptionBrand[$id] = $found;
                    }

                    if ($found !== $targets[$id]) {
                        $missed[] = ['table' => $table, 'id' => $id, 'expected' => $targets[$id], 'found' => $found];
                    }
                }
            }
        }

        return $missed;
    }

    /**
     * Rows that were zero and are still zero once this run is done.
     *
     * The number the report exists for. Counted against what the run actually
     * wrote, so a dry run says what *would* remain rather than what does.
     *
     * @param  list<array{table: string, id: int, from: int, to: int, source: string}>  $changes
     * @return array<string, int>
     */
    private function stillZero(array $changes, bool $dryRun): array
    {
        $written = [];

        foreach ($changes as $change) {
            $written[$change['table']][$change['id']] = true;
        }

        $counts = [];

        $payments = $this->countZeros($this->paymentBrand, $written[self::PAYMENTS] ?? [], $dryRun);
        $subscriptions = $this->countZeros($this->subscriptionBrand, $written[self::SUBSCRIPTIONS] ?? [], $dryRun);

        if ($payments > 0) {
            $counts[self::PAYMENTS] = $payments;
        }

        if ($subscriptions > 0) {
            $counts[self::SUBSCRIPTIONS] = $subscriptions;
        }

        return $counts;
    }

    /**
     * @param  array<int, int>  $stored
     * @param  array<int, true>  $written
     */
    private function countZeros(array $stored, array $written, bool $dryRun): int
    {
        $count = 0;

        foreach ($stored as $id => $brandId) {
            // After a real write the picture was read back from the database,
            // so a zero here is a zero that survived. In a dry run nothing
            // moved and the pending change has to be subtracted by hand.
            if ($brandId !== 0) {
                continue;
            }

            if ($dryRun && isset($written[$id])) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    /**
     * Rows that carry a brand no route could check.
     *
     * Not an error and not a gap — a blind spot, and it has to be counted
     * because on an install where the guessing migration already ran these are
     * the rows still wearing its answer. Nothing here may move them; only a
     * person looking at what was bought can.
     *
     * An **unproven** answer does not clear a row from this count, and that is
     * the whole reason `proven` is carried around: a guess that reached the row
     * by way of its neighbour is the same guess. Counting it as confirmed would
     * let the propagation quietly report the uncertainty away.
     *
     * @param  array{payments: array<int, array{brand: int, source: string, proven: bool}>, subscriptions: array<int, array{brand: int, source: string, proven: bool}>}  $derived
     * @return array<string, int>
     */
    private function unconfirmed(array $derived): array
    {
        $counts = [];

        foreach ([
            self::PAYMENTS => [$this->paymentBrand, $derived['payments']],
            self::SUBSCRIPTIONS => [$this->subscriptionBrand, $derived['subscriptions']],
        ] as $table => [$stored, $answers]) {
            $count = 0;

            foreach ($stored as $id => $brandId) {
                if ($brandId !== 0 && ! ($answers[$id]['proven'] ?? false)) {
                    $count++;
                }
            }

            if ($count > 0) {
                $counts[$table] = $count;
            }
        }

        return $counts;
    }

    /**
     * The brand of a payment, as far as anything knows, and how well.
     *
     * Derived first, then whatever the row already carries. A stored value is
     * good enough to *propagate* onto a row that says nothing — a cycle whose
     * agreement says brand three belongs to brand three — and it is never good
     * enough to overrule a row that already says something, which is what the
     * `proven` flag it comes back with is for.
     *
     * @param  array<int, array{brand: int, source: string, proven: bool}>  $payments
     * @return array{brand: int, proven: bool}|null
     */
    private function knownPaymentBrand(int $id, array $payments): ?array
    {
        if (isset($payments[$id])) {
            return ['brand' => $payments[$id]['brand'], 'proven' => $payments[$id]['proven']];
        }

        $stored = $this->paymentBrand[$id] ?? 0;

        return $stored > 0 && isset($this->brandIds[$stored]) ? ['brand' => $stored, 'proven' => false] : null;
    }

    /**
     * @param  array<int, array{brand: int, source: string, proven: bool}>  $subscriptions
     * @return array{brand: int, proven: bool}|null
     */
    private function knownSubscriptionBrand(int $id, array $subscriptions): ?array
    {
        if (isset($subscriptions[$id])) {
            return ['brand' => $subscriptions[$id]['brand'], 'proven' => $subscriptions[$id]['proven']];
        }

        $stored = $this->subscriptionBrand[$id] ?? 0;

        return $stored > 0 && isset($this->brandIds[$stored]) ? ['brand' => $stored, 'proven' => false] : null;
    }

    /**
     * @param  array{table: string, id: int, from: int, to: int, source: string}  $change
     * @param  list<array{table: string, id: int, expected: int, found: int}>  $missed
     */
    private function wasMissed(array $change, array $missed): bool
    {
        foreach ($missed as $one) {
            if ($one['table'] === $change['table'] && $one['id'] === $change['id']) {
                return true;
            }
        }

        return false;
    }

    private function noteAmbiguity(string $table, int $id, string $reason): void
    {
        foreach ($this->ambiguous as $known) {
            if ($known['table'] === $table && $known['id'] === $id) {
                return;
            }
        }

        $this->ambiguous[] = ['table' => $table, 'id' => $id, 'reason' => $reason];
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        foreach (DB::table('brands')->select('id')->cursor() as $row) {
            $this->brandIds[(int) $row->id] = true;
        }

        $hasSubscriptionId = Schema::hasColumn(self::PAYMENTS, 'subscription_id');
        $hasParentId = Schema::hasColumn(self::PAYMENTS, 'parent_payment_id');

        $columns = array_values(array_filter([
            'id',
            'brand_id',
            $hasSubscriptionId ? 'subscription_id' : null,
            $hasParentId ? 'parent_payment_id' : null,
        ]));

        // Ordered by id, so "the first payment of an agreement" is decided by
        // the same rule everywhere. `paid_at` would be the more meaningful
        // order and is null on exactly the rows that need this most.
        foreach (DB::table(self::PAYMENTS)->select($columns)->orderBy('id')->cursor() as $row) {
            $id = (int) $row->id;

            $this->paymentBrand[$id] = (int) $row->brand_id;

            $subscriptionId = $hasSubscriptionId ? (int) ($row->subscription_id ?? 0) : 0;
            $parentId = $hasParentId ? (int) ($row->parent_payment_id ?? 0) : 0;

            if ($subscriptionId > 0) {
                $this->paymentSubscription[$id] = $subscriptionId;
                $this->firstPaymentOf[$subscriptionId] ??= $id;
                $this->paymentsOf[$subscriptionId][] = $id;
            }

            if ($parentId > 0 && $parentId !== $id) {
                $this->paymentParent[$id] = $parentId;
            }
        }

        foreach (DB::table(self::SUBSCRIPTIONS)->select(['id', 'brand_id'])->orderBy('id')->cursor() as $row) {
            $this->subscriptionBrand[(int) $row->id] = (int) $row->brand_id;
        }

        $this->loadInvoices();
    }

    /**
     * The invoice hint, and it is a hint.
     *
     * `goldnead/statamic-invoices` is a `suggest`, not a `require`. This package
     * must install and migrate on a host that has never heard of it, so the
     * table is asked for by name through `Schema::hasTable()` and read as raw
     * rows. **No model, no facade, no import** — anything that named a class in
     * that package would turn a suggestion into a dependency and break every
     * install without it. The direction of the real dependency is the other way
     * round: invoices requires payments, and `invoices:brand-check` reads
     * `Payment` on purpose.
     *
     * A brand this install does not have is not an answer either. That is a
     * database restored from somewhere else, and a backfill is not the place to
     * find out.
     */
    private function loadInvoices(): void
    {
        try {
            if (! Schema::hasTable('invoices')) {
                return;
            }

            foreach (['payment_id', 'brand_id'] as $column) {
                if (! Schema::hasColumn('invoices', $column)) {
                    return;
                }
            }

            // The loop is inside the `try` on purpose: `cursor()` is lazy, so
            // the query does not run until the `foreach`, and a `try` that
            // ended here would have guarded the schema lookups and nothing
            // else — which is the half that never fails.
            $rows = DB::table('invoices')
                ->select(['payment_id', 'brand_id'])
                ->whereNotNull('payment_id')
                ->orderBy('id')
                ->cursor();

            $this->readInvoices($rows);
        } catch (Throwable) {
            // A table of that name belonging to something else entirely, or a
            // connection that will not describe itself. Neither is a reason to
            // fail a migration that has real work to do without it.
        }
    }

    /**
     * @param  iterable<int, object>  $rows
     */
    private function readInvoices(iterable $rows): void
    {
        foreach ($rows as $row) {
            $paymentId = (int) $row->payment_id;
            $brandId = (int) $row->brand_id;

            if ($brandId <= 0 || ! isset($this->brandIds[$brandId])) {
                continue;
            }

            if (isset($this->invoiceConflict[$paymentId])) {
                // Already contradictory. A third document cannot settle it.
                continue;
            }

            if (! isset($this->invoiceBrand[$paymentId])) {
                $this->invoiceBrand[$paymentId] = $brandId;

                continue;
            }

            if ($this->invoiceBrand[$paymentId] !== $brandId) {
                // An invoice and its credit note in two different series. The
                // strongest source contradicts itself, so this payment has no
                // answer at all and must not fall through to a weaker one.
                unset($this->invoiceBrand[$paymentId]);
                $this->invoiceConflict[$paymentId] = true;
                $this->noteAmbiguity(self::PAYMENTS, $paymentId, 'zwei Rechnungen mit verschiedenen Marken');
            }
        }
    }
}
