<?php

namespace Goldnead\StatamicPayments\Http\Resources\Cp;

use Statamic\CP\Column;

class CancellationsCollection extends LegalRequestsCollection
{
    public $collects = ListedCancellation::class;

    protected function columnList(): array
    {
        return [
            Column::make('public_id')->label(__('statamic-payments::messages.legal_column_id'))->sortable(true)->defaultOrder(1),
            Column::make('confirmed_at')->label(__('statamic-payments::messages.legal_column_confirmed_at'))->sortable(true)->defaultOrder(2),
            Column::make('email')->label(__('statamic-payments::messages.legal_column_email'))->sortable(true)->defaultOrder(3),
            Column::make('identification')->label(__('statamic-payments::messages.cancellation_column_identification'))->sortable(true)->defaultOrder(4),
            Column::make('kind')->label(__('statamic-payments::messages.cancellation_column_kind'))->sortable(true)->defaultOrder(5),
            Column::make('effective_at')->label(__('statamic-payments::messages.cancellation_column_effective_at'))->sortable(true)->defaultOrder(6),
            Column::make('subscription')->label(__('statamic-payments::messages.cancellation_column_subscription'))->sortable(false)->defaultOrder(7),
            Column::make('handled_at')->label(__('statamic-payments::messages.legal_column_handled'))->sortable(true)->defaultOrder(8),
        ];
    }
}
