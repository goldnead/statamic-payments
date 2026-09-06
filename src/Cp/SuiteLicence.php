<?php

namespace Goldnead\StatamicPayments\Cp;

/**
 * Ob im Control Panel ein Hinweis auf die fehlende Suite-Lizenz stehen soll.
 *
 * ## Ein Hinweis, kein Schloss
 *
 * Adrian hat am 05.09.2026 Weg **B** gewaehlt: aus „vergessen" ein „bewusst
 * ignoriert" machen, und sonst nichts. Diese Klasse sperrt darum nie etwas,
 * verlangsamt nie etwas und prueft nie einen Schluessel. Sie beantwortet eine
 * einzige Frage — soll der Hinweis erscheinen — und liest dafuer eine lokale
 * Einstellung.
 *
 * **Kein Netzwerkaufruf.** Nicht zu uns, nicht zu Statamic, zu niemandem. Wer
 * hier jemals einen `Http::`-Aufruf einbaut, hat aus B ein C gemacht.
 *
 * Warum keine echte Pruefung, belegt am 05.09.2026 im Quellcode: Statamic
 * bietet gar keinen Andockpunkt (`LicenseManager::addons()` baut seine Liste
 * ausschliesslich aus der Outpost-Antwort, ohne Setter, Event oder Filter), und
 * eine eigene Pruefung braucht zwingend einen eigenen Server — den Adrian am
 * 31.08.2026 bewusst verworfen hat. Dazu kommt: Statamic faengt Netzfehler ab
 * und schweigt (fail-open). Ein Addon, das stumpf auf `valid()` gated, wird bei
 * jedem Netzproblem **fail-closed** und sperrt zahlende Kunden aus. Keine
 * Pruefung ist hier sicherer als eine.
 *
 * ## Warum das hier wohnt und nicht in jedem Paket
 *
 * Bei sechzehn Paketen ist ein naiver Bau sechzehn Hinweise nebeneinander. Es
 * gibt deshalb genau eine Stelle, und sie liegt neben {@see SuiteNav} — dem
 * anderen gemeinsamen CP-Moebel der Suite, das die Geschwister per
 * `class_exists` einbinden. Wer den Hinweis in einem zweiten Paket noch einmal
 * baut, macht ihn doppelt.
 *
 * **Nicht in `statamic-brand-context`.** Das Paket ist MIT lizenziert; ein
 * Hinweis auf kommerzielle Ware gehoert nicht in ein freies Paket. Und der dort
 * vorhandene Haken `brand-context.license_check` ist ausdruecklich ein *Gate*
 * fuer Multi-Brand, also genau das, was hier nicht passieren soll.
 */
class SuiteLicence
{
    /**
     * Ob der Hinweis erscheinen soll.
     *
     * Zwei Bedingungen, beide lokal:
     *
     *  - Es ist eine Produktionsinstallation. Niemand soll beim Entwickeln ein
     *    Banner wegklicken, und ohne eigenen Lizenzserver ist die Umgebung das
     *    einzige, woran sich „echter Betrieb" hier festmachen laesst. Statamic
     *    entscheidet dasselbe serverseitig und liest im Client nur ein fertiges
     *    Feld — dieser Weg steht ohne Server nicht offen.
     *  - Es steht kein Schluessel da.
     */
    public static function noticeNeeded(): bool
    {
        return app()->environment('production') && ! self::hasKey();
    }

    /**
     * Ob ueberhaupt etwas eingetragen ist.
     *
     * **Absichtlich nur eine Anwesenheitsfrage.** Der Schluessel wird nicht
     * geprueft, weder gegen einen Server noch kryptografisch. Wer sich einen
     * ausdenkt, bekommt keinen Hinweis mehr — und genau das ist gewollt: die
     * Entscheidung liegt dann sichtbar beim Betreiber und nicht mehr bei einem
     * uebersehenen Kasten.
     *
     * Leerzeichen zaehlen nicht als Eintrag. Ein `SUITE_LICENSE_KEY=` in der
     * `.env` ist kein Schluessel, sondern eine leere Zeile, und ein Betreiber,
     * der glaubt, er habe etwas eingetragen, soll den Hinweis weiter sehen.
     */
    public static function hasKey(): bool
    {
        $key = config('statamic-payments.suite.license_key');

        return is_string($key) && trim($key) !== '';
    }

    /**
     * Was der Hinweis dem Browser mitgibt.
     *
     * Vom Server entschieden, damit im Client nichts zu entscheiden bleibt —
     * dasselbe Muster, das Statamic fuer sein eigenes Banner benutzt. Der
     * Schluessel selbst geht NIE mit: die Seite braucht ihn nicht, und was
     * nicht rausgeht, kann nicht in einem Screenshot landen.
     *
     * @return array<string, mixed>
     */
    public static function forScript(): array
    {
        return [
            'needed' => self::noticeNeeded(),
            'days' => max(1, (int) config('statamic-payments.suite.notice_days', 30)),
            'url' => (string) config('statamic-payments.suite.buy_url', 'https://suite.adriangoldner.dev'),
        ];
    }
}
