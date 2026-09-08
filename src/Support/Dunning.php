<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Events\SubscriptionEnded;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Throwable;

/**
 * Trying to keep a customer whose card stopped working.
 *
 * Before this, a failed cycle was `Subscription::STATUS_SUSPENDED` mirrored
 * from the provider and nothing else — no retry of our own, no letter, no way
 * back. On a subscription product that is a lost customer who never finds out
 * they were one.
 *
 * Three properties this class exists for, and all three are the kind that only
 * a test which *tries* to break them shows:
 *
 * 1. **Each letter goes out once.** The stage is claimed with a conditional
 *    UPDATE before the mail is built, so two workers on the same schedule
 *    cannot both write to the same customer. Read-then-write loses that race,
 *    and "your payment failed" twice is a support ticket.
 * 2. **The provider decides when it is over, not the calendar.** The card may
 *    go through between two letters — the provider retries on its own rhythm,
 *    and this sequence must not talk over it. So before every letter the
 *    payment is asked about at the provider, and a payment that has since been
 *    paid ends the sequence silently. No further letter, and no "we could not
 *    reach you" either: nothing happened worth telling anybody.
 * 3. **It ends.** After the last stage plus a grace period the agreement is
 *    ended and the access goes with it. A sequence that only ever sends is a
 *    sequence that leaves a free customer behind for ever.
 */
class Dunning
{
    /** Whether the site wants a sequence at all. */
    public function enabled(): bool
    {
        return (bool) config('statamic-payments.dunning.enabled', false);
    }

    /**
     * The schedule, in days after the failure.
     *
     * Three letters by default — soon enough to catch an expired card, spread
     * far enough that the provider's own retries have happened in between.
     * A site that wants two, or five, says so.
     *
     * @return array<int, int> one-based stage number => days after the failure
     */
    public function stages(): array
    {
        $configured = config('statamic-payments.dunning.stages', [3, 7, 14]);

        $days = collect(is_array($configured) ? $configured : [])
            // `is_numeric` vor dem Cast, sonst wird aus `'bald'` der Tag 0 und
            // der erste Brief geht im Augenblick des Fehlschlags raus — bevor
            // der Anbieter seinen eigenen Wiederholungsversuch gemacht hat.
            ->filter(fn ($day) => is_numeric($day))
            ->map(fn ($day) => (int) $day)
            ->filter(fn (int $day) => $day >= 0)
            ->unique()
            ->sort()
            ->values();

        // An empty or nonsense schedule is not "send everything now". It is a
        // site that has not configured this, and it gets the default.
        return $days->isEmpty()
            ? [1 => 3, 2 => 7, 3 => 14]
            : $days->mapWithKeys(fn (int $day, int $index) => [$index + 1 => $day])->all();
    }

    /** How long after the last letter the agreement is given up on. */
    public function graceDays(): int
    {
        return max(0, (int) config('statamic-payments.dunning.grace_days', 7));
    }

    /**
     * Open a sequence for an agreement whose cycle was not paid.
     *
     * Idempotent by conditional UPDATE: a provider may say "not paid" about the
     * same cycle several times, and each delivery would otherwise restart the
     * clock — which is a sequence that never reaches its end and a customer who
     * is never let go.
     */
    public function begin(Subscription $subscription, Payment $payment): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        // Und nicht wieder aufmachen, was das Geld schon geschlossen hat.
        //
        // `whereNull('dunning_started_at')` allein wehrt nur eine **laufende**
        // Strecke ab, nicht eine beendete. Auf Mollie bleibt eine gescheiterte
        // Zahlung fuer immer gescheitert: wird ihr Webhook spaeter noch einmal
        // zugestellt — nach einem 5xx, oder von Hand aus dem Dashboard —, oeffnet
        // das eine **neue** Strecke mit spaeterem Startdatum, und `paidSince()`
        // sieht die Ersatzzahlung davor dann nicht mehr. Der Kunde hat bezahlt
        // und wird trotzdem dreimal gemahnt und gekuendigt.
        //
        // Dieselbe Falle wie zuvor, nur durch die andere Tuer.
        if ($this->settledAfter($subscription, $payment)) {
            return false;
        }

        $opened = Subscription::query()
            ->whereKey($subscription->getKey())
            ->whereNull('dunning_started_at')
            ->update([
                'dunning_started_at' => Carbon::now(),
                'dunning_stage' => 0,
                'dunning_payment_id' => $payment->getKey(),
                'updated_at' => Carbon::now(),
            ]);

        return $opened > 0;
    }

    /**
     * End a sequence because the money arrived after all.
     *
     * Silent on purpose. Nothing happened that a customer or an operator needs
     * to hear about: a card that failed on Tuesday and worked on Thursday is
     * an ordinary week at a payment provider.
     */
    public function stop(Subscription $subscription): void
    {
        Subscription::query()
            ->whereKey($subscription->getKey())
            ->whereNotNull('dunning_started_at')
            ->update([
                'dunning_started_at' => null,
                'dunning_stage' => 0,
                'dunning_last_at' => null,
                'dunning_payment_id' => null,
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * Whether a cycle of this agreement has been paid since the sequence opened.
     *
     * The failed payment is excluded by the date, not by its id: any cycle
     * fulfilled after the sequence began is money that arrived, whichever row
     * it landed on.
     */
    protected function paidSince(Subscription $subscription): bool
    {
        return Payment::query()
            ->where('subscription_id', $subscription->getKey())
            ->whereNotNull('fulfilled_at')
            ->where('fulfilled_at', '>=', $subscription->dunning_started_at)
            ->exists();
    }

    /**
     * Ob nach dieser gescheiterten Zahlung schon wieder Geld angekommen ist.
     *
     * Gemessen am Zeitpunkt der **Zahlung**, nicht der Strecke: eine Strecke,
     * die dazu gehoert haette, kann laengst gestoppt sein, und genau darum geht
     * es hier. Die gescheiterte Zahlung selbst zaehlt nicht mit — sie wurde nie
     * erfuellt.
     */
    protected function settledAfter(Subscription $subscription, Payment $payment): bool
    {
        return Payment::query()
            ->where('subscription_id', $subscription->getKey())
            ->whereKeyNot($payment->getKey())
            ->whereNotNull('fulfilled_at')
            ->where('fulfilled_at', '>=', $payment->created_at ?? Carbon::now()->subCentury())
            ->exists();
    }

    /**
     * Every agreement with a sequence running.
     *
     * Erst die Kennungen, dann je eine Zeile — und beides mit Grund.
     *
     * `get()` holt alles auf einmal in den Speicher, und die Zahl gleichzeitig
     * fehlgeschlagener Abos hat keine Obergrenze. `cursor()` ist die uebliche
     * Antwort darauf und hier **falsch**: der Aufrufer schreibt in genau die
     * Tabelle, die er gerade streamt (`dunning_stage`, `dunning_started_at`),
     * und eine noch offene Ergebnismenge liefert dieselbe Zeile danach erneut.
     * Gemessen, nicht vermutet — mit `cursor()` verschickte der Test „eine
     * ausgefallene Woche schickt nicht drei Briefe auf einmal" genau drei.
     *
     * Also: die Kennungen einmal einsammeln (eine Spalte, kein ganzes Modell),
     * die Lesung damit abschliessen, und danach je Kennung eine frische Zeile
     * laden. Der Speicher bleibt beschraenkt, und der Schreibvorgang kann die
     * Lesung nicht mehr stoeren, weil es keine mehr gibt.
     *
     * @return LazyCollection<int, Subscription>
     */
    public function running(): LazyCollection
    {
        $ids = Subscription::query()
            ->whereNotNull('dunning_started_at')
            ->orderBy('dunning_started_at')
            ->pluck('id');

        return LazyCollection::make(function () use ($ids) {
            foreach ($ids as $id) {
                // Frisch geladen, nicht aus einem Schnappschuss: zwischen dem
                // Einsammeln und dieser Zeile kann ein Webhook die Strecke
                // beendet haben.
                if ($subscription = Subscription::find($id)) {
                    yield $subscription;
                }
            }
        });
    }

    /**
     * Which letter is due now, if any.
     *
     * Null where nothing is due yet, or where every stage has been sent — the
     * end of the sequence is a separate question, asked by {@see dueToEnd()}.
     */
    public function stageDue(Subscription $subscription, ?Carbon $now = null): ?int
    {
        $now ??= Carbon::now();
        $started = $subscription->dunning_started_at;

        if (! $started) {
            return null;
        }

        $sent = (int) $subscription->dunning_stage;

        foreach ($this->stages() as $stage => $days) {
            if ($stage <= $sent) {
                continue;
            }

            if ($now->greaterThanOrEqualTo($started->copy()->addDays($days))) {
                // The earliest unsent stage that is due. Not the latest: if a
                // scheduler was down for a week, the customer gets the letters
                // one run at a time rather than three at once.
                return $stage;
            }

            break;
        }

        return null;
    }

    /**
     * Take a stage, or find somebody else took it.
     *
     * The move from `n-1` to `n` **is** the claim. Checking first and updating
     * afterwards loses to a second worker that checks before the first writes,
     * which is exactly what two schedulers on one database are.
     */
    public function claimStage(Subscription $subscription, int $stage): bool
    {
        $claimed = Subscription::query()
            ->whereKey($subscription->getKey())
            ->whereNotNull('dunning_started_at')
            ->where('dunning_stage', $stage - 1)
            ->update([
                'dunning_stage' => $stage,
                'dunning_last_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        return $claimed > 0;
    }

    /** Give the claim back, so the next run tries again. */
    public function releaseStage(Subscription $subscription, int $stage): void
    {
        Subscription::query()
            ->whereKey($subscription->getKey())
            ->where('dunning_stage', $stage)
            // `dunning_last_at` goes back with it. Left standing it would say
            // "last written on" about a letter that never left.
            ->update([
                'dunning_stage' => $stage - 1,
                'dunning_last_at' => null,
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * Whether the last letter plus the grace period is behind us.
     *
     * A question to the calendar and to nothing else. It used to also demand
     * that every stage had actually gone out, and that turned each of the
     * ordinary reasons a letter does not leave into a sequence that never ends
     * at all: a brand without a verified sender, a provider that will not
     * answer, a cycle row pruned from under the sequence. The counter then
     * stands still for ever, `dueToEnd()` stays false for ever, and the
     * customer whose money never arrived keeps the paid access — the very
     * outcome this class exists to prevent, reached through its own machinery.
     *
     * The letters are the courtesy. The deadline is the deadline, and it runs
     * whether or not the courtesy could be delivered. Someone who paid is taken
     * out of the sequence long before this, by {@see settledMeanwhile()} and by
     * {@see StopDunningOnRenewal} — so what is left here has not paid.
     */
    public function dueToEnd(Subscription $subscription, ?Carbon $now = null): bool
    {
        $now ??= Carbon::now();
        $started = $subscription->dunning_started_at;

        if (! $started) {
            return false;
        }

        $stages = $this->stages();
        $last = (int) (end($stages) ?: 0);

        // Mindestens ein Tag hinter der letzten Stufe, auch bei `grace_days`
        // von null. Sonst fallen die Frist und der letzte Brief auf denselben
        // Tag, und der Lauf fragt zuerst nach der Frist: der Kunde bekommt zwei
        // von drei versprochenen Briefen und ist gekuendigt, bevor der dritte je
        // geschrieben wurde. Karenz null heisst „am Tag nach dem letzten Brief",
        // nicht „statt des letzten Briefs".
        return $now->greaterThanOrEqualTo($started->copy()->addDays($last + max(1, $this->graceDays())));
    }

    /**
     * Give up on the agreement.
     *
     * Der Anspruch zuerst, dann der Anbieter — und das ist die umgekehrte
     * Reihenfolge zu {@see Subscriptions::cancel()}, mit Absicht. Dort darf der
     * Anbieter fuehren, weil eine Kuendigung scheitern darf. Hier nicht: das
     * Geld ist seit Wochen nicht angekommen, und eine Vereinbarung, die niemand
     * erreichen kann, ist kein Grund, den Zugang weiter zu verschenken. Also
     * wird lokal beendet und die Antwort des Anbieters protokolliert, statt auf
     * ihn zu warten.
     *
     * `SubscriptionEnded` is what withdraws the access, through the listener
     * that already exists for an agreement running out.
     */
    public function end(Subscription $subscription): bool
    {
        // The claim is the sequence, not the agreement — and the order matters.
        // `Subscriptions::cancel()` writes `ended_at` itself, so claiming on
        // that column afterwards would find the work already done and this
        // method would report failure every single time. The dunning columns
        // are the ones only this class writes, which makes them the honest
        // claim: whoever clears `dunning_started_at` is the one ending it.
        // Anspruch **und** Endzustand in einer Transaktion. Getrennt geschrieben
        // waere zwischen ihnen ein Fenster, in dem ein Absturz die Strecke aus
        // `running()` nimmt und die Vereinbarung trotzdem `active` laesst: nie
        // wieder ein Brief, nie ein Ende, kein Zugangsentzug, und keine Zeile
        // irgendwo. Rollt die Transaktion zurueck, steht die Strecke wieder da
        // und der naechste Lauf versucht es erneut.
        //
        // `SubscriptionEnded` gehoert **mit** in diese Transaktion, und das ist
        // der Unterschied zwischen einem schlechten und einem unsichtbaren Tag.
        // Das Ereignis ist, was den Zugang entzieht. Wurde die Strecke davor
        // abgeraeumt und das Abo gekuendigt und warf dann ein Zuhoerer, war
        // beides verloren: der Zugang stand weiter offen, und keine Zeile fand
        // die Strecke je wieder — `running()` kennt sie nicht mehr, und der Lauf
        // kommt nie wieder vorbei. So rollt der Wurf den Anspruch mit zurueck,
        // die Strecke steht wieder da und der naechste Lauf versucht es erneut.
        try {
            $claimed = DB::transaction(function () use ($subscription): bool {
                $genommen = Subscription::query()
                    ->whereKey($subscription->getKey())
                    ->whereNotNull('dunning_started_at')
                    ->update([
                        'dunning_started_at' => null,
                        'dunning_payment_id' => null,
                        'status' => Subscription::STATUS_CANCELLED,
                        'ended_at' => $subscription->ended_at ?? Carbon::now(),
                        'next_payment_at' => null,
                        'updated_at' => Carbon::now(),
                    ]);

                if ($genommen === 0) {
                    return false;
                }

                SubscriptionEnded::dispatch($subscription->fresh() ?? $subscription);

                return true;
            });
        } catch (Throwable $e) {
            // `critical`: der Zugangsentzug ist gescheitert, und das ist die
            // eine Stoerung hier, die Geld kostet, solange sie steht.
            Log::critical('statamic-payments: withdrawing the access at the end of a dunning sequence threw; nothing was ended and the sequence is still open for the next run.', [
                'subscription_id' => $subscription->getKey(),
                'exception' => $e->getMessage(),
            ]);

            return false;
        }

        if (! $claimed) {
            return false;
        }

        // Ended without having said everything the sequence meant to say.
        // The deadline is not negotiable, but this is the one outcome an
        // operator has to see: the customer was cancelled having received
        // fewer letters than the plan promises, so the reason the letters
        // stopped — a sender the brand refuses, a provider that never
        // answered, a pruned cycle row — is the thing to go and fix.
        $geschickt = (int) $subscription->dunning_stage;
        $geplant = count($this->stages());

        if ($geschickt < $geplant) {
            Log::error('statamic-payments: a dunning sequence ended without sending every letter; find out why the letters stopped.', [
                'subscription_id' => $subscription->getKey(),
                'stages_sent' => $geschickt,
                'stages_planned' => $geplant,
            ]);
        }

        try {
            // Der Anbieter wird danach gefragt, nicht davor — die Begruendung
            // steht oben beim Anspruch. Wichtig ist, dass seine **Antwort**
            // gelesen wird: `cancel()` gibt `false` zurueck, wenn es die
            // Vereinbarung dort nicht beenden konnte, und ohne diese Pruefung
            // meldete der Lauf „eine Vereinbarung beendet", waehrend sie beim
            // Anbieter weiterlaeuft und weiter abbucht. Die Zeile sagt gekuendigt,
            // der Zugang ist weg, und das Geld fliesst trotzdem.
            if (! app(Subscriptions::class)->cancel($subscription->fresh() ?? $subscription)) {
                Log::error('statamic-payments: the dunning sequence ended an agreement locally but the provider did not cancel it; it may still be charging. Cancel it by hand.', [
                    'subscription_id' => $subscription->getKey(),
                    'provider' => $subscription->provider,
                    'provider_id' => $subscription->provider_id,
                ]);
            }
        } catch (Throwable $e) {
            // Logged, not swallowed into silence, and not fatal. Unlike an
            // ordinary cancellation this must not stall: the money has not
            // arrived for weeks either way, and an agreement nobody can end is
            // not a reason to keep giving the access away.
            Log::warning('statamic-payments: the provider would not end an agreement the dunning sequence gave up on; the row was ended anyway.', [
                'subscription_id' => $subscription->getKey(),
                'exception' => $e->getMessage(),
            ]);
        }

        // Der Endzustand steht schon oben, in derselben Transaktion wie der
        // Anspruch. Hier bleibt nur, was `cancel()` zusaetzlich gesetzt haben
        // kann und was ohne ihn fehlt: der Kuendigungszeitpunkt.
        Subscription::query()
            ->whereKey($subscription->getKey())
            ->whereNull('cancelled_at')
            ->update(['cancelled_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        return true;
    }

    /**
     * Whether the payment that opened this sequence has since been paid.
     *
     * Asked of the provider, not of the row: the provider retries on its own
     * rhythm and may have collected the money without this site hearing a
     * webhook yet. That is the whole of "the sequence hangs on the provider's
     * state" — and asking the local row instead would keep writing to somebody
     * who has already paid.
     *
     * A provider that will not answer is not an answer. The sequence pauses for
     * this run rather than sending on a guess.
     */
    public function settledMeanwhile(Subscription $subscription): ?bool
    {
        // A newer paid cycle settles it, whatever the failed one still says.
        //
        // This is not a shortcut past the provider question, it is the other
        // half of it — and on Mollie it is the half that matters. A Mollie
        // payment that failed is failed for ever: the retry, and a card the
        // buyer replaces in the portal, produce a **new** payment. Asking only
        // about the old one would answer "still not paid" until the end of
        // time, and the sequence would run its full course against somebody who
        // has been paying all along. Stripe hides this because its row is an
        // invoice, and an invoice turns `paid` in place.
        //
        // Read locally on purpose: a cycle only becomes paid here after
        // `Fulfilment` has already asked the provider and been told so. This is
        // that answer, written down.
        if ($subscription->dunning_started_at && $this->paidSince($subscription)) {
            return true;
        }

        $payment = $subscription->dunning_payment_id
            ? Payment::find($subscription->dunning_payment_id)
            : null;

        if (! $payment || ! $payment->hasProviderId()) {
            // Nothing to ask about. Said out loud, because the caller then
            // sends nothing for ever and a sequence stuck like this is
            // otherwise invisible.
            Log::warning('statamic-payments: a dunning sequence has no payment to ask the provider about; it is sending nothing.', [
                'subscription_id' => $subscription->getKey(),
                'dunning_payment_id' => $subscription->dunning_payment_id,
            ]);

            return null;
        }

        try {
            $remote = app(Gateways::class)->for($payment)->fetch((string) $payment->provider_id);
        } catch (Throwable $e) {
            Log::warning('statamic-payments: the provider would not say whether a dunned payment has been settled; no letter was sent this run.', [
                'subscription_id' => $subscription->getKey(),
                'payment_id' => $payment->getKey(),
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        return $remote->isPaid();
    }
}
