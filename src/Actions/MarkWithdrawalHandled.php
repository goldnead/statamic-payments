<?php

namespace Goldnead\StatamicPayments\Actions;

use Goldnead\StatamicPayments\Models\Withdrawal;

class MarkWithdrawalHandled extends MarkHandled
{
    protected static $handle = 'statamic_payments_mark_withdrawal_handled';

    protected function model(): string
    {
        return Withdrawal::class;
    }

    protected function permission(): string
    {
        return 'handle payment withdrawals';
    }
}
