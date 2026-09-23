<?php

namespace Goldnead\StatamicPayments\Console\Commands;

use Goldnead\StatamicPayments\Support\SubscriptionClaims;
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

    protected $description = 'Put right subscriptions a dead process left in a claim, then resume paused ones whose date has come.';

    public function handle(SubscriptionPauses $pauses, SubscriptionClaims $claims): int
    {
        // Claims first: a row stuck in `resuming` is a pause that wanted to end.
        $stuck = $claims->sweep();
        $report = $pauses->resumeDue();

        $this->info(sprintf(
            '%d left-behind claim(s) put right, %d need a person; %d resumed, %d could not be resumed.',
            $stuck['settled'],
            $stuck['unresolved'],
            $report['resumed'],
            $report['failed'],
        ));

        return $report['failed'] + $stuck['unresolved'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
