<?php

namespace Goldnead\StatamicPayments\Console\Commands;

use Goldnead\StatamicPayments\Support\BrandBackfill;
use Goldnead\StatamicPayments\Support\BrandBackfillReport;
use Goldnead\StatamicPayments\Support\Brands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Repair the brand of rows an earlier migration guessed.
 *
 * The migration that added `brand_id` first shipped with a backfill that wrote
 * the lowest brand id onto every existing payment and subscription. It is
 * committed, and it has already run on more than one install — where it ran it
 * will never run again, and the rows are sitting on the wrong brand rather than
 * on zero. Fixing the migration therefore fixes nobody who already migrated.
 * This does.
 *
 * **Same derivation, different trigger.** It runs {@see BrandBackfill}, the one
 * the migration now runs, and writes a row only where a derived source
 * *contradicts* what the row says. A row nothing can be derived for keeps
 * whatever it has: the absence of evidence is not evidence, and a repair tool
 * that reset rows to zero on principle would take the answer away from every
 * install that had stamped its rows correctly.
 *
 * `--dry-run` first. Without it the run writes.
 */
class BackfillBrands extends Command
{
    protected $signature = 'payments:brand-backfill {--dry-run : Nur zeigen, nichts schreiben}';

    protected $description = 'Die Marke von Zahlungen und Abos ableiten, die eine frühere Migration geraten hat.';

    /** At most this many rows in the table; the counts below say the whole truth. */
    private const SHOW = 40;

    public function handle(): int
    {
        if (! BrandBackfill::possible()) {
            $this->components->info($this->whyNot());

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $report = (new BrandBackfill)->correct(dryRun: $dryRun);
        } catch (Throwable $e) {
            $this->components->error('Die Ableitung ist gescheitert, es wurde nichts geschrieben: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->showChanges($report);
        $this->showLeftovers($report);

        return self::SUCCESS;
    }

    /**
     * Why there is nothing to do, in the words of the reason.
     *
     * A command that printed "0 Zeilen" on an install without `brand-context`
     * would read like an all-clear on a question that was never asked.
     */
    private function whyNot(): string
    {
        if (! Brands::available()) {
            return 'Ohne goldnead/statamic-brand-context gibt es nur eine Marke. Jede Zeile steht auf 0, und das ist richtig.';
        }

        return match (Brands::mode()) {
            Brands::SINGLE => 'Dieser Betrieb führt nur eine Marke (brand-context.multi_brand ist aus). Jede Zeile steht auf 0, und das ist richtig.',
            Brands::UNKNOWN => 'brand-context sagt nicht, ob dieser Betrieb mehrere Marken führt. Solange das so ist, wird hier nichts abgeleitet.',
            default => 'Die Tabellen payments/subscriptions oder die Spalte brand_id fehlen noch. Erst die Migrationen laufen lassen.',
        };
    }

    private function showChanges(BrandBackfillReport $report): void
    {
        if ($report->changedCount() === 0) {
            $this->components->info('Keine Zeile, der eine ableitbare Quelle widerspricht. Nichts zu korrigieren.');

            return;
        }

        $marken = $this->brandNames();

        $this->newLine();
        $this->table(
            ['Tabelle', 'Zeile', 'steht auf', 'gehört zu', 'abgeleitet aus'],
            array_map(fn (array $change) => [
                $change['table'],
                (string) $change['id'],
                $this->brand($change['from'], $marken),
                $this->brand($change['to'], $marken),
                $change['source'],
            ], array_slice($report->changes, 0, self::SHOW))
        );

        if ($report->changedCount() > self::SHOW) {
            $this->line('  … und '.($report->changedCount() - self::SHOW).' weitere.');
        }

        $this->newLine();

        foreach ($report->countsBySource() as $source => $count) {
            $this->line('  '.str_pad($source, 22).$count);
        }

        $this->newLine();

        if ($report->dryRun) {
            $this->components->warn(
                $report->changedCount().' Zeilen würden korrigiert. Geschrieben wurde nichts — '
                .'ohne --dry-run noch einmal laufen lassen.'
            );

            return;
        }

        $this->components->info($report->changedCount().' Zeilen tragen jetzt die Marke, die verkauft hat.');
    }

    /**
     * The rows nothing could say anything about.
     *
     * Named, not swallowed. They stay on `0`, the customer portal shows them to
     * nobody, and that is a decision somebody has to make row by row — most
     * often by looking at what the payment bought.
     */
    private function showLeftovers(BrandBackfillReport $report): void
    {
        foreach ($report->ambiguous as $case) {
            $this->components->warn($case['table'].' '.$case['id'].': '.$case['reason'].'. Bleibt, wie sie ist.');
        }

        foreach ($report->missed as $case) {
            // The conditional UPDATE matched nothing: somebody answered the row
            // between the read and the write. Their answer stands, and it is
            // subtracted from the count above rather than claimed.
            $this->components->warn(
                $case['table'].' '.$case['id'].' hat sich während des Laufs verändert (erwartet '.$case['expected']
                .', gefunden '.$case['found'].'). Nicht angefasst.'
            );
        }

        $this->showUnconfirmed($report);

        if ($report->stillZeroCount() === 0) {
            return;
        }

        $teile = [];

        foreach ($report->stillZero as $table => $count) {
            $teile[] = $count.' '.$table;
        }

        $default = Brands::defaultId();
        $marken = $this->brandNames();

        $this->components->warn(
            implode(', ', $teile).' stehen auf 0 und lassen sich aus nichts ableiten: keine Rechnung, '
            .'kein Abo, keine Zeile, zu der sie gehören. Sie wurden absichtlich nicht auf die '
            .'Standardmarke '.$this->brand($default, $marken).' geschrieben — 0 heißt „gehört keiner Marke" '
            .'und wird im Kundenbereich fail-closed behandelt.'
        );
    }

    /**
     * Rows carrying a brand nothing could check.
     *
     * The blind spot, and the reason it is printed: where the guessing
     * migration already ran, these are the rows still wearing its answer. This
     * command may not touch them — no source contradicts them, and "no
     * evidence" is not evidence — so the only thing it can do for them is say
     * how many there are.
     */
    private function showUnconfirmed(BrandBackfillReport $report): void
    {
        if ($report->unconfirmedCount() === 0) {
            return;
        }

        $teile = [];

        foreach ($report->unconfirmed as $table => $count) {
            $teile[] = $count.' '.$table;
        }

        $this->components->info(
            implode(', ', $teile).' tragen eine Marke, die sich aus nichts nachprüfen lässt — keine Rechnung, '
            .'kein Abo, keine Zeile, zu der sie gehören. Sie bleiben, wie sie sind. Wo die alte Migration '
            .'geraten hat, steht in diesen Zeilen noch ihre Antwort.'
        );
    }

    /**
     * Brand ids in something a person recognises.
     *
     * Read straight off the table rather than through the sibling's model: the
     * label is not worth an import that would tie this command to a package
     * version. Without the table it stays a number.
     *
     * @return array<int, string>
     */
    private function brandNames(): array
    {
        try {
            if (! Schema::hasTable('brands')) {
                return [];
            }

            return DB::table('brands')->pluck('handle', 'id')
                ->map(fn ($handle) => (string) $handle)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @param  array<int, string>  $marken */
    private function brand(int $id, array $marken): string
    {
        if ($id === 0) {
            return '0 (keine Marke)';
        }

        return isset($marken[$id]) ? $id.' ('.$marken[$id].')' : (string) $id;
    }
}
