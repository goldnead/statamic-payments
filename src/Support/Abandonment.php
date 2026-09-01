<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Events\CheckoutAbandoned;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Carbon;

/**
 * Checkouts that were started and never finished.
 *
 * The whole feature is one question asked on a schedule: is this payment still
 * unpaid, and has it been unpaid for long enough that the person is not simply
 * still typing? Everything difficult about it is in the words "long enough" and
 * "once".
 */
class Abandonment
{
    /**
     * The states a checkout can be abandoned *from*.
     *
     * `initiated` is a row this addon wrote before the provider was asked;
     * `open` is one the provider knows about and nobody paid. Both are somebody
     * halfway through. `failed`, `expired` and `canceled` are **not** abandoned
     * — those already have their own event, and announcing both would mean two
     * mails about one thing.
     */
    public const OFFEN = [Payment::STATUS_INITIATED, Payment::STATUS_OPEN];

    /**
     * Announce every checkout that has been sitting unpaid past the cut-off.
     *
     * @return int how many were announced
     */
    public function sweep(?Carbon $jetzt = null): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $jetzt ??= Carbon::now();
        $grenze = $jetzt->copy()->subMinutes($this->afterMinutes());

        $gezaehlt = 0;

        // In Stücken, weil diese Tabelle nur wächst und ein erster Lauf auf
        // einer bestehenden Installation jede alte offene Zahlung trifft.
        Payment::query()
            ->whereIn('status', self::OFFEN)
            ->whereNull('abandoned_notified_at')
            ->whereNull('fulfilled_at')
            ->where('created_at', '<=', $grenze)
            ->orderBy('id')
            ->chunkById(200, function ($stapel) use (&$gezaehlt) {
                foreach ($stapel as $zahlung) {
                    $gezaehlt += $this->announce($zahlung) ? 1 : 0;
                }
            });

        return $gezaehlt;
    }

    /**
     * Claim one payment and announce it.
     *
     * The claim is a conditional update, not a read-then-write: two overlapping
     * sweeps both read `open` and would both dispatch. A reminder arriving
     * twice is the kind of bug a customer reports and nobody can reproduce.
     */
    public function announce(Payment $zahlung): bool
    {
        $beansprucht = Payment::query()
            ->whereKey($zahlung->getKey())
            ->whereNull('abandoned_notified_at')
            ->whereNull('fulfilled_at')
            ->whereIn('status', self::OFFEN)
            ->update(['abandoned_notified_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        if ($beansprucht === 0) {
            return false;
        }

        CheckoutAbandoned::dispatch($zahlung->fresh() ?? $zahlung);

        return true;
    }

    /**
     * A payment that arrives after the reminder went out.
     *
     * The sequence still running somewhere has to be able to stop, and the only
     * honest signal for "stop, they bought it" is the fulfilment itself. So the
     * stamp is cleared: the row stops looking abandoned, and anything asking
     * `abandoned()` gets the truth rather than history.
     *
     * Deliberately not an event of its own. `PaymentPaid` already says what
     * happened, and a sequence that cannot listen for a purchase is not one
     * this addon can fix.
     */
    public function settled(Payment $zahlung): void
    {
        $jetzt = Carbon::now();

        // Die eigene Zeile: erinnert und doch bezahlt. `recovered_at` bleibt
        // stehen, wenn der Stempel geht — das ist der zurückgeholte Umsatz.
        if ($zahlung->abandoned_notified_at !== null) {
            Payment::query()
                ->whereKey($zahlung->getKey())
                ->update(['abandoned_notified_at' => null, 'recovered_at' => $jetzt, 'updated_at' => $jetzt]);
        }

        // Ein neu gestarteter Checkout (`Checkout::resume()`) bezahlt eine
        // andere Zeile als die erinnerte. Zurückgeholt ist trotzdem die
        // erinnerte; sie bekommt den Stempel, bleibt aber, was sie ist — ein
        // offener Checkout, den die Bereinigung später wegräumt.
        $original = data_get($zahlung->meta, 'resumed_from');

        if (is_int($original) || (is_string($original) && ctype_digit($original))) {
            Payment::query()
                ->whereKey((int) $original)
                ->whereNotNull('abandoned_notified_at')
                ->whereNull('recovered_at')
                ->update(['recovered_at' => $jetzt, 'updated_at' => $jetzt]);
        }
    }

    public function enabled(): bool
    {
        return (bool) config('statamic-payments.abandoned.enabled', false);
    }

    /**
     * How long to wait. Minutes rather than hours because the difference
     * between "still typing" and "gone" is not the same on a €9 download as on
     * a €2.400 course, and a site should be able to say so.
     */
    public function afterMinutes(): int
    {
        return max(1, (int) config('statamic-payments.abandoned.after_minutes', 60));
    }
}
