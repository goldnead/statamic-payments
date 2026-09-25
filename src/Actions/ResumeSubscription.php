<?php

namespace Goldnead\StatamicPayments\Actions;

use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Anrede;
use Goldnead\StatamicPayments\Support\SubscriptionPauses;
use Statamic\Actions\Action;
use Statamic\Facades\CP\Toast;

/**
 * Taking a paused agreement up again (P1). Nothing is charged at the moment of
 * resuming; the next charge falls on the old billing day.
 */
class ResumeSubscription extends Action
{
    protected static $handle = 'statamic_payments_resume_subscription';

    public static function title()
    {
        return Anrede::trans('statamic-payments::subscriptions.resume');
    }

    public function icon(): string
    {
        return 'synced';
    }

    public function visibleTo($item)
    {
        return $item instanceof Subscription && $item->isPaused();
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
        return Anrede::trans('statamic-payments::subscriptions.resume');
    }

    public function confirmationText()
    {
        return Anrede::trans('statamic-payments::subscriptions.resume_confirm');
    }

    public function run($items, $values)
    {
        $pauses = app(SubscriptionPauses::class);

        [$resumed, $refused] = $items->partition(
            fn (Subscription $subscription) => $pauses->resume($subscription, 'cp')
        );

        if ($refused->isEmpty()) {
            return trans_choice('statamic-payments::subscriptions.resumed_bulk', $resumed->count(), ['count' => $resumed->count()]);
        }

        Toast::error(Anrede::trans('statamic-payments::subscriptions.resume_failed', [
            'failed' => $refused->count(),
            'total' => $items->count(),
        ]));

        return ['message' => false];
    }
}
