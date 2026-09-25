<?php

/*
|--------------------------------------------------------------------------
| Abgebrochene Kasse, geduzt
|--------------------------------------------------------------------------
|
| Gilt, wenn `statamic-payments.anrede` auf `du` steht. Nur die Zeilen, die
| sich von `../abandoned.php` unterscheiden; alle anderen kommen von dort.
|
*/

return [
    'mail_subject' => 'Dein Kauf ist noch nicht abgeschlossen',
    'mail_greeting' => 'Hallo,',
    'mail_greeting_name' => 'Hallo :name,',
    'mail_body' => 'du hast einen Kauf begonnen und nicht abgeschlossen. Falls du ihn fortsetzen möchtest: Deine Auswahl liegt noch bereit.',
    'mail_ignore' => 'Wenn du dich anders entschieden hast, kannst du diese Nachricht ignorieren. Es entstehen keine Kosten.',
    'resume_intro' => 'Das ist deine Auswahl. Mit der Schaltfläche unten bestellst du verbindlich und wirst zur Zahlung weitergeleitet.',
    'resume_withdrawal_text' => 'Als Verbraucher hast du ein vierzehntägiges Widerrufsrecht.',
    'resume_button_hint' => 'Du wirst anschließend zur Zahlung weitergeleitet. Abgebucht wird erst dort.',
];
