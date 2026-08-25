<?php

namespace Goldnead\StatamicPayments\Scopes;

use Goldnead\StatamicPayments\Models\Payment;
use Statamic\Query\Scopes\Filter;

/**
 * The filter this screen exists for.
 *
 * Mollie can tell you the money arrived. Only the site knows whether the buyer
 * got anything for it, and "paid, nothing delivered" is the one row anybody
 * ever needs to go looking for.
 *
 * The condition matches what `Support\Fulfilment` treats as fulfilled: the
 * claim sets `status = paid` and `fulfilled_at` in one statement, and a listener
 * that throws releases `fulfilled_at` again while `paid` stands. That released
 * row is exactly what this filter is meant to surface.
 */
class PaymentFulfilment extends Filter
{
    public $pinned = true;

    public static function title()
    {
        return __('statamic-payments::messages.filter_fulfilment');
    }

    public function fieldItems()
    {
        return [
            'fulfilment' => [
                'type' => 'select',
                'placeholder' => __('statamic-payments::messages.filter_any'),
                'options' => [
                    'unfulfilled' => __('statamic-payments::messages.filter_unfulfilled'),
                    'fulfilled' => __('statamic-payments::messages.filter_fulfilled'),
                ],
            ],
        ];
    }

    public function apply($query, $values)
    {
        $choice = $values['fulfilment'] ?? null;

        if ($choice === 'unfulfilled') {
            $query->whereNull('fulfilled_at')->where('status', Payment::STATUS_PAID);
        }

        if ($choice === 'fulfilled') {
            $query->whereNotNull('fulfilled_at');
        }
    }

    public function badge($values)
    {
        return match ($values['fulfilment'] ?? null) {
            'unfulfilled' => __('statamic-payments::messages.filter_unfulfilled'),
            'fulfilled' => __('statamic-payments::messages.filter_fulfilled'),
            default => null,
        };
    }

    public function visibleTo($key)
    {
        return $key === 'statamic-payments-payments';
    }
}
