<?php

namespace Goldnead\StatamicPayments\Http\Controllers\Cp;

use Goldnead\StatamicPayments\Http\Resources\Cp\PaymentsCollection;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Statamic\Facades\Scope;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Http\Requests\FilteredRequest;
use Statamic\Query\Scopes\Filters\Concerns\QueriesFilters;
use Statamic\Statamic;

/**
 * The payments screen in the Control Panel.
 *
 * Read-only, and not a replacement for the Mollie dashboard: refunds, disputes
 * and payout detail live at the provider and are more complete there. What this
 * screen answers is the question only the site can answer — was this order
 * fulfilled, and when.
 *
 * The Inertia response carries no rows. The Listing fetches them itself, which
 * is what core's own listings do; sending them along as well would query the
 * same list twice on every page load.
 */
class PaymentsController extends CpController
{
    use QueriesFilters;

    /** The key filters are registered and looked up under. */
    public const SCOPE = 'statamic-payments-payments';

    public function index(FilteredRequest $request)
    {
        // The utility route already carries `can:access payments utility`.
        // This is the second lock, for the day someone points a route of their
        // own at this action.
        // Through the Gate, which is where `Utility::register` puts the
        // permission and what the route's `can:` middleware consults. Asking
        // the user object instead means asking whichever guard happens to be
        // the default, and on a site with its own guard that answers null.
        abort_unless(Gate::allows('access payments utility'), 403);

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return $this->json($request);
        }

        return Inertia::render('statamic-payments::Payments/Index', [
            'listingUrl' => cp_route('utilities.payments'),
            'filters' => Scope::filters(self::SCOPE),
            'sortColumn' => 'created_at',
            // Newest first. On a payments screen the most recent order is the
            // one being asked about, and ascending would bury it on the last
            // page.
            'sortDirection' => 'desc',
            // Whether anything exists at all, which is a different question
            // from whether this search found anything. Driven off the filtered
            // result, a fruitless search claimed the webhook was misconfigured.
            'hasAny' => Payment::query()->exists(),
        ]);
    }

    protected function json(FilteredRequest $request)
    {
        $query = Payment::query();

        if ($search = $this->search($request)) {
            $this->applySearch($query, $search);
        }

        $activeFilterBadges = $this->queryFilters($query, $request->filters, ['scope' => self::SCOPE]);

        [$column, $direction] = $this->order($request);
        $query->orderBy($column, $direction);

        $payments = $query->paginate(Statamic::cpPerPage($request->get('perPage')));

        return (new PaymentsCollection($payments))
            ->columnPreferenceKey('statamic-payments.payments.columns')
            ->additional(['meta' => ['activeFilterBadges' => $activeFilterBadges]]);
    }

    protected function search(FilteredRequest $request): ?string
    {
        $term = trim((string) $request->get('search', $request->get('q', '')));

        return $term === '' ? null : $term;
    }

    /**
     * @param  Builder<Payment>  $query
     */
    protected function applySearch(Builder $query, string $term): void
    {
        // `%` and `_` are wildcards in LIKE; unescaped, a search for "50%"
        // matches everything and reads as a filter that does not work.
        //
        // The `ESCAPE` clause has to be spelled out: MySQL and Postgres treat a
        // backslash as an escape by default, **SQLite does not**. Raw SQL only
        // for that clause; the column names come from the list below and the
        // value stays bound.
        $escaped = addcslashes($term, '%_\\');

        $query->where(function (Builder $q) use ($escaped) {
            foreach (['email', 'name', 'product', 'provider_id'] as $column) {
                $q->orWhereRaw($column." LIKE ? ESCAPE '\\'", ['%'.$escaped.'%']);
            }
        });
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function order(FilteredRequest $request): array
    {
        // A positive list, not a filter: `sort` arrives from the query string,
        // and passed through it would order by any column in the table.
        // `amount` is what the screen shows; `amount_cent` is what it sorts by,
        // because ordering a formatted string puts 9.00 above 19.00.
        $sortable = [
            'created_at' => 'created_at',
            'paid_at' => 'paid_at',
            'fulfilled_at' => 'fulfilled_at',
            'amount' => 'amount_cent',
            'status' => 'status',
            'product' => 'product',
            'email' => 'email',
        ];

        $requested = (string) $request->get('sort', 'created_at');
        $direction = strtolower((string) $request->get('order', 'desc')) === 'asc' ? 'asc' : 'desc';

        return [$sortable[$requested] ?? 'created_at', $direction];
    }
}
