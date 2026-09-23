<?php

/*
| Anrede wie in den übrigen Mails der Familie: „Guten Tag" und „Sie".
*/

return [
    'mail_subject' => 'Ihre Zahlung konnte nicht eingezogen werden',
    'mail_greeting' => 'Guten Tag,',
    'mail_greeting_name' => 'Guten Tag :name,',
    'mail_body' => 'für :plan konnten wir :amount nicht einziehen. Meistens liegt es an einer abgelaufenen Karte.',
    'mail_again' => 'Wir versuchen es noch einmal. Wenn Sie Ihr Zahlungsmittel aktualisieren, geht es ohne Unterbrechung weiter.',
    'mail_final' => 'Das ist die letzte Erinnerung. Ohne ein gültiges Zahlungsmittel endet der Zugang in den nächsten Tagen.',
    'mail_button' => 'Zahlungsmittel aktualisieren',
    'mail_expires' => 'Der Link gilt nur kurz. Ist er abgelaufen, können Sie sich auf derselben Seite einen neuen schicken lassen.',
    'log_suppressed' => 'Mahnung nicht verschickt: :email steht auf der Sperrliste.',
];
