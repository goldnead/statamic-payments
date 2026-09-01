<?php

namespace Goldnead\StatamicPayments\Http\Controllers\Portal;

use Goldnead\StatamicPayments\Facades\PaymentLog;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Portal\Mail\CancellationConfirmed;
use Goldnead\StatamicPayments\Support\Subscriptions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Ending an agreement, the way § 312k BGB describes it.
 *
 * The statute prescribes a shape, and this is that shape:
 *
 * 1. A **cancellation button** on the site, reachable without logging in and
 *    without searching, labelled „Verträge hier kündigen". That is the route
 *    `portal.cancel.entry`, in `MagicLinkController` — it has to be reachable by
 *    somebody who cannot yet prove who they are, so it is the identification
 *    form.
 * 2. A **confirmation page** where the contract to end is named and the consumer
 *    presses „Jetzt kündigen". That is `confirm()` here.
 * 3. An **immediate confirmation in Textform**, with the date and time the
 *    cancellation takes effect. That is a mail, sent from `cancel()`.
 *
 * **Every one of those words is a translation key.** Not one German sentence is
 * compiled into this class. The wording of a statutory button is a lawyer's to
 * settle, the statute has been amended once already, and a site that needs a
 * different phrase must be able to change it without a release of this package.
 * What the code owns is the *sequence*; what the translations own is the text.
 *
 * **The provider is asked first and its answer is what gets written.** That is
 * not a § 312k requirement, it is the harder one: `Subscriptions::cancel()`
 * writes nothing at all unless the provider confirmed, so a buyer who is told
 * "cancelled" is a buyer who will not be charged again. A screen that said so
 * on a local flag would be how somebody keeps paying for a thing their account
 * says they ended.
 */
class CancellationController extends PortalController
{
    /** Step two: the contract, named, with the button under it. */
    public function confirm(Request $request, string $paySubscription)
    {
        $access = $this->access($request);

        if ($access === null) {
            return $this->askForALink();
        }

        $subscription = $this->orders->subscriptionFor($access, (int) $paySubscription);

        abort_if($subscription === null, 404);

        return response()->view('statamic-payments::portal.cancel', [
            'subscription' => $subscription,
            'name' => $this->nameOf($subscription->product),
        ]);
    }

    /** Step three: press it, and mean it. */
    public function cancel(Request $request, string $paySubscription)
    {
        $access = $this->access($request);

        if ($access === null) {
            return $this->askForALink();
        }

        $subscription = $this->orders->subscriptionFor($access, (int) $paySubscription);

        abort_if($subscription === null, 404);

        // Already over. Not an error and not a second cancellation: the buyer
        // pressed a button on a page they had open while a webhook or a second
        // tab did the same thing. They are shown the confirmation they were
        // going to be shown.
        if (! $subscription->isLive()) {
            return $this->done($subscription, $access->email, $this->momentOf($subscription), false);
        }

        if (! app(Subscriptions::class)->cancel($subscription)) {
            // Nothing was written — that is a property of `Subscriptions::cancel()`
            // and the reason this branch can be this short. The buyer is told the
            // truth: it did not happen, and they should try again.
            return redirect()
                ->route('statamic-payments.portal.cancel.confirm', ['paySubscription' => $subscription->getKey()])
                ->with('statamic-payments.portal.error', __('statamic-payments::portal.cancel_failed'));
        }

        $subscription = $subscription->fresh() ?? $subscription;

        return $this->done($subscription, $access->email, $this->momentOf($subscription), true);
    }

    /**
     * The moment the statute wants stated: what the row says, not what the clock
     * says now.
     *
     * `Subscriptions::cancel()` wrote `cancelled_at` from the same `now()` it
     * used for `ended_at`, and reading it back is what makes the mail, the screen
     * and the database say one thing. Re-reading the clock here would produce
     * three timestamps for one event, differing by however long the mailer took.
     */
    protected function momentOf(Subscription $subscription): Carbon
    {
        return $subscription->cancelled_at ?? $subscription->ended_at ?? Carbon::now();
    }

    /**
     * Confirm it — in Textform first, on the screen second.
     *
     * The mail is the confirmation § 312k Abs. 2 S. 4 asks for; the page is a
     * courtesy and says so. A mail that will not go out does not undo the
     * cancellation and must not pretend it did, so the failure is shown on the
     * screen, with the date and time on it, rather than swallowed into a log.
     */
    protected function done(Subscription $subscription, string $email, Carbon $moment, bool $justNow)
    {
        $delivered = $justNow ? $this->confirmByMail($subscription, $email, $moment) : true;

        return response()->view('statamic-payments::portal.cancelled', [
            'subscription' => $subscription,
            'name' => $this->nameOf($subscription->product),
            'moment' => $moment,
            'delivered' => $delivered,
            'email' => $email,
        ]);
    }

    protected function confirmByMail(Subscription $subscription, string $email, Carbon $moment): bool
    {
        try {
            $mailable = new CancellationConfirmed(
                $subscription,
                $moment,
                $this->nameOf($subscription->product),
            );

            Mail::to($email)->send($mailable);

            // An die jüngste Zahlung des Abos, damit die Bestätigung nach
            // § 312k dort steht, wo jemand später nachsieht.
            if ($payment = $subscription->payments()->orderByDesc('paid_at')->orderByDesc('id')->first()) {
                PaymentLog::mail($payment, 'cancellation_confirmation', $email, $mailable->envelope()->subject, meta: ['subscription_id' => $subscription->getKey()]);
            }

            return true;
        } catch (Throwable $e) {
            // Loud, because this one has a legal obligation attached to it: the
            // agreement is ended and the confirmation the statute requires did
            // not leave the building.
            Log::error('statamic-payments: an agreement was cancelled and the confirmation in Textform could not be sent.', [
                'subscription_id' => $subscription->getKey(),
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
