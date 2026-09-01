<?php

namespace Goldnead\StatamicPayments\Http\Resources\Cp;

use Illuminate\Http\Resources\Json\ResourceCollection;
use Statamic\CP\Column;
use Statamic\CP\Columns;
use Statamic\Http\Resources\CP\Concerns\HasRequestedColumns;

/**
 * Das Listing-Payload für Widerrufe und Kündigungen, gebaut wie das der
 * Zahlungen: `HasRequestedColumns` plus `setPreferred()` machen den
 * Spaltenwähler echt, `meta.columns` steht in jeder Antwort.
 */
abstract class LegalRequestsCollection extends ResourceCollection
{
    use HasRequestedColumns;

    protected $columns;

    protected ?string $columnPreferenceKey = null;

    /** @return list<Column> */
    abstract protected function columnList(): array;

    public function columnPreferenceKey(string $key): self
    {
        $this->columnPreferenceKey = $key;

        return $this;
    }

    private function setColumns(): self
    {
        $columns = new Columns($this->columnList());

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
