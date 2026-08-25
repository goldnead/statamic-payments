<?php

namespace Goldnead\StatamicPayments\Http\Controllers;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\FollowUp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Accepting a follow-up offer.
 *
 * A normal form post with a CSRF token, deliberately: this is a browser, a
 * person and an order, and it must look like one. The webhook endpoint next
 * door drops CSRF because its caller is a server; doing the same here would
 * mean a page on another site could place an order on this one.
 */
class OfferController
{
    public function __invoke(Request $request, FollowUp $followUp): RedirectResponse
    {
        $data = $request->validate([
            'payment' => ['required', 'integer'],
            'product' => ['required', 'string', 'max:191'],
            // The order button's own checkbox. Not decoration: it is the record
            // that the person clicked something labelled as an order, and it is
            // what makes the request distinguishable from a stray POST.
            'confirmed' => ['accepted'],
        ]);

        $original = Payment::find($data['payment']);

        if (! $original) {
            return back()->withErrors(['offer' => __('statamic-payments::messages.offer_refused')]);
        }

        $follow = $followUp->accept($original, $data['product'], [
            'accepted_at' => now()->toIso8601String(),
            'from' => (string) $request->headers->get('referer'),
        ]);

        if (! $follow) {
            // Refused. The buyer sees a plain message rather than a charge that
            // silently did not happen.
            Log::warning('statamic-payments: a follow-up offer could not be accepted.', [
                'parent_payment_id' => $original->getKey(),
                'product' => $data['product'],
            ]);

            return back()->withErrors(['offer' => __('statamic-payments::messages.offer_refused')]);
        }

        return back()->with('statamic-payments.offer', [
            'payment' => $follow->getKey(),
            'status' => $follow->status,
        ]);
    }
}
