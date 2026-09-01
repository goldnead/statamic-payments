<?php

namespace Goldnead\StatamicPayments\Http\Controllers\Cp;

use Goldnead\StatamicPayments\Models\Cancellation;
use Illuminate\Support\Facades\Gate;
use Statamic\Http\Controllers\CP\ActionController;

/** Was die Checkboxen der Kündigungsliste ausführen. */
class CancellationActionsController extends ActionController
{
    protected function getSelectedItems($items, $context)
    {
        abort_unless(Gate::allows('access cancellations utility'), 403);

        return Cancellation::query()->whereIn('id', $items->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->values()->all())->get();
    }
}
