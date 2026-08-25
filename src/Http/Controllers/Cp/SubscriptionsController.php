<?php

namespace Goldnead\StatamicPayments\Http\Controllers\Cp;

use Goldnead\StatamicPayments\Http\Resources\Cp\SubscriptionsCollection;
use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Statamic\Facades\Scope;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Http\Requests\FilteredRequest;
use Statamic\Query\Scopes\Filters\Concerns\QueriesFilters;
use Statamic\Statamic;

/**
 * The subscriptions screen in the Control Panel.
 *
 * It reads. There is no form: an agreement is not something somebody types, it
 * is what a confirmed first payment leaves behind, and a "New subscription"
 * button would offer to create a row the provider has never heard of and will
 * never charge.
 *
 * The one thing that can be changed about an agreement is whether it goes on,
 * and that does not happen here either. Cancelling is a registered action
 * (`Actions\CancelSubscription`), which is what core offers from a row's own
 * menu *and* from the bulk toolbar — one endpoint, one lock, one way to get the
 * report right. A second cancel route beside it would be a second way to write
 * "cancelled" onto a row the provider is still charging.
 */
class SubscriptionsController extends CpController
{
    use QueriesFilters;

    /** The key filters are registered and looked up under. */
    public const SCOPE = 'statamic-payments-subscriptions';

    public function index(FilteredRequest $request)
    {
        $this->authorizeAccess();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return $this->json($request);
        }

        return Inertia::render('statamic-payments::Subscriptions/Index', [
            'listingUrl' => cp_route('utilities.subscriptions'),
            // Without an action URL the Listing renders no checkboxes and no
            // bulk toolbar, which is the difference between this screen and the
            // Entries screen the user just came from.
            'actionUrl' => cp_route('utilities.subscriptions.actions'),
            'filters' => Scope::filters(self::SCOPE, ['scope' => self::SCOPE]),
            // Whatever is about to be charged is the thing being looked for, so
            // the soonest date leads.
            'sortColumn' => 'next_payment_at',
            'sortDirection' => 'asc',
            // Whether anything exists at all, which is a different question
            // from whether this search found anything. Driven off the filtered
            // result, a fruitless search would claim the webhook is broken.
            'hasAny' => Subscription::query()->exists(),
            // Every label on the screen, translated here. Building them in the
            // template works right up until this addon is installed somewhere
            // its language files are not part of the Control Panel dictionary,
            // and a raw `statamic-payments::messages.…` where a label belongs is
            // the loudest possible "third-party addon".
            't' => $this->strings(),
        ]);
    }

    protected function json(FilteredRequest $request)
    {
        // The cycles come along with the rows: they are what the slide-over
        // shows, and one eager load beats an endpoint and a spinner.
        $query = Subscription::query()->with([
            'payments' => fn ($q) => $q->orderByDesc('created_at')->orderByDesc('id'),
        ]);

        if ($search = trim((string) $request->get('search', ''))) {
            $this->applySearch($query, $search);
        }

        $activeFilterBadges = $this->queryFilters($query, $request->filters, ['scope' => self::SCOPE]);

        [$column, $direction] = $this->order($request);

        // Empty last, in both directions. The default sort is "what is charged
        // next", and a cancelled agreement has no next charge — SQLite and
        // MySQL sort those nulls to the top, so the screen opened on the rows
        // that will never be charged again. `IS NULL` is the one spelling all
        // three databases agree on; the column comes from the positive list
        // below, never from the request.
        $query->orderByRaw($column.' IS NULL asc')->orderBy($column, $direction);

        return (new SubscriptionsCollection($query->paginate(Statamic::cpPerPage($request->get('perPage')))))
            ->columnPreferenceKey('statamic-payments.subscriptions.columns')
            ->additional(['meta' => ['activeFilterBadges' => $activeFilterBadges]]);
    }

    /**
     * Through the Gate, which is where `Utility::register` puts the permission
     * and what the route's `can:` middleware consults. Asking the user object
     * instead means asking whichever guard happens to be the default, and on a
     * site with its own guard that answers null.
     *
     * Repeated in the controller because a route is a place a middleware can be
     * forgotten, and what is behind it is who pays what, every month, plus a
     * button that stops it.
     */
    protected function authorizeAccess(): void
    {
        abort_unless(Gate::allows('access subscriptions utility'), 403);
    }

    /**
     * @param  Builder<Subscription>  $query
     */
    protected function applySearch(Builder $query, string $term): void
    {
        // `%` and `_` are wildcards in LIKE; unescaped, a search for "50%"
        // matches everything and reads as a filter that does not work. The
        // `ESCAPE` clause is spelled out because SQLite, unlike MySQL and
        // Postgres, has no default one. Raw SQL only for that clause; the
        // column names come from the list below and the value stays bound.
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
            'product' => 'product',
            'amount' => 'amount_cent',
            'progress' => 'times_charged',
            'next_payment_at' => 'next_payment_at',
            'status' => 'status',
            'email' => 'email',
            'created_at' => 'created_at',
        ];

        $requested = (string) $request->get('sort', 'next_payment_at');
        $direction = strtolower((string) $request->get('order', 'asc')) === 'desc' ? 'desc' : 'asc';

        return [$sortable[$requested] ?? 'next_payment_at', $direction];
    }

    /**
     * @return array<string, string>
     */
    protected function strings(): array
    {
        return [
            'title' => __('statamic-payments::messages.subscriptions_utility_title'),
            'utilities' => __('Utilities'),
            'empty_heading' => __('statamic-payments::messages.subscriptions_empty_heading'),
            'empty_title' => __('statamic-payments::messages.subscriptions_empty_title'),
            'empty_description' => __('statamic-payments::messages.subscriptions_empty_description'),
            'detail_action' => __('statamic-payments::messages.subscription_detail_action'),
            'detail_payments' => __('statamic-payments::messages.subscription_detail_payments'),
            'detail_no_payments' => __('statamic-payments::messages.subscription_detail_no_payments'),
            'field_kind' => __('statamic-payments::messages.subscription_column_kind'),
            'field_amount' => __('statamic-payments::messages.subscription_column_amount'),
            'field_rhythm' => __('statamic-payments::messages.subscription_column_rhythm'),
            'field_progress' => __('statamic-payments::messages.subscription_column_progress'),
            'field_status' => __('statamic-payments::messages.subscription_column_status'),
            'field_starts_at' => __('statamic-payments::messages.subscription_field_starts_at'),
            'field_next_payment' => __('statamic-payments::messages.subscription_column_next_payment'),
            'field_cancelled_at' => __('statamic-payments::messages.subscription_field_cancelled_at'),
            'field_ended_at' => __('statamic-payments::messages.subscription_field_ended_at'),
            'field_total' => __('statamic-payments::messages.subscription_field_total'),
            'field_buyer' => __('statamic-payments::messages.subscription_field_buyer'),
            'field_name' => __('statamic-payments::messages.subscription_field_name'),
            'field_provider' => __('statamic-payments::messages.subscription_field_provider'),
            'field_provider_id' => __('statamic-payments::messages.subscription_column_provider_id'),
            'field_customer_reference' => __('statamic-payments::messages.subscription_field_customer_reference'),
            'payment_when' => __('statamic-payments::messages.column_when'),
            'payment_amount' => __('statamic-payments::messages.column_amount'),
            'payment_status' => __('statamic-payments::messages.column_status'),
            'none' => __('statamic-payments::messages.none'),
        ];
    }
}
