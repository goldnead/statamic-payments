<?php

/*
| Die Erinnerungen vor einer Abbuchung und vor dem Kartenablauf. Anrede wie in
| den übrigen Mails der Familie (Rechnung, Abbruch-Erinnerung, Kundenkonto):
| „Guten Tag" und „Sie".
*/

return [
    'greeting' => 'Guten Tag,',
    'greeting_name' => 'Guten Tag :name,',

    'upcoming_subject' => 'Bald wird :plan abgebucht',
    'upcoming_body' => 'am :date buchen wir :amount für :plan ab.',
    'upcoming_next' => 'Sie müssen nichts tun. Stimmt Ihr Zahlungsmittel nicht mehr, können Sie es vorher tauschen.',
    'upcoming_button' => 'Zahlungsmittel ansehen',

    'card_expiring_subject' => 'Ihre Karte für :plan läuft bald ab',
    'card_expiring_body' => 'die Karte, mit der Sie :plan bezahlen, ist nur noch bis :date gültig.',
    'card_expiring_next' => 'Damit die nächste Abbuchung klappt, hinterlegen Sie bitte vorher eine neue Karte.',
    'card_expiring_button' => 'Neue Karte hinterlegen',

    'card_expired_subject' => 'Ihre Karte für :plan ist abgelaufen',
    'card_expired_body' => 'die Karte, mit der Sie :plan bezahlen, war nur bis :date gültig.',
    'card_expired_next' => 'Die nächste Abbuchung wird nicht klappen, solange keine neue Karte hinterlegt ist.',
    'card_expired_button' => 'Neue Karte hinterlegen',

    'link_expires' => 'Ist der Link abgelaufen, können Sie sich auf derselben Seite einen neuen schicken lassen.',
    'log_suppressed' => 'Erinnerung nicht verschickt: :email steht auf der Sperrliste.',
];
