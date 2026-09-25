<?php

/*
|--------------------------------------------------------------------------
| Widerruf, geduzt
|--------------------------------------------------------------------------
|
| Gilt, wenn `statamic-payments.anrede` auf `du` steht. Nur die Zeilen, die
| sich von `../withdrawal.php` unterscheiden. Die zwei Schaltflächen, deren
| Wortlaut § 356a BGB vorschreibt, stehen nur dort und sind anredefrei.
|
*/

return [
    'intro' => 'Hier kannst du einen über diese Website geschlossenen Vertrag widerrufen. Gib an, wer du bist und welche Bestellung du meinst. Im nächsten Schritt siehst du deine Angaben noch einmal und bestätigst den Widerruf.',
    'errors_intro' => 'Bitte prüfe die markierten Felder.',
    'field_email_help' => 'Die Adresse, mit der du bestellt hast. An sie geht die Eingangsbestätigung.',
    'field_reference_help' => 'Aus der Bestellbestätigung oder der Rechnung. Wenn du sie nicht findest, beschreib die Bestellung in der Nachricht.',
    'field_contact' => 'Wie sollen wir dich erreichen?',
    'field_contact_help' => 'Leer gelassen, nehmen wir deine E-Mail-Adresse.',
    'foot' => 'Deine Angaben werden zur Bearbeitung des Widerrufs gespeichert.',
    'confirm_intro' => 'Bitte prüfe deine Angaben. Mit der Schaltfläche unten erklärst du den Widerruf.',
    'confirm_effect' => 'Nach der Bestätigung bekommst du sofort eine Eingangsbestätigung mit Kennung und Zeitpunkt an deine E-Mail-Adresse.',
    'restart' => 'Diese Seite ist nicht mehr erreichbar. Bitte fang noch einmal an.',
    'done_title' => 'Dein Widerruf ist eingegangen',
    'done_id_label' => 'Deine Widerrufs-Kennung',
    'done_mailed' => 'Eine Eingangsbestätigung mit dieser Kennung ist an deine E-Mail-Adresse unterwegs.',
    'done_not_mailed' => 'Der Widerruf ist eingegangen. Die Eingangsbestätigung per E-Mail konnte gerade nicht zugestellt werden. Bitte notiere dir die Kennung und den Zeitpunkt oder bewahre diese Seite auf.',
    'done_keep' => 'Bitte bewahre die Kennung als Nachweis auf. Wir melden uns bei dir über das angegebene Kontaktmittel.',
    'mail_receipt_subject' => 'Eingang deines Widerrufs :id',
    'mail_greeting' => 'Hallo,',
    'mail_receipt_reference' => 'Deine Bestellkennung',
    'mail_receipt_next' => 'Wir prüfen den Vorgang und melden uns bei dir. Diese Nachricht bestätigt den Eingang deiner Erklärung.',
    'mail_keep' => 'Bitte bewahre diese Nachricht als Nachweis auf.',
];
