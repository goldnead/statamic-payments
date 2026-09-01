<?php

namespace Goldnead\StatamicPayments\Actions;

use Goldnead\StatamicPayments\Models\Cancellation;

class MarkCancellationHandled extends MarkHandled
{
    protected static $handle = 'statamic_payments_mark_cancellation_handled';

    protected function model(): string
    {
        return Cancellation::class;
    }

    protected function permission(): string
    {
        return 'handle payment cancellations';
    }
}
