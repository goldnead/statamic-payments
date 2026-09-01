<?php

/*
|--------------------------------------------------------------------------
| Widerruf — § 356a BGB
|--------------------------------------------------------------------------
|
| Jedes Wort, das ein Verbraucher auf dem Weg durch den Widerruf liest, steht
| hier. Zwei davon schreibt das Gesetz vor: die Schaltfläche „Vertrag
| widerrufen" (Abs. 1) und die Bestätigungsschaltfläche „Widerruf bestätigen"
| (Abs. 3). Sie stehen hier unverändert und werden nicht ausgeschmückt.
|
| Der Rest ist Wortlaut, der vor einen Anwalt gehört und ohne Release dieses
| Addons änderbar sein muss:
|
|     php artisan vendor:publish --tag=statamic-payments-translations
|
| Rechtliche Entscheidungen 01.09.2026, von Adrian zu prüfen. Dies ist keine
| Rechtsberatung.
|
*/

return [

    /*
    | § 356a Abs. 1 BGB: der Wortlaut der Schaltfläche im Footer. Dieser String
    | ist für den Host da — `{{ payments:withdrawal_url }}` liefert nur die
    | Adresse, die Beschriftung setzt die Seite selbst, und sie soll dieses
    | Wort nehmen.
    */
    'button' => 'Vertrag widerrufen',

    // Schritt 1
    'title' => 'Vertrag widerrufen',
    'intro' => 'Hier können Sie einen über diese Website geschlossenen Vertrag widerrufen. Geben Sie an, wer Sie sind und welche Bestellung Sie meinen. Im nächsten Schritt sehen Sie Ihre Angaben noch einmal und bestätigen den Widerruf.',
    'errors_intro' => 'Bitte prüfen Sie die markierten Felder.',
    'field_name' => 'Name',
    'field_email' => 'E-Mail-Adresse',
    'field_email_help' => 'Die Adresse, mit der Sie bestellt haben. An sie geht die Eingangsbestätigung.',
    'field_reference' => 'Bestellnummer oder Bestellkennung',
    'field_reference_help' => 'Aus der Bestellbestätigung oder der Rechnung. Wenn Sie sie nicht finden, beschreiben Sie die Bestellung in der Nachricht.',
    'field_contact' => 'Wie sollen wir Sie erreichen?',
    'field_contact_placeholder' => 'E-Mail (voreingestellt), Telefon oder Anschrift',
    'field_contact_help' => 'Leer gelassen, nehmen wir Ihre E-Mail-Adresse.',
    'field_message' => 'Nachricht (optional)',
    'continue' => 'Weiter zur Bestätigung',
    'continue_hint' => 'Mit diesem Schritt ist noch nichts widerrufen.',
    'policy_link' => 'Widerrufsbelehrung',
    'foot' => 'Ihre Angaben werden zur Bearbeitung des Widerrufs gespeichert.',

    // Schritt 2
    'confirm_title' => 'Widerruf bestätigen',
    'confirm_intro' => 'Bitte prüfen Sie Ihre Angaben. Mit der Schaltfläche unten erklären Sie den Widerruf.',
    'confirm_effect' => 'Nach der Bestätigung erhalten Sie sofort eine Eingangsbestätigung mit Kennung und Zeitpunkt an Ihre E-Mail-Adresse.',

    /*
    | § 356a Abs. 3 BGB: der Wortlaut der Bestätigungsschaltfläche ist der vom
    | Gesetz vorgeschriebene.
    */
    'confirm_button' => 'Widerruf bestätigen',
    'confirm_back' => 'Angaben ändern',
    'restart' => 'Diese Seite ist nicht mehr erreichbar. Bitte beginnen Sie erneut.',

    // Schritt 3
    'done_title' => 'Ihr Widerruf ist eingegangen',
    'done_received' => 'Der Widerruf ist bei uns eingegangen am :date um :time Uhr (:zone).',
    'done_id_label' => 'Ihre Widerrufs-Kennung',
    'done_mailed' => 'Eine Eingangsbestätigung mit dieser Kennung ist an Ihre E-Mail-Adresse unterwegs.',
    'done_not_mailed' => 'Der Widerruf ist eingegangen. Die Eingangsbestätigung per E-Mail konnte gerade nicht zugestellt werden — bitte notieren Sie die Kennung und den Zeitpunkt oder bewahren Sie diese Seite auf.',
    'done_keep' => 'Bitte bewahren Sie die Kennung als Nachweis auf. Wir melden uns bei Ihnen über das angegebene Kontaktmittel.',

    // Mail an den Verbraucher (§ 356a Abs. 4)
    'mail_receipt_subject' => 'Eingang Ihres Widerrufs :id',
    'mail_greeting' => 'Guten Tag,',
    'mail_receipt_body' => 'der Widerruf ist bei uns eingegangen am :date um :time Uhr (:zone).',
    'mail_receipt_id' => 'Widerrufs-Kennung',
    'mail_receipt_reference' => 'Ihre Bestellkennung',
    'mail_receipt_next' => 'Wir prüfen den Vorgang und melden uns bei Ihnen. Diese Nachricht bestätigt den Eingang Ihrer Erklärung.',
    'mail_keep' => 'Bitte bewahren Sie diese Nachricht als Nachweis auf.',

    // Mail an den Händler
    'mail_merchant_subject' => 'Widerruf eingegangen: :id',
    'mail_merchant_body' => 'Ein Widerruf mit der Kennung :id ist am :date um :time Uhr (:zone) eingegangen.',
    'mail_merchant_matched' => 'Zugeordnete Zahlung: #:id (:provider_id), :product, :amount, Status :status.',
    'mail_merchant_within' => 'Der Widerruf ging innerhalb der konfigurierten Frist von :days Tagen ab Zahlung ein.',
    'mail_merchant_outside' => 'Hinweis: Der Widerruf ging nach Ablauf der konfigurierten Frist von :days Tagen ab Zahlung ein. Ob die Frist tatsächlich abgelaufen ist, ist zu prüfen.',
    'mail_merchant_expired_hint' => 'Hinweis: An der Zahlung ist eine Zustimmung nach § 356 Abs. 5 BGB festgehalten (sofortige Lieferung digitaler Inhalte). Ob das Widerrufsrecht damit erloschen ist, ist zu prüfen.',
    'mail_merchant_unmatched' => 'Keine eindeutige Zahlung zugeordnet. Der Widerruf ist trotzdem zugegangen und muss von Hand bearbeitet werden.',
    'mail_merchant_cp' => 'Im Control Panel öffnen',
];
