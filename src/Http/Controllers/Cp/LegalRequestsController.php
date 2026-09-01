<?php

namespace Goldnead\StatamicPayments\Http\Controllers\Cp;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Statamic\Facades\Scope;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Http\Requests\FilteredRequest;
use Statamic\Query\Scopes\Filters\Concerns\QueriesFilters;
use Statamic\Statamic;

/**
 * Was die Bildschirme „Widerrufe" und „Kündigungen" gemeinsam haben.
 *
 * Gebaut wie die Zahlungs- und Abo-Listen nebenan, mit demselben Vertrag: kein
 * Datensatz in der Inertia-Antwort, die Listing-Komponente holt ihn sich; ein
 * `actionUrl`, damit die Checkboxen und das Zeilenmenü erscheinen; die
 * Spalten in jeder JSON-Antwort. Die Unterklassen sagen, welches Modell,
 * welche Utility, welche Spalten und welche Wörter.
 *
 * Nur **bestätigte** Erklärungen. Eine abgebrochene Erklärung ohne Schritt 2
 * ist kein Widerruf und keine Kündigung, aber sie enthält Name und Adresse von
 * jemandem, der es sich anders überlegt hat — und die gehören nicht auf einen
 * Bildschirm.
 */
abstract class LegalRequestsController extends CpController
{
    use QueriesFilters;

    /** Der Utility-Handle, aus dem Route, Recht und Scope folgen. */
    abstract protected function handle(): string;

    /** @return class-string<Model> */
    abstract protected function model(): string;

    abstract protected function component(): string;

    /**
     * @param  LengthAwarePaginator<int, Model>  $rows
     */
    abstract protected function collection($rows): ResourceCollection;

    /** @return array<string, string> Anzeigename → Spalte. */
    abstract protected function sortable(): array;

    /** @return array<string, string> */
    abstract protected function strings(): array;

    /** @return list<string> */
    abstract protected function searchable(): array;

    /**
     * Was mit den Zeilen mitkommt: die zugeordnete Zahlung, das zugeordnete
     * Abo. Ein Eager Load statt einer Abfrage je Zeile.
     *
     * @return list<string>
     */
    abstract protected function eager(): array;

    public function index(FilteredRequest $request)
    {
        // Durch das Gate, wo `Utility::register` das Recht ablegt und was die
        // `can:`-Middleware der Route fragt. Zweites Schloss hinter der
        // Route, weil hinter ihr Namen und Adressen von Leuten stehen, die
        // einen Vertrag lösen wollen.
        abort_unless(Gate::allows('access '.$this->handle().' utility'), 403);

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return $this->json($request);
        }

        return Inertia::render($this->component(), [
            'listingUrl' => cp_route('utilities.'.$this->handle()),
            'actionUrl' => cp_route('utilities.'.$this->handle().'.actions'),
            'paymentsUrl' => cp_route('utilities.payments'),
            'subscriptionsUrl' => cp_route('utilities.subscriptions'),
            'filters' => Scope::filters($this->scope(), ['scope' => $this->scope()]),
            'sortColumn' => 'confirmed_at',
            'sortDirection' => 'desc',
            'hasAny' => $this->base()->exists(),
            't' => $this->strings(),
        ]);
    }

    protected function scope(): string
    {
        return 'statamic-payments-'.$this->handle();
    }

    /** @return Builder<Model> */
    protected function base(): Builder
    {
        $model = $this->model();

        return $model::query()->whereNotNull('confirmed_at');
    }

    protected function json(FilteredRequest $request)
    {
        $query = $this->base()->with($this->eager());

        if ($search = trim((string) $request->get('search', ''))) {
            $this->applySearch($query, $search);
        }

        $activeFilterBadges = $this->queryFilters($query, $request->filters, ['scope' => $this->scope()]);

        [$column, $direction] = $this->order($request);
        $query->orderBy($column, $direction);

        return $this->collection($query->paginate(Statamic::cpPerPage($request->get('perPage'))))
            ->additional(['meta' => ['activeFilterBadges' => $activeFilterBadges]]);
    }

    /**
     * @param  Builder<Model>  $query
     */
    protected function applySearch(Builder $query, string $term): void
    {
        // `%` und `_` sind LIKE-Wildcards; die ESCAPE-Klausel steht da, weil
        // SQLite anders als MySQL und Postgres keine voreingestellte hat.
        $escaped = addcslashes($term, '%_\\');

        $query->where(function (Builder $q) use ($escaped) {
            foreach ($this->searchable() as $column) {
                $q->orWhereRaw($column." LIKE ? ESCAPE '\\'", ['%'.$escaped.'%']);
            }
        });
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function order(FilteredRequest $request): array
    {
        // Positivliste: `sort` kommt aus der Query und ginge sonst auf jede
        // Spalte der Tabelle.
        $sortable = $this->sortable();

        $requested = (string) $request->get('sort', 'confirmed_at');
        $direction = strtolower((string) $request->get('order', 'desc')) === 'asc' ? 'asc' : 'desc';

        return [$sortable[$requested] ?? 'confirmed_at', $direction];
    }

    /**
     * Die Wörter, die beide Bildschirme teilen.
     *
     * @return array<string, string>
     */
    protected function sharedStrings(): array
    {
        return [
            'utilities' => __('Utilities'),
            'none' => __('statamic-payments::messages.none'),
            'handled' => __('statamic-payments::messages.legal_handled'),
            'open' => __('statamic-payments::messages.legal_open'),
            'note' => __('statamic-payments::messages.legal_handled_note'),
            'detail_action' => __('statamic-payments::messages.subscription_detail_action'),
            'field_name' => __('statamic-payments::messages.legal_field_name'),
            'field_email' => __('statamic-payments::messages.legal_field_email'),
            'field_declared_at' => __('statamic-payments::messages.legal_field_declared_at'),
            'field_confirmed_at' => __('statamic-payments::messages.legal_field_confirmed_at'),
            'field_receipt_sent_at' => __('statamic-payments::messages.legal_field_receipt_sent_at'),
            'field_merchant_notified_at' => __('statamic-payments::messages.legal_field_merchant_notified_at'),
            'field_handled_at' => __('statamic-payments::messages.legal_field_handled_at'),
            'receipt_missing' => __('statamic-payments::messages.legal_receipt_missing'),
        ];
    }
}
