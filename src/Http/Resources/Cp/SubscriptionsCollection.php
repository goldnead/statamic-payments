<?php

namespace Goldnead\StatamicPayments\Http\Resources\Cp;

use Illuminate\Http\Resources\Json\ResourceCollection;
use Statamic\CP\Column;
use Statamic\CP\Columns;
use Statamic\Http\Resources\CP\Concerns\HasRequestedColumns;

/**
 * The listing payload, built the way core builds its own.
 *
 * Same shape as the payments listing next door, and for the same two reasons:
 * without `HasRequestedColumns` and `setPreferred()` the column picker is a
 * control that reports success and changes nothing, and without `meta.columns`
 * on *every* response the Listing throws inside its own promise and shows
 * "Something went wrong" over a screen where everything works.
 */
class SubscriptionsCollection extends ResourceCollection
{
    use HasRequestedColumns;

    public $collects = ListedSubscription::class;

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
            Column::make('product')->label(__('statamic-payments::messages.subscription_column_product'))->sortable(true)->defaultOrder(1),
            // Not sortable. The cell says "subscription" or "payment plan",
            // and the column behind it is `times` — ordering by it would put
            // the plans in price-of-nothing order and call it a sort.
            Column::make('kind')->label(__('statamic-payments::messages.subscription_column_kind'))->sortable(false)->defaultOrder(2),
            // Sortable, and the sort runs on `amount_cent` behind it: ordering
            // the formatted string puts 9.00 above 19.00.
            Column::make('amount')->label(__('statamic-payments::messages.subscription_column_amount'))->sortable(true)->numeric(true)->defaultOrder(3),
            // "1 month", "12 weeks" — a string in the provider's vocabulary,
            // with no order that means anything.
            Column::make('rhythm')->label(__('statamic-payments::messages.subscription_column_rhythm'))->sortable(false)->defaultOrder(4),
            Column::make('progress')->label(__('statamic-payments::messages.subscription_column_progress'))->sortable(true)->numeric(true)->defaultOrder(5),
            Column::make('next_payment_at')->label(__('statamic-payments::messages.subscription_column_next_payment'))->sortable(true)->defaultOrder(6),
            Column::make('status')->label(__('statamic-payments::messages.subscription_column_status'))->sortable(true)->defaultOrder(7),
            Column::make('email')->label(__('statamic-payments::messages.subscription_column_email'))->sortable(true)->defaultOrder(8),
            Column::make('provider_id')->label(__('statamic-payments::messages.subscription_column_provider_id'))->sortable(false)->defaultOrder(9)->defaultVisibility(false)->visible(false),
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
                'columns' => $this->visibleColumns(),
            ],
        ];
    }
}
