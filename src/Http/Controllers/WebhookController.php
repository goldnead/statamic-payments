<?php

namespace Goldnead\StatamicPayments\Http\Controllers;

use Goldnead\StatamicPayments\Support\Fulfilment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     * Always 200 once the shape is right — a non-2xx makes the provider retry,
     * and there is nothing to retry when the answer is "that payment is not
     * paid". The one exception is a listener throwing: that *should* be
     * retried, so the exception is left to reach the error handler and the
     * fulfilment claim is released for the next delivery.
     */
    public function __invoke(Request $request, Fulfilment $fulfilment): JsonResponse
    {
        $id = $request->input('id');

        if (! is_string($id) || $id === '' || strlen($id) > 191) {
            return response()->json(['message' => __('statamic-payments::messages.missing_payment_id')], 422);
        }

        $fulfilment->handle($id);

        // Deliberately says nothing about what happened. A webhook endpoint
        // that reported "unknown payment" would answer, for anyone who asked,
        // which ids this site has seen.
        return response()->json(['received' => true]);
    }
}
