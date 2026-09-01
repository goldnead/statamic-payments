<?php

namespace Goldnead\StatamicPayments\Http\Controllers\Cp;

use Goldnead\StatamicPayments\Http\Resources\Cp\CancellationsCollection;
use Goldnead\StatamicPayments\Models\Cancellation;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Der Bildschirm „Kündigungen": was nach § 312k BGB ohne Login eingegangen ist.
 *
 * Die Zeile sagt, ob ein Abo zugeordnet und beim Anbieter gekündigt wurde. Wo
 * nicht, ist das der Fall, um den sich jemand kümmern muss — und der steht
 * deshalb nicht in einer Fußnote, sondern in einer eigenen Spalte.
 */
class CancellationsController extends LegalRequestsController
{
    protected function handle(): string
    {
        return 'cancellations';
    }

    protected function model(): string
    {
        return Cancellation::class;
    }

    protected function component(): string
    {
        return 'statamic-payments::Cancellations/Index';
    }

    protected function collection($rows): ResourceCollection
    {
        return (new CancellationsCollection($rows))
            ->columnPreferenceKey('statamic-payments.cancellations.columns');
    }

    protected function eager(): array
    {
        return ['subscription'];
    }

    protected function sortable(): array
    {
        return [
            'public_id' => 'public_id',
            'confirmed_at' => 'confirmed_at',
            'email' => 'email',
            'identification' => 'identification',
            'kind' => 'kind',
            'effective_at' => 'effective_at',
            'handled_at' => 'handled_at',
        ];
    }

    protected function searchable(): array
    {
        return ['public_id', 'email', 'name', 'identification'];
    }

    protected function strings(): array
    {
        return $this->sharedStrings() + [
            'title' => __('statamic-payments::messages.cancellations_utility_title'),
            'empty_heading' => __('statamic-payments::messages.cancellations_empty_heading'),
            'empty_title' => __('statamic-payments::messages.cancellations_empty_title'),
            'empty_description' => __('statamic-payments::messages.cancellations_empty_description'),
            'field_identification' => __('statamic-payments::messages.cancellation_column_identification'),
            'field_kind' => __('statamic-payments::messages.cancellation_column_kind'),
            'field_reason' => __('statamic-payments::messages.cancellation_field_reason'),
            'field_effective_at' => __('statamic-payments::messages.cancellation_column_effective_at'),
            'field_subscription' => __('statamic-payments::messages.cancellation_column_subscription'),
            'field_provider_cancelled_at' => __('statamic-payments::messages.cancellation_column_provider_cancelled_at'),
            'effective_earliest' => __('statamic-payments::cancellation.effective_earliest'),
            'provider_cancelled' => __('statamic-payments::messages.cancellation_provider_cancelled'),
            'provider_not_cancelled' => __('statamic-payments::messages.cancellation_provider_not_cancelled'),
            'unmatched' => __('statamic-payments::messages.cancellation_unmatched'),
        ];
    }
}
