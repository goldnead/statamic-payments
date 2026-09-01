<?php

namespace Goldnead\StatamicPayments\Http\Resources\Cp;

use Statamic\CP\Column;

class WithdrawalsCollection extends LegalRequestsCollection
{
    public $collects = ListedWithdrawal::class;

    protected function columnList(): array
    {
        return [
            Column::make('public_id')->label(__('statamic-payments::messages.legal_column_id'))->sortable(true)->defaultOrder(1),
            Column::make('confirmed_at')->label(__('statamic-payments::messages.legal_column_confirmed_at'))->sortable(true)->defaultOrder(2),
            Column::make('email')->label(__('statamic-payments::messages.legal_column_email'))->sortable(true)->defaultOrder(3),
            Column::make('order_reference')->label(__('statamic-payments::messages.withdrawal_column_reference'))->sortable(true)->defaultOrder(4),
            Column::make('payment')->label(__('statamic-payments::messages.withdrawal_column_payment'))->sortable(false)->defaultOrder(5),
            Column::make('hints')->label(__('statamic-payments::messages.legal_column_hints'))->sortable(false)->defaultOrder(6),
            Column::make('handled_at')->label(__('statamic-payments::messages.legal_column_handled'))->sortable(true)->defaultOrder(7),
        ];
    }
}
