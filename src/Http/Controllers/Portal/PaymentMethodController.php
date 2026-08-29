<?php

namespace Goldnead\StatamicPayments\Http\Controllers\Portal;

use Goldnead\StatamicPayments\Contracts\MandateGateway;
use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Putting a different card on file.
 *
 * The whole of this controller is: check who is asking, check the provider can
 * do it, hand the provider the buyer, redirect. There is no local state to
 * write, and that is deliberate — see below.
 *
 * **One provider today, room for a second.** `ServiceProvider` binds
 * `PaymentGateway` to `MollieGateway`, hard, and that stays true: this screen
 * does not know the word Mollie. It asks whether whatever is bound implements
 * {@see MandateGateway} and offers the button only if it does. A second provider
 * is a class and a binding, not a change here. Building that second provider was
 * not part of this work and none of it is speculative on its behalf: the
 * interface has exactly the three methods this screen calls.
 *
 * **No row is written for the verification charge.** On Mollie the only way to
 * establish a mandate is a real payment, so the buyer is charged a small amount
 * — and that payment is deliberately created without a webhook URL. If it had
 * one, the provider would deliver a paid payment this site has no local row for
 * into the fulfilment path, where it is correctly treated as a phantom purchase
 * and logged as an alarm. A loud alarm about something that went exactly to plan
 * is worse than no record, and the record that matters is at the provider, where
 * the mandate is.
 */
class PaymentMethodController extends PortalController
{
    public function start(Request $request, string $paySubscription)
    {
        $access = $this->access($request);

        if ($access === null) {
            return $this->askForALink();
        }

        $subscription = $this->orders->subscriptionFor($access, (int) $paySubscription);

        abort_if($subscription === null, 404);

        $gateway = app(PaymentGateway::class);

        // Not an abort. A provider that cannot do this, or an agreement that has
        // already ended, is a button that should not have been on the page — and
        // the buyer who got there anyway is told what is true rather than shown
        // a 500.
        if (! $gateway instanceof MandateGateway || ! $gateway->supportsMandateUpdate() || ! $subscription->isLive()) {
            return $this->back(__('statamic-payments::portal.method_unavailable'));
        }

        try {
            $session = $gateway->startMandateUpdate($subscription->customer_reference, [
                'amount' => [
                    'currency' => $subscription->currency,
                    'value' => Money::format($gateway->mandateVerificationCent(), $subscription->currency),
                ],
                'description' => __('statamic-payments::portal.method_charge_description'),
                'redirectUrl' => route('statamic-payments.portal.method.return'),
                'metadata' => [
                    // For whoever reads the provider's dashboard afterwards and
                    // wonders what this one-cent payment was.
                    'statamic_payments' => 'mandate_update',
                    'subscription_id' => $subscription->getKey(),
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('statamic-payments: the provider would not start a payment-method change.', [
                'subscription_id' => $subscription->getKey(),
                'exception' => $e->getMessage(),
            ]);

            return $this->back(__('statamic-payments::portal.method_failed'));
        }

        $this->note($subscription);

        // Away to the provider. An external redirect, so `away()` rather than
        // `to()`: the URL is the provider's and never one of ours, and routing
        // it through Laravel's URL generator would rewrite it.
        return redirect()->away($session->checkoutUrl);
    }

    /**
     * Back from the provider.
     *
     * It says nothing about whether the mandate was established — the buyer
     * reaching a return URL is not evidence, here as everywhere else in this
     * package. So the page does not claim success; it says the change was
     * started and that the next charge will use whatever the provider now holds.
     */
    public function returned(Request $request)
    {
        if ($this->access($request) === null) {
            return $this->askForALink();
        }

        return $this->back(__('statamic-payments::portal.method_returned'));
    }

    protected function note(Subscription $subscription): void
    {
        Log::info('statamic-payments: a buyer was sent to the provider to put a new payment method on file.', [
            'subscription_id' => $subscription->getKey(),
        ]);
    }

    protected function back(string $status)
    {
        return redirect()
            ->route('statamic-payments.portal.show')
            ->with('statamic-payments.portal.status', $status);
    }
}
