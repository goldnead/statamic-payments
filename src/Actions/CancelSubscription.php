<?php

namespace Goldnead\StatamicPayments\Actions;

use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Subscriptions;
use Statamic\Actions\Action;
use Statamic\Facades\CP\Toast;

/**
 * Stopping an agreement — from one row's menu and from the bulk toolbar alike.
 *
 * One class for both, because core offers a registered action in both places
 * itself. A hand-rolled row button beside it would put two entries with the same
 * word in the same menu, and behind them two ways to write "cancelled" onto a
 * row the provider is still charging.
 *
 * What it does is call `Subscriptions::cancel()`, which tells the provider first
 * and writes what the provider answered.
 *
 * **Reporting a refusal is the delicate part.** Core toasts an action's return
 * value green whatever it says, and an exception thrown out of `run()` becomes
 * `success: false` — which the Control Panel then toasts green as well, because
 * the request itself succeeded. So neither channel can carry a failure. What
 * can is a toast pushed from the server: it travels in `_toasts` on the same
 * response and carries its own severity. `message: false` is core's documented
 * way of asking for no toast of its own, and it is what keeps the green one
 * from appearing beside the red.
 *
 * Actions are registered globally and offered on every listing in the Control
 * Panel, so `visibleTo` is not decoration: without it this turns up in the bulk
 * toolbar of the Entries screen. And without `authorize` it is a writing
 * endpoint with no lock on it, reachable by anybody who can open the CP at all.
 */
class CancelSubscription extends Action
{
    protected static $handle = 'statamic_payments_cancel_subscription';

    protected $dangerous = true;

    public static function title()
    {
        return __('statamic-payments::messages.subscription_cancel');
    }

    public function icon(): string
    {
        return 'x-square';
    }

    public function visibleTo($item)
    {
        // Only what the provider will still charge. Offering it on a finished
        // payment plan means a call the provider answers with a shrug and a
        // failure nobody caused.
        return $item instanceof Subscription && $item->isLive();
    }

    public function authorize($user, $item)
    {
        return $user->can('access subscriptions utility');
    }

    public function buttonText()
    {
        /** @translation */
        return __('statamic-payments::messages.subscription_cancel');
    }

    public function confirmationText()
    {
        return __('statamic-payments::messages.subscription_cancel_confirm');
    }

    public function run($items, $values)
    {
        $subscriptions = app(Subscriptions::class);

        [$cancelled, $refused] = $items->partition(
            fn (Subscription $subscription) => $subscriptions->cancel($subscription)
        );

        if ($refused->isEmpty()) {
            return trans_choice(
                'statamic-payments::messages.subscription_cancelled_bulk',
                $cancelled->count(),
                ['count' => $cancelled->count()]
            );
        }

        Toast::error(__('statamic-payments::messages.subscription_cancel_failed', [
            'failed' => $refused->count(),
            'total' => $items->count(),
        ]));

        // `false`, not an empty string: core toasts `message || 'Action
        // completed'`, so anything falsy-but-not-false still puts a green
        // "Action completed" next to the red one.
        return ['message' => false];
    }
}
