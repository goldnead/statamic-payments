<?php

/*
|--------------------------------------------------------------------------
| Kundenkonto, geduzt
|--------------------------------------------------------------------------
|
| Gilt, wenn `statamic-payments.anrede` auf `du` steht. Nur die Zeilen, die
| sich von `../portal.php` unterscheiden. Die Schaltflächen, deren Wortlaut
| § 312k BGB vorschreibt, stehen nur dort und sind anredefrei.
|
*/

return [
    'request_title' => 'Deine Bestellungen',
    'request_intro' => 'Gib die E-Mail-Adresse ein, mit der du bestellt hast. Du bekommst einen Link zu deinen Bestellungen.',
    'request_foot' => 'Der Link ist :minutes Minuten gültig. Er führt zu deinen Bestellungen, ohne Passwort.',
    'cancel_entry_intro' => 'Gib die E-Mail-Adresse ein, mit der du den Vertrag abgeschlossen hast. Du bekommst einen Link zu deinen Verträgen und kannst dort kündigen.',
    'session_over' => 'Der Link ist abgelaufen. Fordere einen neuen an.',
    'signed_out' => 'Du bist abgemeldet.',
    'orders_title' => 'Deine Bestellungen',
    'subscriptions_none' => 'Du hast keine laufenden Verträge.',
    'cancel_intro' => 'Bitte prüfe, welchen Vertrag du kündigen möchtest.',
    'cancel_failed' => 'Die Kündigung konnte nicht ausgeführt werden: Der Zahlungsdienstleister hat sie nicht bestätigt. Es wurde nichts geändert, dein Vertrag läuft unverändert weiter. Bitte versuch es in einigen Minuten noch einmal.',
    'cancelled_confirmation' => 'Dein Vertrag „:name“ wurde am :date um :time Uhr gekündigt.',
    'cancelled_not_mailed' => 'Die Kündigung ist wirksam. Die Bestätigungs-E-Mail an :email konnte gerade nicht zugestellt werden. Bitte bewahre diese Seite auf oder melde dich bei uns.',
    'method_failed' => 'Der Zahlungsdienstleister hat die Umstellung gerade nicht angenommen. Bitte versuch es später noch einmal.',
    'mail_link_subject' => 'Dein Link zu deinen Bestellungen',
    'mail_link_greeting' => 'Hallo,',
    'mail_link_body' => 'über diesen Link kommst du zu deinen Bestellungen. Er ist :minutes Minuten gültig.',
    'mail_link_ignore' => 'Wenn du das nicht angefordert hast, kannst du diese Nachricht ignorieren.',
    'mail_cancelled_subject' => 'Bestätigung deiner Kündigung',
    'mail_cancelled_greeting' => 'Hallo,',
    'mail_cancelled_body' => 'hiermit bestätigen wir die Kündigung deines Vertrags „:product“. Die Kündigung ist am :date um :time Uhr bei uns eingegangen.',
    'mail_cancelled_keep' => 'Bitte bewahre diese Nachricht als Nachweis auf.',
];
