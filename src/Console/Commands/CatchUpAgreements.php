<?php

namespace Goldnead\StatamicPayments\Console\Commands;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Fulfilment;
use Illuminate\Console\Command;

/**
 * Zahlungen nachziehen, deren Abo-Schritt gescheitert ist.
 *
 * Nach `PaymentPaid` bringt die Erfuellung das Abo auf Stand: einen Zyklus
 * zaehlen, ein neues Abo anlegen, ein ersetztes beenden. Scheitert das, bleibt
 * die Zahlung stehen (der Kunde hat, was er bezahlt hat), und an der Zeile
 * steht `meta.agreement_pending`. Ohne diesen Befehl wuerde eine Ratenzahlung
 * nie zu Ende gezaehlt und ein Abo nie angelegt, und niemand merkte es.
 *
 * Wiederholbar: es laeuft nur der gescheiterte Schritt, keine Auslieferung.
 * `--dry-run` zeigt die betroffenen Zahlungen, ohne etwas zu tun.
 */
class CatchUpAgreements extends Command
{
    protected $signature = 'payments:agreement-catch-up {--dry-run : Nur zeigen, nichts nachziehen}';

    protected $description = 'Den Abo-Schritt von Zahlungen nachholen, die meta.agreement_pending tragen.';

    public function handle(Fulfilment $fulfilment): int
    {
        $offen = Payment::query()
            ->whereNotNull('meta->agreement_pending')
            ->orderBy('id');

        $anzahl = (clone $offen)->count();

        if ($anzahl === 0) {
            $this->components->info('Keine Zahlung wartet auf ihren Abo-Schritt.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['Zahlung', 'Anbieter-ID', 'seit', 'Versuche', 'Fehler'],
                (clone $offen)->get()->map(fn (Payment $p): array => [
                    $p->getKey(),
                    $p->provider_id,
                    $p->meta['agreement_pending']['since'] ?? '',
                    $p->meta['agreement_pending']['attempts'] ?? '',
                    $p->meta['agreement_pending']['error'] ?? '',
                ])->all(),
            );

            $this->components->info($anzahl.' Zahlung(en) warten. Ohne --dry-run wird nachgezogen.');

            return self::SUCCESS;
        }

        $nachgezogen = 0;
        $gescheitert = [];

        (clone $offen)->chunkById(100, function ($zahlungen) use ($fulfilment, &$nachgezogen, &$gescheitert): void {
            foreach ($zahlungen as $zahlung) {
                if ($fulfilment->catchUpAgreement($zahlung)) {
                    $nachgezogen++;
                } else {
                    $gescheitert[] = $zahlung->getKey();
                }
            }
        });

        $this->components->info($nachgezogen.' Zahlung(en) nachgezogen.');

        if ($gescheitert !== []) {
            // Laut, mit Exit-Code: ein Zeitplan, der diesen Befehl ruft, soll
            // das Scheitern melden koennen und es nicht als erledigt verbuchen.
            $this->components->error(count($gescheitert).' Zahlung(en) scheitern weiter, der Marker bleibt: '.implode(', ', $gescheitert).'. Stacktrace im Log.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
