<?php

/*
|--------------------------------------------------------------------------
| Kündigung ohne Login, geduzt
|--------------------------------------------------------------------------
|
| Gilt, wenn `statamic-payments.anrede` auf `du` steht. Nur die Zeilen, die
| sich von `../cancellation.php` unterscheiden. Die zwei Schaltflächen, deren
| Wortlaut § 312k BGB vorschreibt, stehen nur dort und sind anredefrei.
|
*/

return [
    'intro' => 'Hier kannst du einen über diese Website geschlossenen Vertrag kündigen, ohne dich anzumelden. Im nächsten Schritt siehst du deine Angaben noch einmal und bestätigst die Kündigung.',
    'errors_intro' => 'Bitte prüfe die markierten Felder.',
    'field_email_help' => 'Die Adresse, mit der du den Vertrag abgeschlossen hast. An sie geht die Bestätigung.',
    'field_identification_help' => 'Aus der Bestätigung oder der letzten Rechnung. Wenn du sie nicht findest, beschreib den Vertrag unter Grund.',
    'kind_ordinary_help' => 'Zum nächstmöglichen oder zu einem von dir genannten Zeitpunkt.',
    'kind_extraordinary_help' => 'Aus wichtigem Grund. Bitte nenn den Grund.',
    'foot' => 'Deine Angaben werden zur Bearbeitung der Kündigung gespeichert.',
    'confirm_intro' => 'Bitte prüfe deine Angaben. Mit der Schaltfläche unten erklärst du die Kündigung.',
    'confirm_effect' => 'Nach der Bestätigung bekommst du sofort eine Bestätigung mit Datum und Uhrzeit des Eingangs an deine E-Mail-Adresse.',
    'done_title' => 'Deine Kündigung ist eingegangen',
    'done_id_label' => 'Deine Kündigungs-Kennung',
    'done_mailed' => 'Eine Bestätigung mit dieser Kennung ist an deine E-Mail-Adresse unterwegs.',
    'done_not_mailed' => 'Die Kündigung ist eingegangen. Die Bestätigung per E-Mail konnte gerade nicht zugestellt werden. Bitte notiere dir die Kennung und den Zeitpunkt oder bewahre diese Seite auf.',
    'done_keep' => 'Bitte bewahre die Kennung als Nachweis auf. Wann der Vertrag endet, teilen wir dir mit.',
    'mail_receipt_subject' => 'Eingang deiner Kündigung :id',
    'mail_greeting' => 'Hallo,',
    'mail_receipt_body' => 'hiermit bestätigen wir den Eingang deiner Kündigungserklärung am :date um :time Uhr (:zone).',
    'mail_receipt_next' => 'Wann der Vertrag endet, teilen wir dir gesondert mit. Diese Nachricht bestätigt den Eingang deiner Erklärung mit ihrem Inhalt.',
    'mail_keep' => 'Bitte bewahre diese Nachricht als Nachweis auf.',
    'restart' => 'Diese Seite ist nicht mehr erreichbar. Bitte fang noch einmal an.',
];
