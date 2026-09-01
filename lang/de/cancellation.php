<?php

/*
|--------------------------------------------------------------------------
| Kündigung ohne Login — § 312k BGB
|--------------------------------------------------------------------------
|
| Der Weg, den ein Verbraucher geht, ohne sich anzumelden. Zwei Wörter darin
| schreibt das Gesetz vor: die Kündigungsschaltfläche „Verträge hier kündigen"
| (Abs. 2 S. 2) und die Bestätigungsschaltfläche „jetzt kündigen" (Abs. 2
| S. 3). Sie stehen hier unverändert.
|
| Das Portal (`portal.php`) hat dieselben zwei Schaltflächen für den Weg über
| den Magic-Link; die Texte hier sind die für den Weg ohne Login.
|
|     php artisan vendor:publish --tag=statamic-payments-translations
|
| Rechtliche Entscheidungen 01.09.2026, von Adrian zu prüfen. Dies ist keine
| Rechtsberatung.
|
*/

return [

    /*
    | § 312k Abs. 2 S. 2 BGB: der Wortlaut der Kündigungsschaltfläche ist
    | gesetzlich vorgegeben. Für den Footer des Hosts.
    */
    'button' => 'Verträge hier kündigen',

    // Schritt 1
    'title' => 'Verträge hier kündigen',
    'intro' => 'Hier können Sie einen über diese Website geschlossenen Vertrag kündigen, ohne sich anzumelden. Im nächsten Schritt sehen Sie Ihre Angaben noch einmal und bestätigen die Kündigung.',
    'errors_intro' => 'Bitte prüfen Sie die markierten Felder.',
    'field_name' => 'Name',
    'field_email' => 'E-Mail-Adresse',
    'field_email_help' => 'Die Adresse, mit der Sie den Vertrag abgeschlossen haben. An sie geht die Bestätigung.',
    'field_identification' => 'Vertrags- oder Kundennummer',
    'field_identification_help' => 'Aus der Bestätigung oder der letzten Rechnung. Wenn Sie sie nicht finden, beschreiben Sie den Vertrag unter Grund.',
    'field_kind' => 'Art der Kündigung',
    'kind_ordinary' => 'Ordentliche Kündigung',
    'kind_ordinary_help' => 'Zum nächstmöglichen oder zu einem von Ihnen genannten Zeitpunkt.',
    'kind_extraordinary' => 'Außerordentliche Kündigung',
    'kind_extraordinary_help' => 'Aus wichtigem Grund. Bitte nennen Sie den Grund.',
    'field_reason' => 'Kündigungsgrund',
    'field_reason_help' => 'Bei der außerordentlichen Kündigung erforderlich, sonst freiwillig.',
    'field_effective' => 'Zeitpunkt, zu dem die Kündigung wirken soll',
    'field_effective_help' => 'Leer gelassen: zum frühestmöglichen Zeitpunkt.',
    'effective_earliest' => 'frühestmöglich',
    'continue' => 'Weiter zur Bestätigung',
    'continue_hint' => 'Mit diesem Schritt ist noch nichts gekündigt.',
    'policy_link' => 'Hinweise zu Laufzeiten und Fristen',
    'foot' => 'Ihre Angaben werden zur Bearbeitung der Kündigung gespeichert.',

    // Schritt 2 — § 312k Abs. 2 S. 3: die Bestätigungsseite
    'confirm_title' => 'Kündigung bestätigen',
    'confirm_intro' => 'Bitte prüfen Sie Ihre Angaben. Mit der Schaltfläche unten erklären Sie die Kündigung.',
    'confirm_effect' => 'Nach der Bestätigung erhalten Sie sofort eine Bestätigung mit Datum und Uhrzeit des Eingangs an Ihre E-Mail-Adresse.',

    /*
    | § 312k Abs. 2 S. 3 BGB: der Wortlaut der Bestätigungsschaltfläche ist
    | gesetzlich vorgegeben („jetzt kündigen").
    */
    'confirm_button' => 'jetzt kündigen',
    'confirm_back' => 'Angaben ändern',

    // Schritt 3 — § 312k Abs. 2 S. 4: die Bestätigung
    'done_title' => 'Ihre Kündigung ist eingegangen',
    'done_received' => 'Die Kündigung ist bei uns eingegangen am :date um :time Uhr (:zone).',
    'done_effective' => 'Gewünschter Zeitpunkt: :date.',
    'done_effective_earliest' => 'Gewünschter Zeitpunkt: frühestmöglich.',
    'done_id_label' => 'Ihre Kündigungs-Kennung',
    'done_mailed' => 'Eine Bestätigung mit dieser Kennung ist an Ihre E-Mail-Adresse unterwegs.',
    'done_not_mailed' => 'Die Kündigung ist eingegangen. Die Bestätigung per E-Mail konnte gerade nicht zugestellt werden — bitte notieren Sie die Kennung und den Zeitpunkt oder bewahren Sie diese Seite auf.',
    'done_keep' => 'Bitte bewahren Sie die Kennung als Nachweis auf. Wann der Vertrag endet, teilen wir Ihnen mit.',

    // Mail an den Verbraucher (§ 312k Abs. 2 S. 4)
    'mail_receipt_subject' => 'Eingang Ihrer Kündigung :id',
    'mail_greeting' => 'Guten Tag,',
    'mail_receipt_body' => 'hiermit bestätigen wir den Eingang Ihrer Kündigungserklärung am :date um :time Uhr (:zone).',
    'mail_receipt_id' => 'Kündigungs-Kennung',
    'mail_receipt_next' => 'Wann der Vertrag endet, teilen wir Ihnen gesondert mit. Diese Nachricht bestätigt den Eingang Ihrer Erklärung mit ihrem Inhalt.',
    'mail_keep' => 'Bitte bewahren Sie diese Nachricht als Nachweis auf.',

    // Mail an den Händler
    'mail_merchant_subject' => 'Kündigung eingegangen: :id',
    'mail_merchant_body' => 'Eine Kündigung mit der Kennung :id ist am :date um :time Uhr (:zone) eingegangen.',
    'mail_merchant_cancelled' => 'Das Abo #:id (:provider_id, :product) wurde eindeutig zugeordnet und beim Zahlungsdienstleister gekündigt. Es folgt keine weitere Abbuchung.',
    'mail_merchant_matched_by_number' => 'Das Abo #:id (:provider_id, :product, Status :status) wurde über die Kundennummer zugeordnet, nicht über die Anbieter-Kennung — deshalb nicht automatisch gekündigt. Bitte prüfen und im Control Panel kündigen.',
    'restart' => 'Diese Seite ist nicht mehr erreichbar. Bitte beginnen Sie erneut.',
    'mail_merchant_matched_not_cancelled' => 'Das Abo #:id (:provider_id, :product, Status :status) wurde zugeordnet, aber nicht beim Zahlungsdienstleister gekündigt — es läuft nicht mehr oder der Anbieter hat nicht bestätigt. Bitte prüfen.',
    'mail_merchant_unmatched' => 'Kein eindeutiges Abo zugeordnet. Die Kündigung ist trotzdem zugegangen und muss von Hand bearbeitet werden.',
    'mail_merchant_cp' => 'Im Control Panel öffnen',
];
