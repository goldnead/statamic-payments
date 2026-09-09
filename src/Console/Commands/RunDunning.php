<?php

namespace Goldnead\StatamicPayments\Console\Commands;

use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Dunning;
use Goldnead\StatamicPayments\Support\DunningNotice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One pass over every agreement whose payment failed.
 *
 * Not scheduled by the addon — the same line this package holds for the
 * abandoned-checkout sweep and the prunes. A host that schedules it decides how
 * often somebody's failed payment is looked at, and that is theirs to decide.
 * Once a day is right for a schedule counted in days:
 *
 *     Schedule::command('payments:dunning')->daily();
 *
 * Safe to run twice: every letter is claimed with a conditional UPDATE and
 * every ending with another, so a second run in the same hour sends nothing and
 * ends nothing twice.
 */
class RunDunning extends Command
{
    protected $signature = 'payments:dunning {--dry-run : Say what would happen and change nothing}';

    protected $description = 'Send the due dunning letters and end the agreements that ran out of them.';

    /** Was dieser Lauf angefasst hat. Der Bericht am Ende liest sie. */
    protected int $seen = 0;

    protected int $sent = 0;

    protected int $ended = 0;

    protected int $stopped = 0;

    /** Der Anbieter hat nicht geantwortet, also ging kein Brief auf Verdacht raus. */
    protected int $unreachable = 0;

    /** Nichts zu verschicken, aber die Stufe zaehlt: keine Adresse, oder gesperrt. */
    protected int $skipped = 0;

    /** Der Brief wollte raus und konnte nicht. Der Anspruch ging zurueck. */
    protected int $withheld = 0;

    /** Die Zeile hat geworfen. Der Lauf ging weiter. */
    protected int $broken = 0;

    /** Die Vereinbarung war schon gekuendigt oder ausgelaufen; die Strecke wurde geschlossen. */
    protected int $closed = 0;

    public function handle(Dunning $dunning, DunningNotice $notice): int
    {
        // Auf null, bei jedem Lauf. Die Konsole haelt eine Instanz je Befehl:
        // zwei `Artisan::call('payments:dunning')` in einem Prozess zaehlten
        // sonst beim zweiten Mal die Zeilen des ersten mit — und seit der
        // Rueckgabewert daran haengt, meldete der zweite, gelungene Lauf den
        // Fehlschlag des ersten.
        $this->seen = $this->sent = $this->ended = $this->stopped = 0;
        $this->unreachable = $this->skipped = $this->withheld = $this->broken = $this->closed = 0;

        $dry = (bool) $this->option('dry-run');

        if (! $dunning->enabled()) {
            return $this->closeEverything($dunning, $dry);
        }

        foreach ($dunning->running() as $subscription) {
            $this->seen++;

            try {
                $this->pass($dunning, $notice, $subscription, $dry);
            } catch (Throwable $e) {
                // Eine Zeile darf den Lauf nicht anhalten. `running()` sortiert
                // nach dem Beginn der Strecke, also stuende dasselbe kaputte Abo
                // morgen wieder vorn: jedes dahinter bekaeme nie wieder einen
                // Brief und wuerde nie beendet, und im Cron laege ein einzelner
                // Stacktrace als ganze Erklaerung.
                $this->broken++;

                Log::error('statamic-payments: a dunning sequence threw; the run continued with the next one.', [
                    'subscription_id' => $subscription->getKey(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $this->report($dry);

        // Ein Lauf, in dem Zeilen geworfen haben, ist kein gelungener Lauf. Im
        // Cron ist der Rueckgabewert das Einzige, was gelesen wird: mit `0`
        // schwieg der Planer ueber eine Mahnstrecke, die niemanden mehr mahnt.
        // Die Zahl steht im Bericht darueber.
        return $this->broken > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Der Schalter steht auf aus — und die laufenden Strecken werden dabei
     * geschlossen, nicht eingefroren.
     *
     * Eingefroren waere die stille Variante genau des Lochs, das die
     * Mahnstrecke schliessen soll: `dunning_started_at` bleibt stehen, kein
     * Brief geht mehr raus, kein Ende kommt, und `payments:prune-unpaid` fasst
     * die Zyklus-Zeile nicht an, weil sie unter einer Mahnstrecke haengt. Kein
     * Mensch sieht das je wieder.
     *
     * Geschlossen, nicht gekuendigt: ein Schalter ist keine Kuendigung, und ein
     * Haus, das die Mahnstrecke abstellt, hat sich damit gegen den automatischen
     * Zugangsentzug entschieden, nicht dafuer. Das Abo bleibt, wie der Anbieter
     * es gesetzt hat, und die Zyklus-Zeile ist wieder aufraeumbar.
     */
    protected function closeEverything(Dunning $dunning, bool $dry): int
    {
        $offen = 0;

        foreach ($dunning->running() as $subscription) {
            // Zeile fuer Zeile, wie im Hauptlauf und aus demselben Grund:
            // `running()` sortiert nach dem Beginn der Strecke, also stuende
            // eine kaputte Zeile morgen wieder vorn und alles dahinter bliebe
            // eingefroren — genau der Zustand, den dieser Pfad aufloesen soll.
            try {
                $dry || $dunning->stop($subscription);
                $offen++;
            } catch (Throwable $e) {
                $this->broken++;

                Log::error('statamic-payments: closing a dunning sequence threw; the run continued with the next one.', [
                    'subscription_id' => $subscription->getKey(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $this->warn(match (true) {
            $offen === 0 && $this->broken === 0 => 'statamic-payments.dunning.enabled is off; nothing was done.',
            $dry => "statamic-payments.dunning.enabled is off; {$offen} running sequence(s) would be closed.",
            default => "statamic-payments.dunning.enabled is off; {$offen} running sequence(s) were closed rather than left frozen.",
        });

        if ($this->broken > 0) {
            $this->error("{$this->broken} sequence(s) threw and were skipped; see the log.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** One agreement: settle, end, or write the letter that is due. */
    protected function pass(Dunning $dunning, DunningNotice $notice, Subscription $subscription, bool $dry): void
    {
        // Eine Vereinbarung, die es nicht mehr gibt, wird nicht gemahnt.
        //
        // `begin()` haelt gekuendigte Abos heraus, aber nur beim Oeffnen. Wer
        // am Tag 4 im Portal kuendigt, statt die Karte zu reparieren, bekam
        // danach Brief zwei und drei — mit „Ohne ein gueltiges Zahlungsmittel
        // endet der Zugang" und einem Link zum Kartenwechsel an jemanden, der
        // gerade gegangen ist — und am Tag 21 ein zweites `SubscriptionEnded`
        // samt Kuendigungsversuch gegen einen Anbieter, der die Vereinbarung
        // laengst beendet hat. `cancel()` raeumt die `dunning_*`-Spalten nicht
        // ab, also blieb die Strecke stehen.
        //
        // `suspended` gehoert ausdruecklich dazu: genau das setzt Mollie, wenn
        // eine Abbuchung scheitert. Ein blankes `isLive()` legte die Mollie-
        // Strecke vollstaendig stumm.
        if (in_array($subscription->status, [Subscription::STATUS_CANCELLED, Subscription::STATUS_COMPLETED], true)) {
            $dry || $dunning->stop($subscription);
            $this->closed++;

            return;
        }

        // The provider first, always. It retries on its own rhythm, and a card
        // that went through between two letters ends the sequence silently —
        // writing to somebody who has already paid is the one outcome worth
        // more than the letter.
        $settled = $dunning->settledMeanwhile($subscription);

        if ($settled === true) {
            $dry || $dunning->stop($subscription);
            $this->stopped++;

            return;
        }

        // The end does not wait for the provider, and that order matters.
        // `dueToEnd()` is a question about dates alone; hanging it behind a
        // successful provider call means a multi-day outage freezes every
        // running sequence — no further letters, but no ending either, long
        // after the grace period is up. The money has not arrived either way,
        // and an agreement nobody can reach is not a reason to keep giving the
        // access away.
        if ($dunning->dueToEnd($subscription)) {
            if ($dry || $dunning->end($subscription)) {
                $this->ended++;
            }

            return;
        }

        if ($settled === null) {
            // The provider would not answer. Logged there; here it simply means
            // no letter goes out on a guess this run.
            $this->unreachable++;

            return;
        }

        $stage = $dunning->stageDue($subscription);

        if ($stage === null) {
            return;
        }

        if ($dry) {
            $this->sent++;

            return;
        }

        // The claim comes before the mail, so two workers cannot both write
        // this letter. If the send then fails, the claim goes back and the
        // next run tries the same stage again.
        if (! $dunning->claimStage($subscription, $stage)) {
            return;
        }

        match ($notice->send($subscription, $stage)) {
            DunningNotice::SENT => $this->sent++,
            DunningNotice::SKIPPED => $this->skipped++,
            default => $this->giveBack($dunning, $subscription, $stage),
        };
    }

    protected function giveBack(Dunning $dunning, Subscription $subscription, int $stage): void
    {
        $dunning->releaseStage($subscription, $stage);
        $this->withheld++;
    }

    /**
     * What the run did, in numbers that can be told apart.
     *
     * „0 letter(s) sent" allein sah identisch aus fuer „nichts faellig",
     * „Migration nicht gelaufen, `running()` ist leer" und „jeder
     * Anbieteraufruf gescheitert". Deshalb steht die Gegenzahl davor: null von
     * null ist ein ruhiger Tag, null von 312 ist ein Defekt.
     */
    protected function report(bool $dry): void
    {
        $zeilen = [
            $dry
                ? "Dry run: {$this->seen} sequence(s) checked, {$this->sent} letter(s) due, {$this->ended} agreement(s) would end, {$this->stopped} settled meanwhile."
                : "{$this->seen} sequence(s) checked, {$this->sent} letter(s) sent, {$this->ended} agreement(s) ended, {$this->stopped} closed because the money arrived.",
        ];

        if ($this->closed > 0) {
            $zeilen[] = "{$this->closed} sequence(s) closed because the agreement had already ended.";
        }

        if ($this->skipped > 0) {
            $zeilen[] = "{$this->skipped} stage(s) counted without a letter (no address, or on the suppression list).";
        }

        if ($this->unreachable > 0) {
            $zeilen[] = "{$this->unreachable} sequence(s) skipped because the provider would not say whether they had been paid.";
        }

        if ($this->withheld > 0) {
            $zeilen[] = "{$this->withheld} letter(s) could not be sent; the stage was given back and the next run tries again.";
        }

        $this->info(implode(' ', $zeilen));

        if ($this->broken > 0) {
            $this->error("{$this->broken} sequence(s) threw and were skipped; see the log.");
        }
    }
}
