<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Events\PaymentFailed;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides whether money moved, and makes sure the answer is acted on once.
 *
 * The two properties this class exists for, and both are the kind that only a
 * test which *tries* to break them can demonstrate:
 *
 * 1. **The caller is never believed.** A webhook posts an id and nothing else
 *    worth reading. The status is fetched from the provider, so a forged
 *    "paid" call is simply a request to re-check a payment that is not paid.
 * 2. **Fulfilment happens once.** Providers redeliver; a proxy can duplicate.
 *    The claim is staked with a conditional UPDATE, so two simultaneous
 *    deliveries cannot both win it.
 *
 * On the second: the claim is *released again* if a listener throws, and the
 * webhook then answers non-2xx so the provider redelivers. That is deliberate.
 * Holding the claim would be "at most once", and the failure mode of at-most-once
 * is a customer who paid and got nothing, silently, for ever. The cost is that a
 * listener which throws halfway may see the event twice — said plainly in the
 * README, because a listener author has to know which of the two it is.
 */
class Fulfilment
{
    public function __construct(protected PaymentGateway $gateway) {}

    public function handle(string $providerId): ?Payment
    {
        $payment = Payment::query()
            ->where('provider', $this->gateway->provider())
            ->where('provider_id', $providerId)
            ->first();

        // Asked once, and asked even for an id we do not know. Fetching only
        // for known ids would answer, in the response time, which ids this site
        // has seen — the very question the flat 200 further down refuses.
        $remote = $this->fetch($providerId);

        $payment ??= $this->recover($providerId, $remote);

        if (! $payment) {
            // A payment this site never created. Nothing to fulfil, and nothing
            // to create either: an id we did not issue is not evidence of an
            // order, whatever the provider says about it.
            //
            // Logged, though. The one way this happens to a real site is a
            // checkout that died between the provider call and the id being
            // stored — the buyer pays, and without this line nobody ever hears
            // about it.
            Log::warning('statamic-payments: webhook for an unknown payment id.', [
                'provider' => $this->gateway->provider(),
                'provider_id' => $providerId,
            ]);

            return null;
        }

        if (! $remote || ! $remote->isPaid()) {
            if ($remote) {
                $this->recordUnpaid($payment, $remote);
            }

            return $payment;
        }

        return $this->fulfilOnce($payment, $remote);
    }

    /** The provider's own answer, or null if it would not give one. */
    protected function fetch(string $providerId): ?RemotePayment
    {
        try {
            return $this->gateway->fetch($providerId);
        } catch (Throwable $e) {
            // Most often a 404 for an id this account never issued, which is
            // the ordinary shape of a stray or forged call. A real outage looks
            // the same from here; the provider redelivers, so nothing is lost.
            Log::warning('statamic-payments: the provider would not answer for this id.', [
                'provider_id' => $providerId,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The row the provider is carrying our own id for.
     *
     * The rescue for a checkout that died mid-flight: `metadata.payment_id` is
     * sent to the provider precisely so a payment can still be recognised when
     * the provider id never made it back into the row.
     */
    protected function recover(string $providerId, ?RemotePayment $remote): ?Payment
    {
        $id = $remote?->metadata['payment_id'] ?? null;

        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return null;
        }

        $payment = Payment::query()
            ->where('provider', $this->gateway->provider())
            ->whereKey((int) $id)
            ->first();

        if (! $payment) {
            return null;
        }

        Log::warning('statamic-payments: recovered a payment by metadata; the provider id was never stored.', [
            'payment_id' => $payment->getKey(),
            'provider_id' => $providerId,
        ]);

        $payment->forceFill(['provider_id' => $providerId])->save();

        return $payment->fresh() ?? $payment;
    }

    protected function recordUnpaid(Payment $payment, RemotePayment $remote): void
    {
        if ($payment->isFulfilled()) {
            // A fulfilled payment is not downgraded. `normalise()` turns every
            // status this package has not met into `open`, so without this line
            // one unfamiliar provider status would reopen a finished order —
            // and a listener on PaymentFailed would revoke access from someone
            // who paid.
            return;
        }

        if ($payment->status !== $remote->status) {
            $payment->forceFill(['status' => $remote->status])->save();
            $payment = $payment->fresh() ?? $payment;
        }

        if (! in_array($remote->status, [Payment::STATUS_FAILED, Payment::STATUS_EXPIRED, Payment::STATUS_CANCELED], true)) {
            return;
        }

        // Same conditional-update claim as fulfilment, for the same reason: two
        // deliveries arriving together both read `open` and would both announce
        // the failure. A "your payment failed" mail twice is a support ticket.
        $claimed = Payment::query()
            ->whereKey($payment->getKey())
            ->whereNull('failed_notified_at')
            ->update(['failed_notified_at' => now(), 'updated_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        PaymentFailed::dispatch($payment->fresh() ?? $payment);
    }

    protected function fulfilOnce(Payment $payment, RemotePayment $remote): Payment
    {
        // The claim is staked in the database, not in PHP. A read-then-write
        // ("is it fulfilled? no, then fulfil") loses to a second request that
        // reads before the first writes — which is exactly what a redelivery
        // arriving twice within milliseconds looks like.
        $claimed = Payment::query()
            ->whereKey($payment->getKey())
            ->whereNull('fulfilled_at')
            ->update([
                'status' => Payment::STATUS_PAID,
                'paid_at' => now(),
                'fulfilled_at' => now(),
                'updated_at' => now(),
            ]);

        $payment = $payment->fresh() ?? $payment;

        if ($claimed === 0) {
            // Someone else already has it. Not an error — the ordinary result
            // of a provider doing its job — so it is silent.
            return $payment;
        }

        if ($remote->email && ! $payment->email) {
            $payment->forceFill(['email' => $remote->email])->save();
            $payment = $payment->fresh() ?? $payment;
        }

        if (! $payment->email) {
            // Paid, and nowhere to deliver to. Not a reason to refuse the
            // money, but a listener that delivers by mail is about to have
            // nothing to work with, and that must not pass in silence.
            Log::warning('statamic-payments: paid without an address; delivery by email is not possible.', [
                'payment_id' => $payment->getKey(),
                'provider_id' => $payment->provider_id,
            ]);
        }

        try {
            PaymentPaid::dispatch($payment);
        } catch (Throwable $e) {
            // Give the claim back and let the provider try again. The
            // alternative — keeping it — books the order as fulfilled while
            // nothing was delivered, and no retry ever comes.
            Payment::query()
                ->whereKey($payment->getKey())
                ->update(['fulfilled_at' => null, 'updated_at' => now()]);

            Log::error('statamic-payments: a listener threw; the fulfilment claim was released for redelivery.', [
                'payment_id' => $payment->getKey(),
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $payment;
    }
}
