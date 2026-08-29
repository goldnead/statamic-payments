<?php

namespace Goldnead\StatamicPayments\Support;

/**
 * What one backfill run found, and what it refused to answer.
 *
 * A separate object rather than a return array because the interesting number
 * is not "how many rows were written" but **how many were left alone** — and a
 * count that nobody printed is a count that nobody looked at. The migration
 * logs it, the console command prints it, and both read the same fields.
 */
final class BrandBackfillReport
{
    /**
     * @param  list<array{table: string, id: int, from: int, to: int, source: string}>  $changes
     * @param  array<string, int>  $stillZero  table => rows that are zero and stayed zero
     * @param  array<string, int>  $unconfirmed  table => rows carrying a brand that nothing could check
     * @param  list<array{table: string, id: int, reason: string}>  $ambiguous
     * @param  list<array{table: string, id: int, expected: int, found: int}>  $missed  rows somebody answered mid-run
     */
    public function __construct(
        public readonly array $changes,
        public readonly array $stillZero,
        public readonly array $unconfirmed,
        public readonly array $ambiguous,
        public readonly array $missed = [],
        public readonly bool $dryRun = true,
    ) {}

    /** An install this does not apply to: no tenants, or no sibling to ask. */
    public static function notApplicable(): self
    {
        return new self([], [], [], []);
    }

    /**
     * How many rows each derivation route accounts for.
     *
     * @return array<string, int>
     */
    public function countsBySource(): array
    {
        $counts = [];

        foreach ($this->changes as $change) {
            $counts[$change['source']] = ($counts[$change['source']] ?? 0) + 1;
        }

        return $counts;
    }

    public function changedCount(): int
    {
        return count($this->changes);
    }

    public function stillZeroCount(): int
    {
        return array_sum($this->stillZero);
    }

    /**
     * Rows that keep a brand nothing was able to confirm.
     *
     * The number the repair command would otherwise leave unsaid. Where the
     * broken migration ran, these are exactly the rows still carrying its
     * guess — no invoice, no agreement, no parent row said otherwise, so the
     * command left them alone, and somebody should know how many that is.
     */
    public function unconfirmedCount(): int
    {
        return array_sum($this->unconfirmed);
    }
}
