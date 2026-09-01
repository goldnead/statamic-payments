<?php

/*
 * Die Erinnerung an einen offenen Kauf. Der Wortlaut geht vor einen Menschen,
 * nicht vor einen Compiler: veröffentlichbar unter
 * lang/vendor/statamic-payments/de/abandoned.php.
 */
return [
    'mail_subject' => 'Ihr Kauf ist noch nicht abgeschlossen',
    'mail_greeting' => 'Guten Tag,',
    'mail_greeting_name' => 'Guten Tag :name,',
    'mail_body' => 'Sie haben einen Kauf begonnen und nicht abgeschlossen. Falls Sie ihn fortsetzen möchten: Ihre Auswahl liegt noch bereit.',
    'mail_total' => 'Gesamtbetrag: :total :currency',
    'mail_button' => 'Kauf fortsetzen',
    'mail_ignore' => 'Wenn Sie sich anders entschieden haben, können Sie diese Nachricht ignorieren. Es entstehen keine Kosten.',

    'log_suppressed' => 'Keine Erinnerung verschickt: :email steht auf der Sperrliste.',

    'resume_unavailable' => 'Dieser Kauf lässt sich nicht mehr fortsetzen. Er wurde bereits abgeschlossen, oder die Auswahl ist nicht mehr verfügbar.',
];
