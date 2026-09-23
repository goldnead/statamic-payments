<?php

namespace Goldnead\StatamicPayments\Console\Commands;

use Goldnead\StatamicPayments\Support\SubscriptionReminders;
use Illuminate\Console\Command;

/**
 * Reminders before a charge and before a card expires. See
 * {@see SubscriptionReminders}.
 *
 * Not scheduled by the addon, like every other pass here:
 *
 *     Schedule::command('payments:reminders')->dailyAt('09:00');
 *
 * Safe to run twice: every reminder is claimed under a unique index first.
 */
class SendSubscriptionReminders extends Command
{
    protected $signature = 'payments:reminders';

    protected $description = 'Send the reminders before a charge and before a card expires.';

    public function handle(SubscriptionReminders $reminders): int
    {
        $report = $reminders->run();

        $this->info(sprintf(
            '%d agreement(s) looked at, %d reminder(s) sent, %d announced without a mail, %d skipped, %d failed.',
            $report['seen'],
            $report['sent'],
            $report['announced'],
            $report['skipped'],
            $report['failed'],
        ));

        return $report['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
