<?php

namespace Goldnead\StatamicPayments\Http\Controllers\Portal;

use Goldnead\StatamicPayments\Contracts\MandateGateway;
use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Portal\Display;
use Goldnead\StatamicPayments\Support\Invoices;
use Illuminate\Http\Request;

/**
 * What somebody bought, and what is still running.
 *
 * One screen for both, because they are one question: "what does this shop have
 * of mine". Splitting them would mean a buyer with a subscription and a
 * one-off purchase has to be told there are two places to look.
 */
class OrdersController extends PortalController
{
    public function index(Request $request)
    {
        $access = $this->access($request);

        if ($access === null) {
            return $this->askForALink();
        }

        return response()->view('statamic-payments::portal.orders', [
            'email' => $access->email,
            'orders' => $this->orders->ordersFor($access)->map(fn (Payment $payment) => [
                'id' => $payment->getKey(),
                'name' => $this->nameOf($payment->product),
                // Formatted here rather than in the template. `amount()` is
                // the provider's format — a decimal point, no separators — and
                // `19.00 EUR` on a German page next to a receipt that says
                // `19,00 €` is the tell that a screen was assembled.
                'amount' => Display::money((int) $payment->amount_cent, $payment->currency),
                'paid_at' => $payment->paid_at,
                'refunded' => $payment->refunded_cent > 0,
            ])->all(),
            'subscriptions' => $this->orders->subscriptionsFor($access)
                ->map(fn (Subscription $subscription) => $this->asRow($subscription))
                ->all(),
        ]);
    }

    public function order(Request $request, string $payOrder)
    {
        $access = $this->access($request);

        if ($access === null) {
            return $this->askForALink();
        }

        $payment = $this->orders->orderFor($access, (int) $payOrder);

        // 404, not 403. Whether an order exists that this person may not see is
        // exactly the thing a numbered URL must not be able to answer.
        abort_if($payment === null, 404);

        return response()->view('statamic-payments::portal.order', [
            'payment' => $payment,
            'name' => $this->nameOf($payment->product),
            'lines' => $payment->items,
            // Asked here and not in the listing. It is one lookup per order the
            // buyer actually opens, against one per row of a page they may only
            // be scanning — and on the sibling's side that lookup may be a query
            // and a render.
            'invoice' => Invoices::forPayment($payment),
        ]);
    }

    /**
     * A running agreement, as a buyer needs to read it.
     *
     * The cancel route is only offered where the agreement is actually live.
     * A „Verträge hier kündigen" button on something that already ended is not a
     * legal nicety, it is a button that produces an error.
     *
     * @return array<string, mixed>
     */
    protected function asRow(Subscription $subscription): array
    {
        $gateway = app(PaymentGateway::class);

        return [
            'id' => $subscription->getKey(),
            'name' => $this->nameOf($subscription->product),
            'amount' => Display::money((int) $subscription->amount_cent, $subscription->currency),
            'currency' => $subscription->currency,
            'rhythm' => Display::rhythm($subscription->interval),
            'status' => $subscription->status,
            'live' => $subscription->isLive(),
            'next_payment_at' => $subscription->next_payment_at,
            'cancelled_at' => $subscription->cancelled_at,
            'remaining' => $subscription->remaining(),
            // Two conditions, both real: the provider has to be able to take a
            // new mandate at all, and this agreement has to have one to replace.
            // Neither is a property of the screen, which is why the screen asks.
            'can_change_method' => $subscription->isLive()
                && $gateway instanceof MandateGateway
                && $gateway->supportsMandateUpdate()
                && $subscription->customer_reference !== '',
            'verification' => $gateway instanceof MandateGateway
                ? Display::money($gateway->mandateVerificationCent(), $subscription->currency)
                : '',
        ];
    }
}
