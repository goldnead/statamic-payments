<?php

namespace Goldnead\StatamicPayments\Scopes;

use Goldnead\StatamicPayments\Models\Payment;
use Statamic\Query\Scopes\Filter;

/**
 * The status filter, as a real Statamic scope.
 *
 * Not as a query parameter the controller reads: `Listing` builds its requests
 * from a fixed set of keys and rewrites the address bar from the same set, so a
 * parameter it does not know is gone after the first fetch. A scope is the
 * shape core understands.
 */
class PaymentStatus extends Filter
{
    public $pinned = true;

    public static function title()
    {
        return __('statamic-payments::messages.column_status');
    }

    public function fieldItems()
    {
        return [
            'status' => [
                'type' => 'select',
                'placeholder' => __('statamic-payments::messages.filter_any'),
                'options' => collect(Payment::statuses())
                    ->mapWithKeys(fn (string $s) => [$s => __('statamic-payments::messages.status_'.$s)])
                    ->all(),
            ],
        ];
    }

    public function apply($query, $values)
    {
        $status = $values['status'] ?? null;

        // Anything unrecognised filters nothing. An empty list would read as
        // "there are no payments", which is a different statement.
        if (! in_array($status, Payment::statuses(), true)) {
            return;
        }

        $query->where('status', $status);
    }

    public function badge($values)
    {
        $status = $values['status'] ?? null;

        if (! in_array($status, Payment::statuses(), true)) {
            return null;
        }

        return __('statamic-payments::messages.column_status').': '.__('statamic-payments::messages.status_'.$status);
    }

    public function visibleTo($key)
    {
        return $key === 'statamic-payments-payments';
    }
}
