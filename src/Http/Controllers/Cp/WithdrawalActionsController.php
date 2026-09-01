<?php

namespace Goldnead\StatamicPayments\Http\Controllers\Cp;

use Goldnead\StatamicPayments\Models\Withdrawal;
use Illuminate\Support\Facades\Gate;
use Statamic\Http\Controllers\CP\ActionController;

/**
 * Was die Checkboxen der Widerrufsliste ausführen. Siehe
 * {@see SubscriptionActionsController} für das Muster: die Ids kommen aus dem
 * Request, also werden sie nachgeschlagen, nie geglaubt.
 */
class WithdrawalActionsController extends ActionController
{
    protected function getSelectedItems($items, $context)
    {
        abort_unless(Gate::allows('access withdrawals utility'), 403);

        return Withdrawal::query()->whereIn('id', $items->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->values()->all())->get();
    }
}
