<?php

namespace Goldnead\StatamicPayments\Scopes;

use Statamic\Query\Scopes\Filter;

/**
 * Offen oder erledigt. Der eine Filter, den diese Liste braucht: was noch
 * niemand angefasst hat, ist das, was zu tun ist.
 */
class LegalHandled extends Filter
{
    public $pinned = true;

    public static function title()
    {
        return __('statamic-payments::messages.legal_filter_handled');
    }

    public function fieldItems()
    {
        return [
            'handled' => [
                'type' => 'select',
                'placeholder' => __('statamic-payments::messages.filter_any'),
                'options' => [
                    'open' => __('statamic-payments::messages.legal_open'),
                    'handled' => __('statamic-payments::messages.legal_handled'),
                ],
            ],
        ];
    }

    public function apply($query, $values)
    {
        match ($values['handled'] ?? null) {
            'open' => $query->whereNull('handled_at'),
            'handled' => $query->whereNotNull('handled_at'),
            default => null,
        };
    }

    public function badge($values)
    {
        return match ($values['handled'] ?? null) {
            'open' => __('statamic-payments::messages.legal_open'),
            'handled' => __('statamic-payments::messages.legal_handled'),
            default => null,
        };
    }

    public function visibleTo($key)
    {
        return in_array($key, ['statamic-payments-withdrawals', 'statamic-payments-cancellations'], true);
    }
}
