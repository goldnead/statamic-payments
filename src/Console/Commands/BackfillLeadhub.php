<?php

namespace Goldnead\StatamicPayments\Console\Commands;

use Goldnead\StatamicPayments\Integrations\LeadhubBridge;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Console\Command;

/**
 * Send paid orders to the CRM that never reached it.
 *
 * Three situations produce the same silence, and none of them announces itself:
 * the bridge was switched on after sales had already happened, the CRM was
 * unavailable for an afternoon, or a first-time buyer's contact could not be
 * created and the amount had nowhere to go. In all three the revenue report is
 * simply too low — the failure that looks like an answer.
 *
 * Safe to run as often as you like. Both halves of the bridge are idempotent by
 * construction: the timeline entry is keyed by a unique dedupe key, the ledger
 * line by a unique reference. A second pass over the same orders writes nothing
 * and reports what it found.
 */
class BackfillLeadhub extends Command
{
    protected $signature = 'payments:leadhub-backfill
        {--since= : Nur Zahlungen ab diesem Datum (YYYY-MM-DD)}
        {--limit=0 : Höchstens so viele Zahlungen anfassen (0 = alle)}
        {--dry-run : Nur zählen, nichts senden}';

    protected $description = 'Resend paid orders to the CRM so no revenue is missing from a contact.';

    public function handle(LeadhubBridge $bridge): int
    {
        if (! $bridge->available()) {
            // Not an error. The bridge is off by default, and a scheduler that
            // ran this on an install without a CRM should say so once and stop,
            // not fail.
            $this->warn('Die CRM-Brücke ist aus oder das Addon fehlt. Nichts zu tun.');

            return self::SUCCESS;
        }

        $query = Payment::query()
            ->where('status', Payment::STATUS_PAID)
            ->whereNotNull('email')
            ->orderBy('id');

        if ($seit = $this->option('since')) {
            $query->where('paid_at', '>=', $seit);
        }

        if (($limit = (int) $this->option('limit')) > 0) {
            $query->limit($limit);
        }

        $anzahl = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("{$anzahl} bezahlte Zahlungen würden erneut an das CRM gehen.");

            return self::SUCCESS;
        }

        if ($anzahl === 0) {
            $this->info('Keine bezahlten Zahlungen gefunden.');

            return self::SUCCESS;
        }

        $balken = $this->output->createProgressBar($anzahl);
        $balken->start();

        // Chunked by id rather than paginated: the bridge writes nothing to
        // `payments`, so the result set cannot shift underneath, and a chunk
        // keeps the memory flat on an install with years of orders.
        $query->chunkById(200, function ($zahlungen) use ($bridge, $balken): void {
            foreach ($zahlungen as $zahlung) {
                $bridge->recordPurchase($zahlung->loadMissing('items'));
                $balken->advance();
            }
        });

        $balken->finish();
        $this->newLine(2);
        $this->info("{$anzahl} Zahlungen durchgereicht. Was schon dort war, blieb unverändert.");

        return self::SUCCESS;
    }
}
