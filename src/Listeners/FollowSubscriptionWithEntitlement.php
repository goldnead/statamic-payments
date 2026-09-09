<?php

namespace Goldnead\StatamicPayments\Listeners;

use Goldnead\StatamicPayments\Events\SubscriptionCancelled;
use Goldnead\StatamicPayments\Events\SubscriptionEnded;
use Goldnead\StatamicPayments\Events\SubscriptionRenewed;
use Goldnead\StatamicPayments\Integrations\EntitlementsBridge;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Dunning;
use Goldnead\StatamicPayments\Support\Subscriptions;

/**
 * A subscription and the access it pays for, kept in step.
 *
 * Autoloaded by `AddonServiceProvider` off the first parameter type of each
 * `handle*` method — which is why they are named rather than one `handle()`.
 *
 * Without this, every installation wrote the same three listeners itself, and
 * the interesting one is the middle: cancelling is **not** revoking. Somebody
 * who cancels has paid for the period they are in and keeps it to the end.
 *
 * **Und ein Ende ist nicht immer ein Ende.** `SubscriptionEnded` kommt aus zwei
 * Richtungen, die das Gegenteil voneinander bedeuten, und der Unterschied steht
 * im `status` der Zeile. Wer das nicht liest, nimmt dem Käufer genau das weg,
 * wofür er gerade fertig bezahlt hat — siehe {@see handleEnded()}.
 */
class FollowSubscriptionWithEntitlement
{
    public function __construct(protected EntitlementsBridge $bridge) {}

    public function handleRenewed(SubscriptionRenewed $event): void
    {
        $this->bridge->renewFor($event->subscription, $event->payment);
    }

    public function handleCancelled(SubscriptionCancelled $event): void
    {
        $this->bridge->closeFor($event->subscription);
    }

    /**
     * Ein Ende, zwei Bedeutungen.
     *
     * - **`cancelled`** — die Mahnstrecke hat aufgegeben, es wurde nicht
     *   bezahlt ({@see Dunning}). Der Zugang
     *   läuft zum Ende des bezahlten Zeitraums aus.
     * - **`completed`** — der Plan hat seine letzte Rate bekommen
     *   ({@see Subscriptions::recordCycle()}).
     *   Alles bezahlt. Der Zugang bleibt.
     *
     * **Bis 09.09.2026 schloss beides.** Ein Ratenkauf über 3 × 520 € nahm dem
     * Käufer den Zugang in der Sekunde, in der die dritte Rate durchging: er
     * hatte 1.560 € überwiesen, vollständig, und verlor dafür, was er gekauft
     * hatte. Aufgefallen an einem echten Testkauf auf staging, nicht im Code.
     *
     * Und es konnte nur diesen Fall treffen: `closeFor()` fasst ausschließlich
     * Zugänge ohne Ablaufdatum an. Ein Zugang mit Ablaufdatum läuft von selbst
     * aus und wird dort übersprungen. Ein **offener** Zugang ist aber gerade
     * der, den ein Kauf auf Dauer vergibt — die Sache selbst, in Raten bezahlt.
     * Ein Zeitabo, das mit dem letzten Einzug enden soll, vergibt sein Fenster
     * über `access_days` und wird je Zyklus mit `renewFor()` verlängert.
     *
     * Der Preis dieser Regel, ausgesprochen: wer ein befristetes Abo mit einem
     * unbefristeten `grants` verkauft und sich darauf verlässt, dass das Ende
     * es zurücknimmt, behält den Zugang jetzt. Das ist die kleinere von zwei
     * Schieflagen — die andere nimmt jemandem etwas weg, das er bezahlt hat.
     */
    public function handleEnded(SubscriptionEnded $event): void
    {
        if ($event->subscription->status === Subscription::STATUS_COMPLETED) {
            return;
        }

        $this->bridge->closeFor($event->subscription);
    }
}
