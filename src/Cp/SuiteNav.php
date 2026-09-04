<?php

namespace Goldnead\StatamicPayments\Cp;

/**
 * Der Name des Navigationsabschnitts, unter dem die Verkaufs-Bildschirme der
 * Suite haengen.
 *
 * ## Warum das eine eigene Klasse ist und kein String in fuenf Dateien
 *
 * Statamic uebersetzt Abschnittsnamen nicht.
 * `NavBuilder::ensureSectionConfigHasDisplay()` nimmt den uebergebenen
 * Schluessel und zeigt ihn an, wie er ist. Zwei Addons, die „Verkauf" und
 * „Shop" schicken, erzeugen deshalb **zwei** Abschnitte nebeneinander, jeder
 * halb gefuellt — und niemand sieht dem Code an, warum.
 *
 * Also gibt es genau eine Quelle. `statamic-payments` ist dafuer der richtige
 * Ort: offers, funnels und products haengen ohnehin an diesem Paket
 * (`composer.json`), koennen die Methode also direkt rufen.
 *
 * `statamic-booking` haengt nicht daran und fragt per `class_exists` — steht
 * payments daneben, landen beide im selben Abschnitt; laeuft booking allein,
 * nimmt es seinen eigenen Titel. Das ist die Absicht: ein Abschnitt „Verkauf"
 * mit einem einzigen Eintrag waere in einer Installation ohne Kasse eine
 * Ueberschrift ohne Inhalt.
 *
 * ## Warum ueberhaupt
 *
 * Die neun Verkaufs-Bildschirme sind als Statamic-*Utilities* registriert und
 * landeten damit unter „Hilfsmittel" — zwischen Cache, PHP-Info und Suche.
 * Adrian am 03.09.2026: „Dass da einige unter ‚Utilities' auftauchen ist komisch
 * und verwirrend." Die Routen bleiben, wo sie sind (`cp/utilities/…`), damit
 * kein Lesezeichen und kein Recht bricht; nur der Weg dorthin steht jetzt in
 * der Seitenleiste.
 */
class SuiteNav
{
    /**
     * Der Abschnittstitel, uebersetzt aus der Sprachdatei dieses Pakets.
     *
     * Wer ihn aendert, aendert ihn fuer alle fuenf Addons — das ist der Sinn.
     */
    public static function section(): string
    {
        return __('statamic-payments::messages.nav_section');
    }
}
