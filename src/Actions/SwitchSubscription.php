<?php

namespace Goldnead\StatamicPayments\Actions;

use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Anrede;
use Goldnead\StatamicPayments\Support\SubscriptionSwitches;
use Statamic\Actions\Action;
use Statamic\Facades\CP\Toast;

/**
 * Moving one agreement to another product (P2).
 *
 * One row at a time: which products are possible depends on the row's own
 * rhythm and currency, so a bulk selection has no list that fits all of them.
 * The options come from `SubscriptionSwitches::targetsFor()`, and `run()` asks
 * again, because the list the browser shows is not a promise.
 */
class SwitchSubscription extends Action
{
    protected static $handle = 'statamic_payments_switch_subscription';

    public static function title()
    {
        return Anrede::trans('statamic-payments::subscriptions.switch');
    }

    public function icon(): string
    {
        return 'arrow-right';
    }

    protected function fieldItems()
    {
        $subscription = $this->items->first();
        $options = $subscription instanceof Subscription
            ? app(SubscriptionSwitches::class)->targetsFor($subscription)
            : [];

        return [
            'to' => [
                'type' => 'select',
                'display' => Anrede::trans('statamic-payments::subscriptions.switch_to'),
                'instructions' => Anrede::trans('statamic-payments::subscriptions.switch_to_instructions'),
                'options' => $options,
                'validate' => 'required',
            ],
        ];
    }

    public function visibleTo($item)
    {
        return $item instanceof Subscription && app(SubscriptionSwitches::class)->targetsFor($item) !== [];
    }

    public function visibleToBulk($items)
    {
        return $items->count() === 1 && $this->visibleTo($items->first());
    }

    public function authorize($user, $item)
    {
        // Seeing the screen is the utility's right; changing what somebody
        // pays is its own (Gauntlet 23.09.2026).
        return $user->can('access subscriptions utility') && $user->can('manage payment subscriptions');
    }

    public function buttonText()
    {
        /** @translation */
        return Anrede::trans('statamic-payments::subscriptions.switch');
    }

    public function confirmationText()
    {
        return Anrede::trans('statamic-payments::subscriptions.switch_confirm');
    }

    public function run($items, $values)
    {
        $to = (string) ($values['to'] ?? '');
        $switches = app(SubscriptionSwitches::class);

        [$switched, $refused] = $items->partition(
            fn (Subscription $subscription) => $switches->switch($subscription, $to, 'cp')
        );

        if ($refused->isEmpty()) {
            return trans_choice('statamic-payments::subscriptions.switched_bulk', $switched->count(), ['count' => $switched->count()]);
        }

        Toast::error(Anrede::trans('statamic-payments::subscriptions.switch_failed'));

        return ['message' => false];
    }
}
