<?php

namespace Goldnead\StatamicPayments\Scopes;

use Goldnead\StatamicPayments\Models\Subscription;
use Statamic\Query\Scopes\Filter;

/**
 * The filter this screen is opened for: what is still being charged.
 *
 * "Still running" is the model's own question — `isLive()` — and this filter
 * asks it of the query rather than of the rows. Fetching a page and then
 * dropping the finished ones in PHP would leave the pager counting rows the
 * screen does not show: page 1 with four entries, page 2 with eleven, a total
 * that matches neither.
 */
class SubscriptionLive extends Filter
{
    public $pinned = true;

    /**
     * The statuses `isLive()` answers true for, asked of the model rather than
     * copied out of it. A second list here would be a list to forget: add a
     * status the provider can still charge on, and this filter would go on
     * quietly hiding it.
     *
     * @return list<string>
     */
    public static function liveStatuses(): array
    {
        return array_values(array_filter(
            Subscription::statuses(),
            fn (string $status) => (new Subscription(['status' => $status]))->isLive()
        ));
    }

    public static function title()
    {
        return __('statamic-payments::messages.subscription_filter_running');
    }

    public function fieldItems()
    {
        return [
            'running' => [
                'type' => 'select',
                'placeholder' => __('statamic-payments::messages.filter_any'),
                'options' => [
                    'yes' => __('statamic-payments::messages.subscription_filter_running_yes'),
                    'no' => __('statamic-payments::messages.subscription_filter_running_no'),
                ],
            ],
        ];
    }

    public function apply($query, $values)
    {
        $choice = $values['running'] ?? null;

        if ($choice === 'yes') {
            $query->whereIn('status', self::liveStatuses());
        }

        if ($choice === 'no') {
            $query->whereNotIn('status', self::liveStatuses());
        }
    }

    public function badge($values)
    {
        return match ($values['running'] ?? null) {
            'yes' => __('statamic-payments::messages.subscription_filter_running_yes'),
            'no' => __('statamic-payments::messages.subscription_filter_running_no'),
            default => null,
        };
    }

    public function visibleTo($key)
    {
        return $key === 'statamic-payments-subscriptions';
    }
}
