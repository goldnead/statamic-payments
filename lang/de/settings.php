<?php

return [

    // Beschriftungen der Einstellungs-Seite. Die Seite selbst gehört
    // statamic-brand-context; dieses Addon liefert nur die Feldliste
    // (Support\Settings) und die Wörter dazu.

    'permission_manage_settings' => 'Zahlungs-Einstellungen verwalten',

    'groups' => [

        'checkout' => [
            'title' => 'Kasse',
            'description' => 'Was für alle Käufe gilt. Der Katalog selbst (`products`) ist eine verschachtelte Abbildung und bleibt in config/statamic-payments.php; ebenso `methods`, weil dort steht, was aus der .env kommt — eine kommagetrennte Zeichenkette, keine Liste. Der Mollie-Schlüssel gehört nicht hierher und wird es nie: er bleibt in der .env, damit er nicht in jeder Datenbanksicherung liegt.',
        ],

        'withdrawal' => [
            'title' => 'Widerrufsbutton, § 356a BGB',
            'description' => 'Seit dem 19.06.2026 muss ein Laden, der im Fernabsatz mit Verbrauchern abschließt, eine elektronische Widerrufsfunktion anbieten. Das Addon bringt die Form mit; die beiden Angaben, die nur Sie haben — wohin ein Widerruf gemeldet wird und wo Ihre Widerrufsbelehrung liegt — stehen hier. Adresse und Drosselung der Routen bleiben in config/statamic-payments.php: sie werden gelesen, während die Routen entstehen, und eine Änderung käme dort erst nach dem nächsten Deploy an.',
        ],

        'cancellation' => [
            'title' => 'Kündigungsbutton, § 312k BGB',
            'description' => 'Der zweite, anmeldefreie Weg, einen laufenden Vertrag zu kündigen. Dieselbe Aufteilung wie beim Widerruf: Meldeadresse und Belehrung hier, Routenadresse und Drosselung in der Konfiguration.',
        ],

        'portal' => [
            'title' => 'Kundenkonto',
            'description' => 'Die eigenen Bildschirme des Käufers, erreichbar über einen signierten Link an die Adresse auf der Bestellung — ohne Konto und ohne Passwort. Adresse, Middleware und die drei Drosselungen bleiben in config/statamic-payments.php: die ersten beiden werden beim Registrieren der Routen gelesen, die Drosselungen sind teils verschachtelt und teils dasselbe. Auch `min_response_ms` bleibt dort — der Wert hält die Antwort auf eine Mindestlaufzeit, damit sie nicht verrät, ob eine Adresse hier je gekauft hat, und ein Feld, dessen einzige Wirkung wäre, diesen Schutz zu senken, ist kein Betreiberwert.',
        ],

        'legal' => [
            'title' => 'Belege und Einwilligung',
            'description' => 'Was auf einer Empfangsbestätigung steht und welche Einwilligungssätze als echt gelten.',
        ],

        'abandoned' => [
            'title' => 'Abgebrochene Kassen',
            'description' => 'Jemand hat eine Kasse begonnen und nicht abgeschlossen. Ob eine Erinnerung rausgehen darf, ist eine Einwilligungsfrage — die Adresse wurde gegeben, um einen Kauf abzuschließen, nicht um Werbung zu bekommen. Klären Sie das, bevor Sie hier etwas einschalten.',
        ],

        'bridges' => [
            'title' => 'Angeschlossene Addons',
            'description' => 'Was dieses Addon an Geschwister weitergibt. Alles aus, solange es niemand einschaltet: zwei Addons, die aus unabhängigen Gründen installiert sind, dürfen nicht anfangen, Kundendaten auszutauschen, weil sie im selben vendor-Verzeichnis liegen.',
        ],

    ],

    'fields' => [

        'currency' => [
            'label' => 'Währung',
            'description' => 'Dreibuchstabiger Code, zum Beispiel EUR.',
        ],
        'return_url' => [
            'label' => 'Rückkehr nach der Zahlung',
            'description' => 'Wohin der Anbieter den Käufer nach dem Bezahlen schickt. Das ist nicht die Stelle, an der die Lieferung passiert: wer den Tab schließt, hat trotzdem bezahlt, und wer hier ankommt, nicht unbedingt. Darüber entscheidet allein der Webhook.',
        ],
        'max_quantity' => [
            'label' => 'Höchstmenge je Kauf',
            'description' => 'Ein Fangnetz, keine Geschäftsregel. Die Menge ist die einzige Zahl, die eine Kasse aus einer Anfrage übernimmt — der Einzelpreis nie —, und eine vertippte oder feindselige Zahl darf keine fünfstellige Belastung werden. Produkte mit eigener Mengenspanne setzen sich darüber hinweg.',
        ],
        'prune_unpaid_after_days' => [
            'label' => 'Unbezahlte Kassen löschen nach (Tagen)',
            'description' => 'Nicht Ordnungsliebe: was in der Zeile steht, sind Name und Adresse von jemandem, mit dem nie ein Vertrag zustande kam. 0 schaltet das Löschen ab. Wählen Sie eine Zahl, die zu Ihren laufenden Erinnerungsstrecken passt.',
        ],

        'withdrawal_enabled' => [
            'label' => 'Widerrufsfunktion anbieten',
            'description' => 'An, weil ein Addon, das eine gesetzliche Pflicht abgeschaltet ausliefert, sie an niemanden ausliefert. Reine B2B-Läden dürfen sie ausschalten.',
        ],
        'withdrawal_notify' => [
            'label' => 'Meldeadresse',
            'description' => 'Wohin Ihre Kopie eines Widerrufs geht, mit der zugeordneten Zahlung und den Hinweisen. Leer nimmt die Absenderadresse des Kundenkontos und danach die der Anwendung. Die Empfangsbestätigung an den Verbraucher geht unabhängig davon raus.',
        ],
        'withdrawal_policy_url' => [
            'label' => 'Widerrufsbelehrung (URL)',
            'description' => 'Wo Ihre Belehrung liegt. Die Formularseite verlinkt darauf; die Belehrung selbst ist Ihr Dokument, nicht das des Addons.',
        ],
        'withdrawal_days' => [
            'label' => 'Widerrufsfrist (Tage)',
            'description' => 'Wird nur dazu benutzt, Ihnen zu sagen, ob eine Erklärung innerhalb der Frist kam. Der Verbraucher wird an dieser Zahl nie abgewiesen — ob die Frist gelaufen ist, ist eine Rechtsfrage, die die Zeile nicht allein klären kann.',
        ],

        'cancellation_enabled' => [
            'label' => 'Kündigungsfunktion anbieten',
            'description' => 'Der anmeldefreie Weg, § 312k BGB. Die Kündigung im Kundenkonto bleibt als bequemer Weg für jemanden, der ohnehin gerade auf seinen Vertrag schaut.',
        ],
        'cancellation_notify' => [
            'label' => 'Meldeadresse',
            'description' => 'Wohin Ihre Kopie einer Kündigung geht. Leer wie beim Widerruf.',
        ],
        'cancellation_policy_url' => [
            'label' => 'Kündigungshinweise (URL)',
            'description' => 'Eine Seite von Ihnen, die Fristen und dergleichen erklärt. Wird von der Formularseite verlinkt, wenn gesetzt.',
        ],

        'portal_enabled' => [
            'label' => 'Kundenkonto anbieten',
            'description' => 'Bestellungen, Rechnung, Kündigung und Kartenwechsel für den Käufer.',
        ],
        'portal_link_ttl_minutes' => [
            'label' => 'Gültigkeit des Links (Minuten)',
            'description' => 'Das ist der ganze Widerruf — es gibt keine Token-Tabelle, gegen die man zurückziehen könnte. Also eher kürzen als verlängern. Dreißig Minuten reichen, damit eine Mail zugestellt und gelesen wird.',
        ],
        'portal_session_minutes' => [
            'label' => 'Dauer des Besuchs (Minuten)',
            'description' => 'Eine eigene Uhr, unabhängig von der Sitzungsdauer für angemeldete Mitarbeiter: ein Käufer an einem geteilten Rechner ist kein Mitarbeiter am Schreibtisch.',
        ],
        'portal_max_rows' => [
            'label' => 'Höchstzahl Zeilen',
            'description' => 'Eine Obergrenze für die Seite, keine Geschäftsregel: wer vierhundert Bestellungen hat, soll keine vierhundert Zeilen auf einem Telefon bekommen.',
        ],
        'portal_mandate_verification_cent' => [
            'label' => 'Betrag für die Kartenprüfung (Cent)',
            'description' => 'Das kostet echtes Geld, und daran führt kein Weg vorbei: Mollie kennt keine Null-Betrag-Autorisierung, ein Mandat entsteht nur aus einer Zahlung. Ein Cent ist die übliche Antwort für Karten; manche Zahlarten haben einen höheren Boden und lehnen ab. Der Käufer sieht den Betrag vor dem Knopf.',
        ],
        'portal_from_address' => [
            'label' => 'Absenderadresse',
            'description' => 'Wovon die beiden Mails des Kundenkontos kommen. Leer nimmt die Absenderadresse der Anwendung, was auf einer einmarkigen Installation richtig ist.',
        ],
        'portal_from_name' => [
            'label' => 'Absendername',
            'description' => '',
        ],
        'portal_ignored_query_parameters' => [
            'label' => 'Zu ignorierende Parameter',
            'description' => 'Eine je Zeile. Parameter, die ein Mailanbieter unterwegs an den Link hängt und die die Signaturprüfung sonst stolpern lassen — `_se` kommt zum Beispiel von Brevos Klickzähler. `expires` und `signature` können hier nie stehen, was auch immer eingetragen wird.',
        ],

        'legal_timezone' => [
            'label' => 'Zeitzone der Belege',
            'description' => 'In welcher Zone Datum und Uhrzeit einer Empfangsbestätigung angegeben werden. Leer nimmt die Zone der Anwendung. Setzen Sie sie, wenn die Anwendung in UTC läuft und der Laden nicht: die Uhrzeit auf einem Beleg sollte die des Händlers sein.',
        ],
        'consent_accepted_texts' => [
            'label' => 'Anerkannte Einwilligungssätze',
            'description' => 'Einer je Zeile, im genauen Wortlaut Ihrer eigenen Kasse. Ein eingereichter Einwilligungstext wird nur dann auf die Zeile geschrieben, wenn er einer von diesen ist — ein verstecktes Feld ist ein Feld, das jeder bearbeiten kann, und ein Beleg, dessen Wortlaut sich der Käufer ausgesucht hat, belegt nichts.',
        ],

        'abandoned_enabled' => [
            'label' => 'Abbrüche melden',
            'description' => 'Kündigt abgebrochene Kassen als Ereignis an, je einmal. Eine Strecke in statamic-automations kann das aufnehmen.',
        ],
        'abandoned_after_minutes' => [
            'label' => 'Gilt als abgebrochen nach (Minuten)',
            'description' => 'In Minuten und nicht in Stunden, weil die Grenze zwischen „tippt noch" und „weg" bei einem Neun-Euro-Download eine andere ist als bei einem Kurs für zweitausend.',
        ],
        'abandoned_mail_enabled' => [
            'label' => 'Erinnerung senden',
            'description' => 'Einen Abbruch anzukündigen und der Person zu schreiben sind zwei Entscheidungen. Die Einwilligungsfrage von oben gilt hier doppelt.',
        ],
        'abandoned_mail_template' => [
            'label' => 'Vorlage',
            'description' => 'Ein Slug aus statamic-email-templates. Ohne das Geschwisterpaket geht eine eingebaute Mail raus.',
        ],
        'abandoned_mail_subject' => [
            'label' => 'Betreff',
            'description' => '',
        ],
        'abandoned_mail_resume_url' => [
            'label' => 'Ziel des Knopfes',
            'description' => 'Leer baut einen signierten Link, der die Kasse mit denselben Zeilen neu beginnt. Eine eigene Adresse darf `{payment}` für die Kennung tragen.',
        ],
        'abandoned_mail_resume_days' => [
            'label' => 'Gültigkeit des Links (Tage)',
            'description' => '',
        ],

        'follow_up_enabled' => [
            'label' => 'Folgeangebote',
            'description' => 'Ein Angebot nach der Zahlung, belastet ohne erneute Kartendaten. Zum Einschalten gehört mehr als dieser Schalter: das Mandat unten muss ebenfalls an sein, und die Angebotsseite braucht ihren eigenen Bestellknopf nach § 312j BGB. Siehe docs/follow-up-offers.md.',
        ],
        'follow_up_collect_mandate' => [
            'label' => 'Mandat bei der ersten Zahlung einholen',
            'description' => 'Lässt die erste Zahlung den Anbieter bitten, sich den Käufer zu merken — das ist es, was eine spätere Belastung überhaupt möglich macht. Dem Käufer muss das auf der Kassenseite gesagt werden.',
        ],
        'leadhub_enabled' => [
            'label' => 'An statamic-leadhub melden',
            'description' => 'Schreibt einen bezahlten Kauf auf die Zeitleiste des Kontakts und in seinen Lebenszeitumsatz. Es reisen Adresse, Name, Gekauftes, Bezahltes und die eingefrorene Kampagne — eine Entscheidung über personenbezogene Daten, deshalb ein Schalter und keine Vorgabe.',
        ],
        'entitlements_enabled' => [
            'label' => 'An statamic-entitlements melden',
            'description' => 'Gibt dem Käufer die Berechtigung, die am Produkt unter `grants` steht. Aus für jedes Produkt ohne `grants`.',
        ],

    ],

];
