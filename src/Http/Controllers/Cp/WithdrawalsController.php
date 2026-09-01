<?php

namespace Goldnead\StatamicPayments\Http\Controllers\Cp;

use Goldnead\StatamicPayments\Http\Resources\Cp\WithdrawalsCollection;
use Goldnead\StatamicPayments\Models\Withdrawal;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Der Bildschirm „Widerrufe": was nach § 356a BGB eingegangen ist.
 *
 * Er zeigt, ordnet zu, und lässt einen Vorgang als erledigt markieren. Was
 * nicht hier passiert: die Erstattung. Die gehört zu Mollie, wie jede andere
 * Erstattung auch, und die Zeile hier sagt nur, dass jemand sich gekümmert hat.
 */
class WithdrawalsController extends LegalRequestsController
{
    protected function handle(): string
    {
        return 'withdrawals';
    }

    protected function model(): string
    {
        return Withdrawal::class;
    }

    protected function component(): string
    {
        return 'statamic-payments::Withdrawals/Index';
    }

    protected function collection($rows): ResourceCollection
    {
        return (new WithdrawalsCollection($rows))
            ->columnPreferenceKey('statamic-payments.withdrawals.columns');
    }

    protected function eager(): array
    {
        return ['payment'];
    }

    protected function sortable(): array
    {
        return [
            'public_id' => 'public_id',
            'confirmed_at' => 'confirmed_at',
            'email' => 'email',
            'order_reference' => 'order_reference',
            'handled_at' => 'handled_at',
        ];
    }

    protected function searchable(): array
    {
        return ['public_id', 'email', 'name', 'order_reference'];
    }

    protected function strings(): array
    {
        return $this->sharedStrings() + [
            'title' => __('statamic-payments::messages.withdrawals_utility_title'),
            'empty_heading' => __('statamic-payments::messages.withdrawals_empty_heading'),
            'empty_title' => __('statamic-payments::messages.withdrawals_empty_title'),
            'empty_description' => __('statamic-payments::messages.withdrawals_empty_description'),
            'field_reference' => __('statamic-payments::messages.withdrawal_column_reference'),
            'field_contact' => __('statamic-payments::messages.withdrawal_field_contact'),
            'field_message' => __('statamic-payments::messages.withdrawal_field_message'),
            'field_payment' => __('statamic-payments::messages.withdrawal_column_payment'),
            'expired_hint' => __('statamic-payments::messages.withdrawal_expired_hint'),
            'outside_period' => __('statamic-payments::messages.withdrawal_outside_period'),
            'unmatched' => __('statamic-payments::messages.withdrawal_unmatched'),
        ];
    }
}
