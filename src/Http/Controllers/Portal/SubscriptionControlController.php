<?php

namespace Goldnead\StatamicPayments\Http\Controllers\Portal;

use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Portal\Display;
use Goldnead\StatamicPayments\Portal\PortalAccess;
use Goldnead\StatamicPayments\Support\SubscriptionPauses;
use Goldnead\StatamicPayments\Support\SubscriptionSwitches;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Pausing, resuming and switching from the buyer's own screen (P1, P2).
 *
 * Same pattern as the cancellation next door: a GET that shows what will
 * happen, a POST that does it, and never one route doing both. Every write asks
 * `SubscriptionPauses` or `SubscriptionSwitches` whether this buyer may, for this
 * product, right now — the button on the page is not the permission.
 */
class SubscriptionControlController extends PortalController
{
    public function pauseConfirm(Request $request, string $paySubscription)
    {
        [$access, $subscription] = $this->mine($request, $paySubscription);

        if ($access === null) {
            return $this->askForALink();
        }

        abort_if($subscription === null, 404);

        if (! app(SubscriptionPauses::class)->portalMayPause($subscription)) {
            return $this->backToOrders(__('statamic-payments::subscriptions.portal_pause_unavailable'));
        }

        return response()->view('statamic-payments::portal.pause', [
            'subscription' => $subscription,
            'name' => $this->nameOf($subscription->product),
            'min' => Carbon::tomorrow()->toDateString(),
        ]);
    }

    public function pause(Request $request, string $paySubscription)
    {
        [$access, $subscription] = $this->mine($request, $paySubscription);

        if ($access === null) {
            return $this->askForALink();
        }

        abort_if($subscription === null, 404);

        $pauses = app(SubscriptionPauses::class);

        if (! $pauses->portalMayPause($subscription)) {
            return $this->backToOrders(__('statamic-payments::subscriptions.portal_pause_unavailable'));
        }

        $resumeOn = null;
        $raw = trim((string) $request->input('resume_on', ''));

        if ($raw !== '') {
            try {
                $resumeOn = Carbon::parse($raw)->startOfDay();
            } catch (Throwable) {
                $resumeOn = null;
            }

            if ($resumeOn === null || $resumeOn->lte(Carbon::today())) {
                return redirect()
                    ->route('statamic-payments.portal.pause.confirm', ['paySubscription' => $subscription->getKey()])
                    ->with('statamic-payments.portal.error', __('statamic-payments::subscriptions.portal_pause_date_invalid'));
            }
        }

        if (! $pauses->pause($subscription, $resumeOn, 'portal')) {
            return $this->backToOrders(__('statamic-payments::subscriptions.portal_pause_failed'));
        }

        return redirect()
            ->route('statamic-payments.portal.show')
            ->with('statamic-payments.portal.status', __('statamic-payments::subscriptions.portal_paused'));
    }

    public function resume(Request $request, string $paySubscription)
    {
        [$access, $subscription] = $this->mine($request, $paySubscription);

        if ($access === null) {
            return $this->askForALink();
        }

        abort_if($subscription === null, 404);

        // Whoever may pause here may resume; and a pause the shop set in the
        // Control Panel may be ended by the buyer too, where pausing is allowed
        // for this product at all.
        if (! $subscription->isPaused() || ! app(SubscriptionPauses::class)->portalAllows($subscription)) {
            return $this->backToOrders(__('statamic-payments::subscriptions.portal_pause_unavailable'));
        }

        if (! app(SubscriptionPauses::class)->resume($subscription, 'portal')) {
            return $this->backToOrders(__('statamic-payments::subscriptions.portal_resume_failed'));
        }

        $subscription = $subscription->fresh() ?? $subscription;

        return redirect()
            ->route('statamic-payments.portal.show')
            ->with('statamic-payments.portal.status', __('statamic-payments::subscriptions.portal_resumed', [
                'date' => $subscription->next_payment_at?->translatedFormat(__('statamic-payments::portal.date_format')) ?? '',
            ]));
    }

    public function switchConfirm(Request $request, string $paySubscription)
    {
        [$access, $subscription] = $this->mine($request, $paySubscription);

        if ($access === null) {
            return $this->askForALink();
        }

        abort_if($subscription === null, 404);

        $switches = app(SubscriptionSwitches::class);
        $targets = $switches->targetsFor($subscription, portal: true);

        if ($targets === []) {
            return $this->backToOrders(__('statamic-payments::subscriptions.portal_switch_unavailable'));
        }

        $choices = [];

        foreach ($targets as $handle => $name) {
            $preview = $switches->preview($subscription, $handle);

            if ($preview === null) {
                continue;
            }

            $choices[] = [
                'handle' => $handle,
                'name' => $name,
                'amount' => Display::money($preview['to_amount_cent'], $subscription->currency),
                'rhythm' => Display::rhythm((string) $subscription->interval),
                'effect' => $preview['immediate']
                    ? ($preview['proration_cent'] > 0
                        ? __('statamic-payments::subscriptions.portal_switch_now_charge', ['amount' => Display::money($preview['proration_cent'], $subscription->currency)])
                        : __('statamic-payments::subscriptions.portal_switch_now_free'))
                    : __('statamic-payments::subscriptions.portal_switch_later', [
                        'date' => $preview['effective_at']?->translatedFormat(__('statamic-payments::portal.date_format')) ?? '',
                    ]),
            ];
        }

        return response()->view('statamic-payments::portal.switch', [
            'subscription' => $subscription,
            'name' => $this->nameOf($subscription->product),
            'choices' => $choices,
        ]);
    }

    public function switch(Request $request, string $paySubscription)
    {
        [$access, $subscription] = $this->mine($request, $paySubscription);

        if ($access === null) {
            return $this->askForALink();
        }

        abort_if($subscription === null, 404);

        $to = (string) $request->input('to', '');
        $switches = app(SubscriptionSwitches::class);

        if (! array_key_exists($to, $switches->targetsFor($subscription, portal: true))) {
            return $this->backToOrders(__('statamic-payments::subscriptions.portal_switch_unavailable'));
        }

        if (! $switches->switch($subscription, $to, 'portal')) {
            return $this->backToOrders(__('statamic-payments::subscriptions.portal_switch_failed'));
        }

        return redirect()
            ->route('statamic-payments.portal.show')
            ->with('statamic-payments.portal.status', __('statamic-payments::subscriptions.portal_switched', ['name' => $this->nameOf($to)]));
    }

    /**
     * The access, and the agreement if it is this person's.
     *
     * @return array{0: PortalAccess|null, 1: Subscription|null}
     */
    protected function mine(Request $request, string $id): array
    {
        $access = $this->access($request);

        if ($access === null) {
            return [null, null];
        }

        return [$access, $this->orders->subscriptionFor($access, (int) $id)];
    }

    protected function backToOrders(string $message): RedirectResponse
    {
        return redirect()
            ->route('statamic-payments.portal.show')
            ->with('statamic-payments.portal.error', $message);
    }
}
