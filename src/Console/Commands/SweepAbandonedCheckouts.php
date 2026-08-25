<?php

namespace Goldnead\StatamicPayments\Console\Commands;

use Goldnead\StatamicPayments\Support\Abandonment;
use Illuminate\Console\Command;

class SweepAbandonedCheckouts extends Command
{
    protected $signature = 'payments:sweep-abandoned';

    protected $description = 'Announce checkouts that were started and never paid.';

    public function handle(Abandonment $abandonment): int
    {
        if (! $abandonment->enabled()) {
            $this->components->warn('Abgeschaltet. `statamic-payments.abandoned.enabled` einschalten.');

            return self::SUCCESS;
        }

        $gezaehlt = $abandonment->sweep();

        $this->components->info(match (true) {
            $gezaehlt === 0 => 'Nichts liegen geblieben.',
            $gezaehlt === 1 => 'Ein abgebrochener Checkout gemeldet.',
            default => $gezaehlt.' abgebrochene Checkouts gemeldet.',
        });

        return self::SUCCESS;
    }
}
