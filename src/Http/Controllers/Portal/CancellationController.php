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

        if (! $this->mayCancelHere($subscription)) {
            return $this->elsewhere();
        }

        return response()->view('statamic-payments::portal.cancel', [
            'subscription' => $subscription,
            'name' => $this->nameOf($subscription->product),
            // Wann der Vertrag begann: die Anlage der Zeile, also der Kauf.
            // Nicht `starts_at` — das ist der Tag, an dem der Anbieter seinen
            // Rhythmus beginnt, ein Intervall nach der ersten, schon bezahlten
            // Abbuchung. Beim Jahresabo stand dort das Datum in einem Jahr.
            'began' => $subscription->created_at,
            'until' => $this->paidUntil($subscription),
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

        if (! $this->mayCancelHere($subscription)) {
            return $this->elsewhere();
        }

        // Already over. Not an error and not a second cancellation: the buyer
        // pressed a button on a page they had open while a webhook or a second
        // tab did the same thing. They are shown the confirmation they were
        // going to be shown.
        // A row in a claim (being paused, resumed, switched) is not over: the
        // cancellation is tried, and where it has to wait the buyer is told,
        // instead of being shown "cancelled" for a contract that runs on.
        if (! $subscription->isRunning() && ! $subscription->isClaimed()) {
            return $this->done($subscription, $access->email, $this->momentOf($subscription), false, $this->paidUntil($subscription));
        }

        // Vor der Kündigung gelesen: `cancel()` setzt `next_payment_at` auf
        // null, und danach wüsste die Bestätigung nicht mehr, bis wann bezahlt ist.
        $until = $this->paidUntil($subscription);

        if (! app(Subscriptions::class)->cancel($subscription)) {
            if (($subscription->fresh() ?? $subscription)->isClaimed()) {
                return redirect()
                    ->route('statamic-payments.portal.cancel.confirm', ['paySubscription' => $subscription->getKey()])
                    ->with('statamic-payments.portal.error', __('statamic-payments::subscriptions.portal_cancel_busy'));
            }

            // Nothing was written — that is a property of `Subscriptions::cancel()`
            // and the reason this branch can be this short. The buyer is told the
            // truth: it did not happen, and they should try again.
            return redirect()
                ->route('statamic-payments.portal.cancel.confirm', ['paySubscription' => $subscription->getKey()])
                ->with('statamic-payments.portal.error', __('statamic-payments::portal.cancel_failed'));
        }

        $subscription = $subscription->fresh() ?? $subscription;

        return $this->done($subscription, $access->email, $this->momentOf($subscription), true, $until);
    }

    /**
     * Bis wann bezahlt ist: die nächste Abbuchung, sonst das Ende des Zeitraums
     * der letzten bezahlten Zahlung. Bis dahin läuft der Vertrag nach einer
     * Kündigung aus (siehe `EntitlementsBridge::closeFor()`, gleiche Kette);
     * danach folgt keine Abbuchung mehr. Null, wenn es keinen Zeitraum gibt.
     */
    protected function paidUntil(Subscription $subscription): ?Carbon
    {
        $until = $subscription->next_payment_at ?? $subscription->paidThroughAt();

        return $until !== null && $until->isFuture() ? Carbon::instance($until) : null;
    }

    /**
     * The portal button is off for this product: the statutory way stays open.
     */
    protected function elsewhere()
    {
        return redirect()
            ->route('statamic-payments.portal.show')
            ->with('statamic-payments.portal.error', __('statamic-payments::subscriptions.portal_cancel_elsewhere'));
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
    protected function done(Subscription $subscription, string $email, Carbon $moment, bool $justNow, ?Carbon $until = null)
    {
        $delivered = $justNow ? $this->confirmByMail($subscription, $email, $moment, $until) : true;

        return response()->view('statamic-payments::portal.cancelled', [
            'subscription' => $subscription,
            'name' => $this->nameOf($subscription->product),
            'moment' => $moment,
            'until' => $until,
            'delivered' => $delivered,
            'email' => $email,
        ]);
    }

    protected function confirmByMail(Subscription $subscription, string $email, Carbon $moment, ?Carbon $until = null): bool
    {
        try {
            $mailable = new CancellationConfirmed(
                $subscription,
                $moment,
                $this->nameOf($subscription->product),
                $until,
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
