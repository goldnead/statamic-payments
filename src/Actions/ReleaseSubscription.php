<?php

namespace Goldnead\StatamicPayments\Actions;

use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Anrede;
use Illuminate\Support\Facades\Log;
use Statamic\Actions\Action;

/**
 * Releasing a switch a dead process left behind (Gauntlet 23.09.2026).
 *
 * The sweep cannot read back whether the provider took the new amount of a
 * switch that changed it in place, so it leaves the row in `switching` and logs
 * an error. A person checks the provider's dashboard and says which is true:
 * the new product (the provider charges the new amount) or the old one. Only
 * offered on a switch older than `Subscription::CLAIM_STALE_MINUTES`.
 */
class ReleaseSubscription extends Action
{
    protected static $handle = 'statamic_payments_release_subscription';

    protected $dangerous = true;

    public static function title()
    {
        return Anrede::trans('statamic-payments::subscriptions.release');
    }

    public function icon(): string
    {
        return 'synced';
    }

    protected function fieldItems()
    {
        return [
            'keep' => [
                'type' => 'select',
                'display' => Anrede::trans('statamic-payments::subscriptions.release_keep'),
                'instructions' => Anrede::trans('statamic-payments::subscriptions.release_keep_instructions'),
                'options' => [
                    'new' => Anrede::trans('statamic-payments::subscriptions.release_keep_new'),
                    'old' => Anrede::trans('statamic-payments::subscriptions.release_keep_old'),
                ],
                'validate' => 'required|in:new,old',
            ],
        ];
    }

    public function visibleTo($item)
    {
        return $item instanceof Subscription
            && $item->status === Subscription::STATUS_SWITCHING
            && $item->isStuck();
    }

    public function authorize($user, $item)
    {
        return $user->can('access subscriptions utility') && $user->can('manage payment subscriptions');
    }

    public function buttonText()
    {
        /** @translation */
        return Anrede::trans('statamic-payments::subscriptions.release');
    }

    public function run($items, $values)
    {
        $keep = ($values['keep'] ?? 'new') === 'old' ? 'old' : 'new';

        foreach ($items as $subscription) {
            $meta = $subscription->meta ?? [];
            $switching = is_array($meta['switching'] ?? null) ? $meta['switching'] : [];
            unset($meta['switching']);

            $update = ['status' => Subscription::STATUS_ACTIVE, 'meta' => $meta === [] ? null : $meta];

            if ($keep === 'old' && isset($switching['from'], $switching['from_amount_cent'])) {
                $update['product'] = (string) $switching['from'];
                $update['amount_cent'] = (int) $switching['from_amount_cent'];
            }

            // Kept new: the switch happened, and a difference charged for it
            // is used. Kept old: it stays unused, and the next try of the
            // same switch takes it instead of charging again.
            if ($keep === 'new' && isset($switching['from'], $switching['to'])) {
                $meta['switches'] = array_values(array_merge((array) ($meta['switches'] ?? []), [[
                    'from' => $switching['from'],
                    'to' => $switching['to'],
                    'from_amount_cent' => $switching['from_amount_cent'] ?? null,
                    'to_amount_cent' => $switching['to_amount_cent'] ?? null,
                    'proration_payment_id' => $switching['proration_payment_id'] ?? null,
                    'by' => 'release',
                    'at' => now()->toIso8601String(),
                ]]));
                $update['meta'] = $meta;
            }

            $subscription->forceFill($update)->save();

            Log::warning('statamic-payments: a switch left behind was released by hand.', [
                'subscription_id' => $subscription->getKey(),
                'kept' => $keep,
            ]);
        }

        return trans_choice('statamic-payments::subscriptions.released_bulk', $items->count(), ['count' => $items->count()]);
    }
}
