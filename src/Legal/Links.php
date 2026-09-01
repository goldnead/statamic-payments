<?php

namespace Goldnead\StatamicPayments\Legal;

/**
 * Die zwei Adressen, die in den Footer gehören.
 *
 * § 356a Abs. 1 BGB verlangt die Widerrufsschaltfläche „während des Laufs der
 * Widerrufsfrist auf der Online-Benutzeroberfläche ständig verfügbar,
 * hervorgehoben platziert und für den Verbraucher leicht zugänglich"; § 312k
 * Abs. 2 verlangt dasselbe für die Kündigungsschaltfläche, solange ein
 * Dauerschuldverhältnis über die Seite geschlossen werden kann. Ein Footer, der
 * auf jeder Seite steht, ist der Ort, an dem beides zutrifft.
 *
 * Null, wenn der jeweilige Weg abgeschaltet ist — dann gibt es nichts zu
 * verlinken, und ein Link auf eine 404 wäre schlimmer als keiner.
 *
 * In Antlers: `{{ payments:withdrawal_url }}` und `{{ payments:cancellation_url }}`.
 */
final class Links
{
    public static function withdrawal(): ?string
    {
        if (! config('statamic-payments.withdrawal.enabled', true)) {
            return null;
        }

        return route('statamic-payments.withdrawal.form');
    }

    public static function cancellation(): ?string
    {
        if (! config('statamic-payments.cancellation.enabled', true)) {
            return null;
        }

        return route('statamic-payments.cancellation.form');
    }
}
