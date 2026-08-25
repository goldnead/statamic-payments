<?php

namespace Goldnead\StatamicPayments\Console\Commands;

use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Checkouts that were never paid, removed after a while.
 *
 * The reason is not tidiness. A paid order carries a retention *obligation*; an
 * abandoned checkout carries the opposite. What sits in those rows is the name
 * and the email address of somebody with whom no contract was ever concluded,
 * and keeping that indefinitely needs a purpose nobody can name.
 *
 * Deleted rather than anonymised: an anonymised record with no purpose is still
 * a record.
 */
class PruneUnpaidCheckouts extends Command
{
    protected $signature = 'payments:prune-unpaid {--dry-run : Nur zählen, nichts löschen}';

    protected $description = 'Delete checkouts that were started and never paid.';

    public function handle(): int
    {
        $tage = (int) config('statamic-payments.prune_unpaid_after_days', 0);

        if ($tage <= 0) {
            $this->components->warn(
                'Abgeschaltet. `statamic-payments.prune_unpaid_after_days` auf eine Zahl setzen.'
            );

            return self::SUCCESS;
        }

        $abfrage = Payment::query()
            // Nur was nie bezahlt wurde. Eine Zahlung, die den Status
            // gewechselt hat, ist ein Beleg — auch eine fehlgeschlagene, denn
            // dass jemand es versucht hat, kann später eine Frage sein.
            ->whereIn('status', [Payment::STATUS_INITIATED, Payment::STATUS_OPEN])
            ->whereNull('fulfilled_at')
            ->whereNull('paid_at')
            ->where('refunded_cent', 0)
            // Nicht unter einer laufenden Abbruch-Strecke wegräumen: eine
            // Automation, deren Auslöser verschwindet, scheitert mitten drin.
            ->whereNull('abandoned_notified_at')
            ->where('created_at', '<=', Carbon::now()->subDays($tage));

        $anzahl = (clone $abfrage)->count();

        if ($this->option('dry-run')) {
            $this->components->info($anzahl.' unbezahlte Checkouts wären zu löschen.');

            return self::SUCCESS;
        }

        // In Stücken, weil ein erster Lauf auf einer bestehenden Installation
        // jeden alten offenen Checkout trifft. `payment_items` hängt per
        // cascadeOnDelete daran.
        $geloescht = 0;

        $abfrage->orderBy('id')->chunkById(200, function ($stapel) use (&$geloescht) {
            foreach ($stapel as $zahlung) {
                $zahlung->delete();
                $geloescht++;
            }
        });

        $this->components->info(match (true) {
            $geloescht === 0 => 'Nichts zu löschen.',
            $geloescht === 1 => 'Ein unbezahlter Checkout gelöscht.',
            default => $geloescht.' unbezahlte Checkouts gelöscht.',
        });

        return self::SUCCESS;
    }
}
