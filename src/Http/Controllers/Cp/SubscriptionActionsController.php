<?php

namespace Goldnead\StatamicPayments\Http\Controllers\Cp;

use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Statamic\Http\Controllers\CP\ActionController;

/**
 * What the listing's checkboxes run.
 *
 * Core's `ActionController` does the whole dance — validating the selection,
 * refusing an action the user may not run on any of the chosen items, running
 * it, reporting back. All that is left is turning the ids the browser sent into
 * rows, and that is exactly where a bulk endpoint gets it wrong: the ids come
 * from a request, so they are looked up, never trusted.
 */
class SubscriptionActionsController extends ActionController
{
    protected function getSelectedItems($items, $context)
    {
        abort_unless(Gate::allows('access subscriptions utility'), 403);

        return Subscription::query()->whereIn('id', $this->ids($items))->get();
    }

    /**
     * @param  Collection<int, mixed>  $items
     * @return list<int>
     */
    protected function ids($items): array
    {
        return $items
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
