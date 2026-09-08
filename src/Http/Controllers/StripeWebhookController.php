<?php

namespace Goldnead\StatamicPayments\Http\Controllers;

use Goldnead\StatamicPayments\Gateways\StripeGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Fulfilment;
use Goldnead\StatamicPayments\Support\Gateways;
use Goldnead\StatamicPayments\Support\Refunds;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stripe's webhook, which is not Mollie's.
 *
 * Its own endpoint rather than a branch inside the existing one, for three
 * reasons that are all the same reason — the two providers agree on nothing:
 *
 * 1. **Mollie posts an id; Stripe posts an event.** Mollie's body is not worth
 *    reading, so it needs no signature: the status is fetched afterwards and a
 *    forged call can only ask this site to re-check a payment. Stripe's body
 *    carries a refund amount and an event type that decide what happens, so it
 *    is signed, and an unsigned body here is refused before it is parsed.
 * 2. **Stripe redelivers until it gets a 2xx.** Mollie's redelivery is harmless
 *    because fulfilment is claimed on the payment row. A second
 *    `charge.refunded` is not harmless, so the event id itself is claimed —
 *    with a unique index, not with a lookup.
 * 3. **The provider is known from the URL.** That is the whole of the gateway
 *    resolution fix at this end: a Stripe delivery is handled by the Stripe
 *    adapter, so `Fulfilment` scopes its lookups to `provider = 'stripe'` and a
 *    Stripe id can never match a Mollie row, or the other way round.
 *
 * Still 200 for anything that is genuinely Stripe's, whatever the outcome. A
 * non-2xx makes Stripe retry, and there is nothing to retry when the answer is
 * "that payment is not paid". The exception is a listener that throws: that
 * *should* be retried, so the event claim is released and the exception is left
 * to reach the error handler.
 */
class StripeWebhookController
{
    public function __invoke(Request $request, Gateways $gateways): JsonResponse
    {
        $secret = (string) config('statamic-payments.stripe.webhook_secret', '');
        $body = $request->getContent();

        // Before anything is parsed. A body this site cannot verify is not a
        // Stripe event, and reading a type or an amount out of it first would
        // be acting on a stranger's instructions.
        if (! StripeGateway::verifySignature($body, $request->header('Stripe-Signature'), $secret)) {
            // Says nothing about which half failed — a missing secret, a bad
            // signature and a stale timestamp look identical from outside.
            return response()->json(['message' => __('statamic-payments::messages.webhook_not_verified')], 400);
        }

        $event = json_decode($body, true);

        if (! is_array($event)) {
            return response()->json(['message' => __('statamic-payments::messages.webhook_not_verified')], 400);
        }

        $id = $event['id'] ?? null;
        $type = is_string($event['type'] ?? null) ? $event['type'] : null;

        if (! is_string($id) || $id === '' || strlen($id) > 191) {
            return response()->json(['message' => __('statamic-payments::messages.missing_payment_id')], 422);
        }

        if (! $this->claim($id, $type)) {
            // Seen before. Answered exactly like a fresh one so a redelivery
            // cannot be told apart from a first delivery from outside.
            return response()->json(['received' => true]);
        }

        try {
            $this->act($gateways, $type, $event['data']['object'] ?? null);
        } catch (Throwable $e) {
            // The claim is released, not held. Holding it would be "at most
            // once", whose failure mode is a buyer who paid and got nothing,
            // silently, for ever.
            $this->release($id);

            throw $e;
        }

        return response()->json(['received' => true]);
    }

    /**
     * Take this event's id, or find it is already taken.
     *
     * The insert *is* the claim. Asking first and inserting afterwards loses to
     * a second delivery that asks before the first has written, which is what
     * two redeliveries milliseconds apart are.
     */
    protected function claim(string $eventId, ?string $type): bool
    {
        try {
            DB::table('payment_webhook_events')->insert([
                'provider' => 'stripe',
                'event_id' => $eventId,
                'event_type' => $type === null ? null : mb_substr($type, 0, 191),
                'created_at' => now(),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    protected function release(string $eventId): void
    {
        DB::table('payment_webhook_events')
            ->where('provider', 'stripe')
            ->where('event_id', $eventId)
            ->delete();
    }

    /**
     * What this event means, in this package's terms.
     *
     * Only the object's **id** is taken from the event. Everything that decides
     * an outcome is fetched from Stripe afterwards, exactly as on the Mollie
     * side — a signature proves who sent a body, not that the body still
     * describes the world.
     */
    protected function act(Gateways $gateways, ?string $type, mixed $object): void
    {
        if (! is_array($object)) {
            // Loud, like everything else on this path. A verified Stripe event
            // whose object this package could not read is answered 200 and
            // never retried, so without a line here it leaves no trace at all.
            Log::warning('statamic-payments: a verified Stripe event carried no object to act on.', ['type' => $type]);

            return;
        }

        $gateway = $gateways->resolve('stripe');

        if (! $gateway instanceof StripeGateway) {
            // A host has pointed the `stripe` handle at something that is not
            // this adapter. Refused rather than half-run: this endpoint reads
            // Stripe's own event shapes and needs the two lookups only this
            // class has, so carrying on would mean acting on a body with a
            // provider that cannot answer for it.
            Log::error('statamic-payments: the `stripe` handle resolves to something that is not the Stripe adapter; the delivery was not acted on.', [
                'resolved' => $gateway::class,
            ]);

            return;
        }

        match ($type) {
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
            'checkout.session.async_payment_failed',
            'checkout.session.expired',
            'invoice.paid',
            'invoice.payment_succeeded',
            'invoice.payment_failed',
            'payment_intent.succeeded',
            'payment_intent.payment_failed' => $this->fulfil($gateway, $object),

            'charge.refunded' => $this->refund($gateway, $object),

            // Everything else Stripe sends, and it sends a lot. Ignored on
            // purpose and answered 200, so Stripe stops retrying something this
            // package was never going to act on.
            default => null,
        };
    }

    /** @param  array<string, mixed>  $object */
    protected function fulfil(StripeGateway $gateway, array $object): void
    {
        $id = $object['id'] ?? null;

        if (! is_string($id) || $id === '') {
            Log::warning('statamic-payments: a Stripe event named no object id; nothing was fulfilled.', [
                'object' => $object['object'] ?? null,
            ]);

            return;
        }

        // Built with the Stripe adapter rather than the container's default.
        // This is what stops a Stripe delivery from being looked up among
        // Mollie's rows: `Fulfilment` scopes every query to the gateway's own
        // handle, and this one says `stripe`.
        app()->makeWith(Fulfilment::class, ['gateway' => $gateway])->handle($id);
    }

    /**
     * Money that went back.
     *
     * The amounts are read from Stripe, not from the event: a charge object in
     * a webhook body carries at most the first ten refunds and `amount_refunded`
     * is a running total, so booking that number would count the first refund
     * again on the second. Each refund is recorded under its own id, and
     * `Refunds::record()` ignores one it has already seen.
     *
     * @param  array<string, mixed>  $charge
     */
    protected function refund(StripeGateway $gateway, array $charge): void
    {
        $chargeId = $charge['id'] ?? null;
        $intentId = $charge['payment_intent'] ?? null;

        if (! is_string($chargeId) || $chargeId === '' || ! is_string($intentId) || $intentId === '') {
            // A charge made outside Checkout — through the dashboard, or the
            // older API — carries no payment intent, and there is then nothing
            // to match it to. Money went back and this package cannot say for
            // which order: exactly the thing that must not pass in silence.
            Log::warning('statamic-payments: a Stripe refund arrived on a charge with no payment intent; it could not be matched to an order.', [
                'charge' => is_string($chargeId) ? $chargeId : null,
            ]);

            return;
        }

        $payment = $this->paymentFor($gateway, $intentId);

        if (! $payment) {
            // Loud, because the alternative is silence about money that left
            // the account for an order this site cannot name.
            Log::warning('statamic-payments: Stripe refunded a charge this site has no payment for.', [
                'charge' => $chargeId,
                'payment_intent' => $intentId,
            ]);

            return;
        }

        $refunds = app(Refunds::class);

        foreach ($gateway->refundsFor($chargeId) as $refund) {
            $refunds->record($payment, $refund['amount'], $refund['id']);
        }
    }

    /**
     * The row a Stripe charge belongs to.
     *
     * A first payment was stamped with the Checkout Session id, a follow-up
     * with the PaymentIntent id. Both are tried, and both are scoped to
     * `provider = 'stripe'` — a Mollie row must never be reachable from here.
     */
    protected function paymentFor(StripeGateway $gateway, string $intentId): ?Payment
    {
        $candidates = array_filter([$gateway->sessionIdForIntent($intentId), $intentId]);

        return Payment::query()
            ->where('provider', $gateway->provider())
            ->whereIn('provider_id', $candidates)
            ->first();
    }
}
