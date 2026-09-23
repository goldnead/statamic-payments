<?php

namespace Goldnead\StatamicPayments\Console\Commands;

use Goldnead\StatamicPayments\Support\SubscriptionPauses;
use Illuminate\Console\Command;

/**
 * Resume every pause whose date has come.
 *
 * Needed on Mollie, where a pause is an ended agreement and nothing at the
 * provider knows when to start again. On Stripe the provider resumes by itself
 * on the same date; this brings the row along, and resuming twice is harmless.
 *
 *     Schedule::command('payments:resume-paused')->daily()->withoutOverlapping();
 */
class ResumePausedSubscriptions extends Command
{
    protected $signature = 'payments:resume-paused';

    protected $description = 'Resume paused subscriptions whose resume date has come.';

    public function handle(SubscriptionPauses $pauses): int
    {
        $report = $pauses->resumeDue();

        $this->info(sprintf('%d resumed, %d could not be resumed.', $report['resumed'], $report['failed']));

        return $report['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
