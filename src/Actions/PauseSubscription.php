<?php

namespace Goldnead\StatamicPayments\Actions;

use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\SubscriptionPauses;
use Illuminate\Support\Carbon;
use Statamic\Actions\Action;
use Statamic\Facades\CP\Toast;

/**
 * Pausing an agreement from the subscriptions screen (P1).
 *
 * The same shape as `CancelSubscription`, for the same reasons: one registered
 * action in the row menu and the bulk toolbar, `visibleTo` so it does not turn
 * up on the Entries screen, `authorize` so it is not an open endpoint, and a
 * refusal reported as a red toast from the server because core would toast
 * anything else green.
 */
class PauseSubscription extends Action
{
    protected static $handle = 'statamic_payments_pause_subscription';

    protected $fields = [
        'resume_on' => [
            'type' => 'date',
            'display' => 'statamic-payments::subscriptions.pause_resume_on',
            'instructions' => 'statamic-payments::subscriptions.pause_resume_on_instructions',
            'validate' => 'nullable|date|after:today',
        ],
    ];

    public static function title()
    {
        return __('statamic-payments::subscriptions.pause');
    }

    public function icon(): string
    {
        return 'time-clock';
    }

    protected function fieldItems()
    {
        $fields = $this->fields;
        $fields['resume_on']['display'] = __($fields['resume_on']['display']);
        $fields['resume_on']['instructions'] = __($fields['resume_on']['instructions']);

        return $fields;
    }

    public function visibleTo($item)
    {
        return $item instanceof Subscription && app(SubscriptionPauses::class)->canPause($item);
    }

    public function authorize($user, $item)
    {
        return $user->can('access subscriptions utility');
    }

    public function buttonText()
    {
        /** @translation */
        return __('statamic-payments::subscriptions.pause');
    }

    public function confirmationText()
    {
        return __('statamic-payments::subscriptions.pause_confirm');
    }

    public function run($items, $values)
    {
        $pauses = app(SubscriptionPauses::class);
        $resumeOn = ($values['resume_on'] ?? null) ? Carbon::parse(is_array($values['resume_on']) ? ($values['resume_on']['date'] ?? '') : $values['resume_on']) : null;

        [$paused, $refused] = $items->partition(
            fn (Subscription $subscription) => $pauses->pause($subscription, $resumeOn, 'cp')
        );

        if ($refused->isEmpty()) {
            return trans_choice('statamic-payments::subscriptions.paused_bulk', $paused->count(), ['count' => $paused->count()]);
        }

        Toast::error(__('statamic-payments::subscriptions.pause_failed', [
            'failed' => $refused->count(),
            'total' => $items->count(),
        ]));

        return ['message' => false];
    }
}
