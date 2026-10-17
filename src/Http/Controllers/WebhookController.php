<?php

namespace Goldnead\StatamicPayments\Http\Controllers;

use Goldnead\StatamicPayments\Support\Fulfilment;
use Goldnead\StatamicPayments\Support\ProviderUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController
{
    /**
     * The provider posts an id. That is all this reads.
     *
     * No signature is checked, and none is needed: the id is not trusted
     * either. Whatever arrives, the status is fetched from the provider, so the
     * worst a forged call can do is make us ask about a payment that is not
     * paid. That is a stronger position than a signature would buy, because it
     * does not depend on a shared secret staying secret.
     *
     * 200 once the shape is right — a non-2xx makes the provider retry, and
     * there is nothing to retry when the answer is "that payment is not paid".
     * Two exceptions, both of which *should* be retried: the provider could not
     * be asked (503 below, mapped by the gateway from a timeout, a 5xx or a
     * refused connection), and a listener throwing (left to reach the error
     * handler; the fulfilment claim is released for the next delivery).
     */
    public function __invoke(Request $request, Fulfilment $fulfilment): JsonResponse
    {
        $id = $request->input('id');

        if (! is_string($id) || $id === '' || strlen($id) > 191) {
            return response()->json(['message' => __('statamic-payments::messages.missing_payment_id')], 422);
        }

        try {
            $fulfilment->handle($id);
        } catch (ProviderUnavailable $e) {
            // Mollie could not be asked, so nothing is known about this
            // payment yet. A 5xx is the one answer that makes Mollie call again
            // (10 calls over 26 hours, https://docs.mollie.com/reference/webhooks);
            // a 200 would end it with the buyer paid and the order not done.
            Log::warning('statamic-payments: the provider could not be asked; answering 503 so it redelivers.', [
                'provider_id' => $id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'try again later'], 503);
        }

        // Deliberately says nothing about what happened. A webhook endpoint
        // that reported "unknown payment" would answer, for anyone who asked,
        // which ids this site has seen.
        return response()->json(['received' => true]);
    }
}
