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

    // Die Bestellseite hinter dem Link (§ 312j Abs. 3 BGB).
    'resume_title' => 'Kauf fortsetzen',
    'resume_intro' => 'Das ist Ihre Auswahl. Mit der Schaltfläche unten bestellen Sie verbindlich und werden zur Zahlung weitergeleitet.',
    'resume_discount' => 'Rabatt',
    'resume_total' => 'Gesamtbetrag',
    'resume_price_hint' => 'Alle Preise inklusive Umsatzsteuer.',
    'resume_withdrawal_heading' => 'Widerrufsrecht',
    'resume_withdrawal_text' => 'Als Verbraucher haben Sie ein vierzehntägiges Widerrufsrecht.',
    'resume_policy_link' => 'Widerrufsbelehrung lesen',
    'resume_button' => 'Zahlungspflichtig bestellen',
    'resume_button_hint' => 'Sie werden anschließend zur Zahlung weitergeleitet. Abgebucht wird erst dort.',
    'resume_foot' => 'Diese Seite gehört zu einer Erinnerung an :email.',
];
