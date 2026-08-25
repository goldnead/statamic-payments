<?php

namespace Goldnead\StatamicPayments\Http\Resources\Cp;

use Illuminate\Http\Resources\Json\ResourceCollection;
use Statamic\CP\Column;
use Statamic\CP\Columns;
use Statamic\Http\Resources\CP\Concerns\HasRequestedColumns;

/**
 * The listing payload, built the way core builds its own.
 *
 * `HasRequestedColumns` together with `setPreferred()` is what makes the column
 * picker real. Without them the server answers every request with the same
 * fixed set, and since the Listing takes its columns from each response, the
 * picker becomes a control that reports success and changes nothing.
 */
class PaymentsCollection extends ResourceCollection
{
    use HasRequestedColumns;

    public $collects = ListedPayment::class;

    protected $columns;

    protected ?string $columnPreferenceKey = null;

    public function columnPreferenceKey(string $key): self
    {
        $this->columnPreferenceKey = $key;

        return $this;
    }

    private function setColumns(): self
    {
        $columns = new Columns([
            Column::make('created_at')->label(__('statamic-payments::messages.column_when'))->sortable(true)->defaultOrder(1),
            Column::make('product')->label(__('statamic-payments::messages.column_product'))->sortable(true)->defaultOrder(2),
            // Sortable, and the sort runs on `amount_cent` behind it. Money was
            // the one thing on a money screen you could not order by.
            Column::make('amount')->label(__('statamic-payments::messages.column_amount'))->sortable(true)->numeric(true)->defaultOrder(3),
            Column::make('status')->label(__('statamic-payments::messages.column_status'))->sortable(true)->defaultOrder(4),
            Column::make('fulfilled_at')->label(__('statamic-payments::messages.column_fulfilled'))->sortable(true)->defaultOrder(5),
            Column::make('email')->label(__('statamic-payments::messages.column_buyer'))->sortable(true)->defaultOrder(6),
            Column::make('provider_id')->label(__('statamic-payments::messages.column_provider_id'))->sortable(false)->defaultOrder(7)->defaultVisibility(false)->visible(false),
        ]);

        if ($key = $this->columnPreferenceKey) {
            $columns->setPreferred($key);
        }

        $this->columns = $columns->rejectUnlisted()->values();

        return $this;
    }

    public function toArray($request)
    {
        $this->setColumns();

        return $this->collection;
    }

    public function with($request)
    {
        return [
            'meta' => [
                // Read out of every response by the Listing. Missing, the read
                // throws inside the component's own promise and the screen
                // shows "Something went wrong" while everything on it works.
                'columns' => $this->visibleColumns(),
            ],
        ];
    }
}
