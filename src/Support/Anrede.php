<?php

namespace Goldnead\StatamicPayments\Support;

use Illuminate\Support\Facades\Lang;

/**
 * Du oder Sie in allem, was ein Käufer liest: Kasse, Kundenkonto, Widerruf,
 * Kündigung, Erinnerungen und Mahnungen.
 *
 * **Warum eine Einstellung und keine Umstellung.** Die Texte dieses Addons
 * siezen seit dem ersten Release, auf Installationen, die sie so ausliefern,
 * Widerruf und Kündigung eingeschlossen. Andere Addons der Familie (Konto,
 * Team) duzen. Eine Seite, die mitten im Konto die Anrede wechselt, liest sich
 * wie zwei Läden (Befund aus ChoirLive, 25.09.2026). Welche Anrede ein Laden
 * spricht, entscheidet der Laden, nicht ein Update: `anrede` ist `sie`, bis
 * jemand `du` wählt.
 *
 * **Wie.** Die geduzten Zeilen liegen neben den gesiezten in
 * `lang/de/du/<gruppe>.php`, nur die, die sich unterscheiden. Gefragt wird
 * zur Laufzeit, nicht beim Booten: die Einstellung kann je Marke verschieden
 * sein, und `brand-context` legt die Config beim Markenwechsel neu. Eine
 * Sprache ohne du-Datei (Englisch) bleibt, wie sie ist. Eine Installation, die
 * die Übersetzungen veröffentlicht hat, kann die du-Zeilen dort genauso
 * überschreiben (`lang/vendor/statamic-payments/de/du/…`).
 */
final class Anrede
{
    public const SIE = 'sie';

    public const DU = 'du';

    protected const PREFIX = 'statamic-payments::';

    public static function current(): string
    {
        return config('statamic-payments.anrede') === self::DU ? self::DU : self::SIE;
    }

    /**
     * `__()` mit der Anrede des Ladens.
     *
     * @param  array<string, mixed>  $replace
     */
    public static function trans(string $key, array $replace = [], ?string $locale = null): string
    {
        return (string) __(self::key($key, $locale), $replace, $locale);
    }

    /**
     * Der Schlüssel, der für die aktuelle Anrede gilt: die du-Zeile, wenn es
     * eine gibt, sonst der Schlüssel selbst.
     */
    public static function key(string $key, ?string $locale = null): string
    {
        if (self::current() !== self::DU || ! str_starts_with($key, self::PREFIX)) {
            return $key;
        }

        $du = self::PREFIX.'du/'.substr($key, strlen(self::PREFIX));

        return Lang::hasForLocale($du, $locale) ? $du : $key;
    }
}
