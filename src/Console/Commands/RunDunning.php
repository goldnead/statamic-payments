<?php

namespace Goldnead\StatamicPayments\Console\Commands;

use Goldnead\StatamicPayments\Support\Dunning;
use Goldnead\StatamicPayments\Support\DunningNotice;
use Illuminate\Console\Command;

/**
 * One pass over every agreement whose payment failed.
 *
 * Not scheduled by the addon — the same line this package holds for the
 * abandoned-checkout sweep and the prunes. A host that schedules it decides how
 * often somebody's failed payment is looked at, and that is theirs to decide.
 * Once a day is right for a schedule counted in days:
 *
 *     Schedule::command('payments:dunning')->daily();
 *
 * Safe to run twice: every letter is claimed with a conditional UPDATE and
 * every ending with another, so a second run in the same hour sends nothing and
 * ends nothing twice.
 */
class RunDunning extends Command
{
    protected $signature = 'payments:dunning {--dry-run : Say what would happen and change nothing}';

    protected $description = 'Send the due dunning letters and end the agreements that ran out of them.';

    public function handle(Dunning $dunning, DunningNotice $notice): int
    {
        if (! $dunning->enabled()) {
            $this->warn('statamic-payments.dunning.enabled is off; nothing was done.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $sent = $ended = $stopped = 0;

        foreach ($dunning->running() as $subscription) {
            // The provider first, always. It retries on its own rhythm, and a
            // card that went through between two letters ends the sequence
            // silently — writing to somebody who has already paid is the one
            // outcome worth more than the letter.
            $settled = $dunning->settledMeanwhile($subscription);

            if ($settled === true) {
                $dry || $dunning->stop($subscription);
                $stopped++;

                continue;
            }

            // The end does not wait for the provider, and that order matters.
            // `dueToEnd()` is a question about dates alone; hanging it behind a
            // successful provider call means a multi-day outage freezes every
            // running sequence — no further letters, but no ending either, long
            // after the grace period is up. The money has not arrived either
            // way, and an agreement nobody can reach is not a reason to keep
            // giving the access away.
            if ($dunning->dueToEnd($subscription)) {
                if ($dry || $dunning->end($subscription)) {
                    $ended++;
                }

                continue;
            }

            if ($settled === null) {
                // The provider would not answer. Logged there; here it simply
                // means no letter goes out on a guess this run.
                continue;
            }

            $stage = $dunning->stageDue($subscription);

            if ($stage === null) {
                continue;
            }

            if ($dry) {
                $sent++;

                continue;
            }

            // The claim comes before the mail, so two workers cannot both write
            // this letter. If the send then fails, the claim goes back and the
            // next run tries the same stage again.
            if (! $dunning->claimStage($subscription, $stage)) {
                continue;
            }

            if ($notice->send($subscription, $stage)) {
                $sent++;
            } else {
                $dunning->releaseStage($subscription, $stage);
            }
        }

        $this->info($dry
            ? "Dry run: {$sent} letter(s) due, {$ended} agreement(s) would end, {$stopped} settled meanwhile."
            : "{$sent} letter(s) sent, {$ended} agreement(s) ended, {$stopped} sequence(s) closed because the money arrived.");

        return self::SUCCESS;
    }
}
