<?php

/*
|--------------------------------------------------------------------------
| Erinnerungen, geduzt
|--------------------------------------------------------------------------
|
| Gilt, wenn `statamic-payments.anrede` auf `du` steht. Nur die Zeilen, die
| sich von `../reminders.php` unterscheiden.
|
*/

return [
    'greeting' => 'Hallo,',
    'greeting_name' => 'Hallo :name,',
    'upcoming_next' => 'Du musst nichts tun. Stimmt dein Zahlungsmittel nicht mehr, kannst du es vorher tauschen.',
    'card_expiring_subject' => 'Deine Karte für :plan läuft bald ab',
    'card_expiring_body' => 'die Karte, mit der du :plan bezahlst, ist nur noch bis :date gültig.',
    'card_expiring_next' => 'Damit die nächste Abbuchung klappt, hinterleg bitte vorher eine neue Karte.',
    'card_expired_subject' => 'Deine Karte für :plan ist abgelaufen',
    'card_expired_body' => 'die Karte, mit der du :plan bezahlst, war nur bis :date gültig.',
    'link_expires' => 'Ist der Link abgelaufen, kannst du dir auf derselben Seite einen neuen schicken lassen.',
];
