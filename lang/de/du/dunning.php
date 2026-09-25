<?php

/*
|--------------------------------------------------------------------------
| Mahnung, geduzt
|--------------------------------------------------------------------------
|
| Gilt, wenn `statamic-payments.anrede` auf `du` steht. Nur die Zeilen, die
| sich von `../dunning.php` unterscheiden.
|
*/

return [
    'mail_subject' => 'Deine Zahlung konnte nicht eingezogen werden',
    'mail_greeting' => 'Hallo,',
    'mail_greeting_name' => 'Hallo :name,',
    'mail_again' => 'Wir versuchen es noch einmal. Wenn du dein Zahlungsmittel aktualisierst, geht es ohne Unterbrechung weiter.',
    'mail_expires' => 'Der Link gilt nur kurz. Ist er abgelaufen, kannst du dir auf derselben Seite einen neuen schicken lassen.',
];
