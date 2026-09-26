<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Facades\PaymentLog;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Portal\Mail\CancellationConfirmed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Ending an agreement, the whole sequence, for any caller.
 *
 * The portal's cancellation button, an app's API, a Control Panel action: each
 * one owes the buyer the same three things, in this order.
 *
 * 1. **The provider says yes first.** {@see Subscriptions::cancel()} writes
 *    nothing unless the provider confirmed, so "cancelled" here means nobody
 *    charges again.
 * 2. **The confirmation in Textform** (§ 312k Abs. 2 S. 4 BGB), with the
 *    moment it took effect: {@see CancellationConfirmed}.
 * 3. **A line in the payment's log**, on the latest payment of the agreement,
 *    where somebody looks later.
 *
 * Who may cancel is not decided here. The caller has already established that
 * the person asking owns this row (the portal through `Portal\Orders`, an API
 * through its own authentication); this class does what follows.
 */
class Cancellations
{
    public function __construct(protected Subscriptions $subscriptions) {}

    /**
     * Cancel, confirm, log.
     *
     * `$email` is where the confirmation goes; null means the address on the
     * row. A mail that cannot be sent does not undo the cancellation: the
     * outcome says `confirmationSent: false` and the failure is logged loudly,
     * because the statute's confirmation did not leave the building.
     *
     * `$copies`: further addresses that get the same confirmation as a mail of
     * their own (a team's billing address next to the person who cancelled).
     * Each once, never the recipient again, only valid addresses. A copy that
     * fails is logged and left out of `copiedTo`; it changes nothing else.
     *
     * @param  array<int, string|null>  $copies
     */
    public function cancel(Subscription $subscription, ?string $email = null, array $copies = []): CancellationOutcome
    {
        $email = $email !== null && $email !== '' ? $email : ($subscription->email ?: null);
        $name = $this->nameOf((string) $subscription->product);

        // Already over. A webhook or a second tab got there first.
        if (! $subscription->isRunning() && ! $subscription->isClaimed()) {
            return new CancellationOutcome(
                CancellationOutcome::ALREADY_ENDED,
                $subscription,
                $name,
                $email,
                $this->momentOf($subscription),
                $this->paidUntil($subscription),
            );
        }

        // Before the cancellation: it nulls `next_payment_at`, and afterwards
        // nobody could say any more until when it is paid.
        $until = $this->paidUntil($subscription);

        if (! $this->subscriptions->cancel($subscription)) {
            $current = $subscription->fresh() ?? $subscription;

            return new CancellationOutcome(
                $current->isClaimed() ? CancellationOutcome::BUSY : CancellationOutcome::FAILED,
                $current,
                $name,
                $email,
                until: $until,
            );
        }

        $subscription = $subscription->fresh() ?? $subscription;
        $moment = $this->momentOf($subscription);
        $sent = $email !== null && $this->confirm($subscription, $email, $moment, $name, $until);

        $copiedTo = [];

        foreach ($this->copyAddresses($copies, $email) as $copy) {
            if ($this->confirm($subscription, $copy, $moment, $name, $until, copy: true)) {
                $copiedTo[] = $copy;
            }
        }

        return new CancellationOutcome(
            CancellationOutcome::CANCELLED,
            $subscription,
            $name,
            $email,
            $moment,
            $until,
            $sent,
            $copiedTo,
        );
    }

    /**
     * @param  array<int, string|null>  $copies
     * @return list<string>
     */
    protected function copyAddresses(array $copies, ?string $email): array
    {
        $seen = $email !== null ? [mb_strtolower(trim($email))] : [];
        $out = [];

        foreach ($copies as $copy) {
            $copy = is_string($copy) ? trim($copy) : '';
            $key = mb_strtolower($copy);

            if ($copy === '' || in_array($key, $seen, true) || filter_var($copy, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $seen[] = $key;
            $out[] = $copy;
        }

        return $out;
    }

    /**
     * Until when it is paid: the next charge, else the end of the last paid
     * period. Null when there is no such date in the future.
     */
    public function paidUntil(Subscription $subscription): ?Carbon
    {
        $until = $subscription->next_payment_at ?? $subscription->paidThroughAt();

        return $until !== null && $until->isFuture() ? Carbon::instance($until) : null;
    }

    /**
     * The moment the statute wants stated: what the row says, not the clock,
     * so the mail, the screen and the database name one time.
     */
    public function momentOf(Subscription $subscription): Carbon
    {
        return $subscription->cancelled_at ?? $subscription->ended_at ?? Carbon::now();
    }

    protected function confirm(Subscription $subscription, string $email, Carbon $moment, string $name, ?Carbon $until, bool $copy = false): bool
    {
        try {
            $mailable = new CancellationConfirmed($subscription, $moment, $name, $until);

            Mail::to($email)->send($mailable);

            if ($payment = $subscription->payments()->orderByDesc('paid_at')->orderByDesc('id')->first()) {
                PaymentLog::mail($payment, 'cancellation_confirmation', $email, $mailable->envelope()->subject, meta: array_filter([
                    'subscription_id' => $subscription->getKey(),
                    'copy' => $copy ?: null,
                ]));
            }

            return true;
        } catch (Throwable $e) {
            Log::error($copy
                ? 'statamic-payments: an agreement was cancelled and a copy of the confirmation could not be sent.'
                : 'statamic-payments: an agreement was cancelled and the confirmation in Textform could not be sent.', [
                    'subscription_id' => $subscription->getKey(),
                    'exception' => $e->getMessage(),
                ]);

            return false;
        }
    }

    /** The catalogue's name where it has one, the stored handle where not. */
    protected function nameOf(string $handle): string
    {
        $name = app(Catalogue::class)->find($handle)['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : $handle;
    }
}
