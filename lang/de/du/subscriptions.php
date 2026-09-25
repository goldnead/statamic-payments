<?php

/*
|--------------------------------------------------------------------------
| Verträge im Kundenkonto, geduzt
|--------------------------------------------------------------------------
|
| Gilt, wenn `statamic-payments.anrede` auf `du` steht. Nur die Zeilen, die
| sich von `../subscriptions.php` unterscheiden.
|
*/

return [
    'release_keep_instructions' => 'Dieser Wechsel ist nicht fertig geworden. Sieh im Konto beim Zahlungsanbieter nach, welchen Betrag er abbucht, und wähle danach.',
    'portal_pause_effect' => 'Der bezahlte Zeitraum bleibt dir. Beim Fortsetzen wird nicht sofort abgebucht, sondern am nächsten regulären Abbuchungstag.',
    'portal_pause_date_help' => 'Optional. Ohne Datum bleibt der Vertrag pausiert, bis du ihn hier fortsetzt.',
    'portal_pause_date_invalid' => 'Bitte wähle ein Datum ab morgen.',
    'portal_pause_failed' => 'Das Pausieren hat nicht geklappt. Der Vertrag läuft unverändert weiter. Bitte versuch es noch einmal.',
    'portal_resume_failed' => 'Das Fortsetzen hat nicht geklappt. Der Vertrag bleibt pausiert. Bitte versuch es noch einmal.',
    'portal_switch_intro' => 'Du zahlst für :name derzeit :amount.',
    'portal_cancel_hint' => 'Kündigen kannst du diesen Vertrag hier:',
    'portal_cancel_busy' => 'Dieser Vertrag wird gerade geändert. Bitte versuch es in einem Moment noch einmal.',
];
