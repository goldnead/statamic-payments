<?php

namespace Goldnead\StatamicPayments\Http\Controllers;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\ThanksLink;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Where the provider sends the buyer back to when the thank-you page expires (P5).
 *
 * Valid signature: the visit is admitted and forwarded to the page. Expired or
 * tampered: a short page of this addon, or `thanks.expired_url`. The target is
 * part of what was signed, so it cannot be swapped for another site.
 */
class ThanksController extends Controller
{
    public function __invoke(Request $request, ThanksLink $thanks, string $payPayment)
    {
        $payment = Payment::find((int) $payPayment);

        if ($payment === null || ! $request->hasValidSignature()) {
            return $this->expired();
        }

        // The window starts at the first visit, not at the checkout.
        $until = $thanks->windowFor($payment);

        if ($until === null) {
            return $this->expired();
        }

        $thanks->admit($request, $payment, $until);

        $to = (string) $request->query('to', '');

        return redirect($to !== '' ? $to : url((string) config('statamic-payments.return_url', '/')));
    }

    protected function expired()
    {
        $elsewhere = config('statamic-payments.thanks.expired_url');

        if (is_string($elsewhere) && trim($elsewhere) !== '') {
            return redirect($elsewhere);
        }

        return response()->view('statamic-payments::thanks.expired', [
            'portal' => config('statamic-payments.portal.enabled', true)
                ? route('statamic-payments.portal.request')
                : null,
        ], 410);
    }
}
