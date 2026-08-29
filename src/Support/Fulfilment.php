<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Events\PaymentFailed;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Goldnead\StatamicPayments\Models\Subscription;
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

        // The one payment a site legitimately never created: a cycle the
        // provider charged on its own, on an agreement this site *did* create.
        // The row is written here rather than refused, because the alternative
        // is a customer paying every month into a system that has no record of
        // it. The evidence is the agreement, not the caller: the subscription id
        // comes from the provider's own answer.
        $payment ??= $this->openCycle($providerId, $remote);

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

    /**
     * A row for a cycle the provider charged without being asked.
     *
     * Everything about it is taken from the agreement, never from the webhook:
     * the product, the amount, the currency and who it is for. A forged call
     * naming a real subscription id therefore cannot invent an amount — the
     * worst it can do is create a row for a payment the provider will not
     * confirm, and `isPaid()` is checked afterwards.
     *
     * **Hier gibt es keine aufrufende Strecke und deshalb keine Naht.** Der
     * Anbieter treibt, niemand kann dieser Zahlung etwas mitgeben. Was sie
     * trotzdem braucht, wird geerbt: was der Aufrufer der ersten Zahlung dieses
     * Abos an `meta` mitgab, gilt auch für ihre Zyklen. Sonst hätte eine
     * Abo-Rechnung ab dem zweiten Monat keine Anschrift, und das ist genau der
     * Beleg, der über 250 EUR eine Pflichtangabe vermissen lässt — bei der
     * Umsatzart, die diese Grenze am ehesten reißt.
     *
     * Das Land wird **nicht** geerbt. Es trägt der Anbieter nach, noch vor
     * `PaymentPaid`, und was der Kartenherausgeber sagt, ist der bessere
     * Nachweis. Ein geerbtes Land stünde ihm im Weg.
     */
    protected function openCycle(string $providerId, ?RemotePayment $remote): ?Payment
    {
        if (! $remote?->subscriptionId) {
            return null;
        }

        $subscription = Subscription::query()
            ->where('provider', $this->gateway->provider())
            ->where('provider_id', $remote->subscriptionId)
            ->first();

        if (! $subscription) {
            // Its own alarm, not the generic "unknown payment id" further up.
            // This is the shape a phantom agreement takes: the provider charging
            // on a rhythm for something this site has no row for. Told apart
            // from a stray webhook by the fact that it names an agreement.
            Log::error('statamic-payments: a cycle arrived for an agreement this site has no record of. Someone may be being charged for nothing.', [
                'provider_subscription_id' => $remote->subscriptionId,
                'provider_id' => $providerId,
            ]);

            return null;
        }

        $payment = Payment::create([
            'provider' => $this->gateway->provider(),
            'provider_id' => $providerId,
            // Geerbt, nicht erfragt. Dieser Zyklus entsteht im Webhook des
            // Anbieters, wo keine Marke gesetzt ist; `Brands::stampId()` gäbe
            // hier 0 zurück und die Zahlung wäre im Kundenbereich für niemanden
            // sichtbar. Sie gehört der Marke, die das Abo verkauft hat.
            'brand_id' => $subscription->brand_id,
            'product' => $subscription->product,
            'amount_cent' => $subscription->amount_cent,
            'currency' => $subscription->currency,
            'status' => Payment::STATUS_OPEN,
            'email' => $remote->email ?: $subscription->email,
            'name' => $subscription->name,
            'customer_reference' => $subscription->customer_reference,
            // Leer, und das mit Absicht: `Subscriptions::recordCycle()` füllt
            // die Spalte mit einem bedingten UPDATE auf `whereNull`, und das
            // ist der Anspruch, an dem eine zweite Zustellung desselben Zyklus
            // scheitert. Sie hier zu setzen hiesse, jeden Zyklus ungezählt zu
            // lassen. Der Zeiger für einen Listener steht deshalb in `meta`.
            'subscription_id' => null,
            'meta' => $this->fromTheFirstPayment($subscription),
        ]);

        // A line, like every other payment has. Without one
        // `Payment::itemsTotalCent()` reads zero for a cycle, and any report
        // built over lines silently leaves out all recurring revenue.
        PaymentItem::create([
            'payment_id' => $payment->getKey(),
            'product' => $subscription->product,
            'name' => $subscription->product,
            'amount_cent' => $subscription->amount_cent,
            'quantity' => 1,
            'kind' => PaymentItem::KIND_PRIMARY,
        ]);

        return $payment;
    }

    /**
     * Was ein Zyklus von der ersten Zahlung seines Abos mitbekommt.
     *
     * Zwei Dinge, und beide stehen da, bevor `PaymentPaid` feuert:
     *
     * 1. Die Angaben, die die aufrufende Strecke der ersten Zahlung mitgab.
     *    Die Anschrift ändert sich nicht dadurch, dass ein Monat vergeht.
     * 2. Ein Zeiger auf das Abo. Die Spalte `subscription_id` steht zu diesem
     *    Zeitpunkt noch nicht (siehe oben), ein Listener hätte sonst nichts in
     *    der Hand als die Kundenkennung und eine Rückwärtssuche.
     *
     * Nicht mitgeerbt wird, was das Paket in `meta` selbst führt. Ein
     * `subscription_intent` gehört der Zahlung, die das Abo begonnen hat, und
     * ein `refunds` der Zahlung, die erstattet wurde.
     *
     * @return array<string, mixed>
     */
    protected function fromTheFirstPayment(Subscription $subscription): array
    {
        $first = Payment::query()
            ->where('subscription_id', $subscription->getKey())
            ->orderBy('id')
            ->first();

        $inherited = $first === null ? [] : array_diff_key(
            $first->meta ?? [],
            array_flip(PaymentDetails::RESERVED_META),
        );

        return $inherited + [
            'cycle_of' => array_filter([
                'subscription_id' => $subscription->getKey(),
                'first_payment_id' => $first?->getKey(),
            ], fn ($v) => $v !== null),
        ];
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

    /**
     * Fulfil a payment there was never anything to charge for.
     *
     * There is no provider to ask, so the usual "never believe the caller" rule
     * has nothing to check against. That is safe here for one reason and it is
     * worth stating: this is only reachable from `Checkout::start()`, after the
     * **catalogue** priced the basket at zero. Nothing a browser sent decided
     * that. It goes through the same claim as every other fulfilment, so a
     * double submit still delivers once.
     */
    public function fulfilFree(Payment $payment): Payment
    {
        return $this->fulfilOnce($payment, new RemotePayment(
            providerId: (string) $payment->provider_id,
            status: Payment::STATUS_PAID,
            metadata: ['free' => true],
            email: $payment->email,
        ));
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

        // Das Land, aber nur wenn keines da ist.
        //
        // Was der Anbieter sagt, ist der bessere Beleg — es kommt vom
        // Kartenherausgeber oder der Bank und ist damit einer der zwei
        // Nachweise, die die EU bei einer digitalen Leistung an Verbraucher
        // verlangt. Ein bereits eingefrorenes Land wird trotzdem nicht
        // ueberschrieben: eine Rechnung, die sich nachtraeglich aendert, ist
        // keine.
        if ($remote->country && ! $payment->country) {
            $payment->forceFill([
                'country' => $remote->country,
                'country_source' => $payment->provider,
            ])->save();
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

        // Wer doch noch bezahlt, ist nicht mehr abgesprungen. Vor dem Ereignis,
        // damit ein Listener, der `abandoned_notified_at` liest, die Wahrheit
        // sieht und nicht die Geschichte.
        app(Abandonment::class)->settled($payment);

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

        $this->followTheAgreement($payment, $remote);

        return $payment->fresh() ?? $payment;
    }

    /**
     * Keep the subscription row in step with the money.
     *
     * **After** fulfilment, deliberately. A cycle is first and foremost a
     * payment: it grants what it grants through `PaymentPaid`, exactly like a
     * one-off, so a listener needs to know nothing about subscriptions. What
     * happens here is bookkeeping on the agreement, and bookkeeping must never
     * stand between a customer and the thing they paid for.
     *
     * Nothing here throws. A subscription that could not be recorded is a row
     * to repair; a fulfilment claim released over it would make the provider
     * redeliver and the customer receive everything twice.
     */
    protected function followTheAgreement(Payment $payment, RemotePayment $remote): void
    {
        $subscriptions = app(Subscriptions::class);

        try {
            // A payment the provider made on its own: a cycle of a running
            // agreement. The id comes from the provider, never from the caller.
            if ($remote->subscriptionId) {
                $subscriptions->recordCycle($payment, $remote->subscriptionId);

                return;
            }

            // A first payment that carried the intention to start one. Only now
            // is there a mandate to build it on.
            $subscriptions->startFromPayment($payment);
        } catch (Throwable $e) {
            Log::error('statamic-payments: the agreement could not be brought up to date; the payment stands.', [
                'payment_id' => $payment->getKey(),
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
