<?php

namespace Goldnead\StatamicPayments\Console\Commands;

use Goldnead\StatamicPayments\Models\Cancellation;
use Goldnead\StatamicPayments\Models\Withdrawal;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Schritt-1-Leichen: Erklärungen, die nie bestätigt wurden.
 *
 * Wer das Widerrufs- oder Kündigungsformular ausfüllt und die zweite
 * Schaltfläche nicht drückt, hat nichts erklärt — § 356a und § 312k stellen
 * auf die Bestätigung ab. Was liegen bleibt, ist ein Name, eine Adresse und
 * eine Bestellkennung ohne Vorgang dahinter, und dafür gibt es nach ein paar
 * Tagen keinen Zweck mehr. Sieben Tage lassen Zeit für „ich mache das morgen
 * fertig"; danach ist es Vorrat.
 *
 * Bestätigte Zeilen werden nie angefasst. Die sind der Vorgang.
 */
class PruneLegalDrafts extends Command
{
    protected $signature = 'payments:prune-legal-drafts
        {--days=7 : Nach wie vielen Tagen eine unbestätigte Erklärung gelöscht wird}
        {--dry-run : Nur zählen, nichts löschen}';

    protected $description = 'Delete withdrawal and cancellation declarations that were never confirmed.';

    public function handle(): int
    {
        $tage = max(1, (int) $this->option('days'));
        $grenze = Carbon::now()->subDays($tage);

        $abfragen = [
            'Widerruf' => Withdrawal::query()->whereNull('confirmed_at')->where('declared_at', '<=', $grenze),
            'Kündigung' => Cancellation::query()->whereNull('confirmed_at')->where('declared_at', '<=', $grenze),
        ];

        $gesamt = 0;

        foreach ($abfragen as $art => $abfrage) {
            $anzahl = (clone $abfrage)->count();

            if ($this->option('dry-run')) {
                $this->components->info(sprintf('%s: %d unbestätigte Erklärung(en) wären zu löschen.', $art, $anzahl));

                continue;
            }

            if ($anzahl > 0) {
                $abfrage->delete();
            }

            $gesamt += $anzahl;
            $this->components->info(sprintf('%s: %d unbestätigte Erklärung(en) gelöscht.', $art, $anzahl));
        }

        if (! $this->option('dry-run') && $gesamt === 0) {
            $this->components->info('Nichts zu löschen.');
        }

        return self::SUCCESS;
    }
}
