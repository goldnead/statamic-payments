<?php

namespace Goldnead\StatamicPayments\Http\Controllers\Portal;

use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Anrede;
use Goldnead\StatamicPayments\Support\CancellationOutcome;
use Goldnead\StatamicPayments\Support\Cancellations;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
 *
 * The sequence itself (provider, mail, log) lives in {@see Cancellations}, so
 * that an app API ending the same agreement cannot forget one of the three.
 * This controller owns who may press the button and what the screen says.
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
            'until' => app(Cancellations::class)->paidUntil($subscription),
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

        // The confirmation goes to the address that proved itself by following
        // the link, not to whatever the row says.
        $outcome = app(Cancellations::class)->cancel($subscription, $access->email);

        return match ($outcome->status) {
            // Already over: a webhook or a second tab got there first. The buyer
            // is shown the confirmation they were going to be shown.
            CancellationOutcome::CANCELLED, CancellationOutcome::ALREADY_ENDED => $this->done($outcome, $access->email),
            // A pause, resume or switch is talking to the provider right now.
            CancellationOutcome::BUSY => $this->backWith($subscription, 'statamic-payments::subscriptions.portal_cancel_busy'),
            // Nothing was written. The buyer is told the truth: it did not
            // happen, and they should try again.
            default => $this->backWith($subscription, 'statamic-payments::portal.cancel_failed'),
        };
    }

    protected function backWith(Subscription $subscription, string $key)
    {
        return redirect()
            ->route('statamic-payments.portal.cancel.confirm', ['paySubscription' => $subscription->getKey()])
            ->with('statamic-payments.portal.error', Anrede::trans($key));
    }

    /**
     * The portal button is off for this product: the statutory way stays open.
     */
    protected function elsewhere()
    {
        return redirect()
            ->route('statamic-payments.portal.show')
            ->with('statamic-payments.portal.error', Anrede::trans('statamic-payments::subscriptions.portal_cancel_elsewhere'));
    }

    /**
     * Confirm it — in Textform first, on the screen second.
     *
     * The mail is the confirmation § 312k Abs. 2 S. 4 asks for and has already
     * been sent by {@see Cancellations}; the page is a courtesy and says so. A
     * mail that did not go out does not undo the cancellation and must not
     * pretend it did, so the failure is shown on the screen, with the date and
     * time on it, rather than swallowed into a log.
     */
    protected function done(CancellationOutcome $outcome, string $email)
    {
        return response()->view('statamic-payments::portal.cancelled', [
            'subscription' => $outcome->subscription,
            'name' => $this->nameOf($outcome->subscription->product),
            'moment' => $outcome->moment ?? Carbon::now(),
            'until' => $outcome->until,
            // An agreement that was already over sent nothing this time, and
            // did not have to.
            'delivered' => $outcome->status === CancellationOutcome::ALREADY_ENDED || $outcome->confirmationSent,
            'email' => $email,
        ]);
    }
}
