<?php

/*
| Pausieren, Fortsetzen und Wechseln von Abos: Control Panel und Kundenkonto.
| Im Kundenkonto gilt „Sie“, wie auf allen Seiten dort.
*/

return [

    // Control Panel: Aktionen
    'pause' => 'Pausieren',
    'pause_confirm' => 'Dieses Abo pausieren? Bis zum Fortsetzen wird nichts abgebucht.|Diese :count Abos pausieren? Bis zum Fortsetzen wird nichts abgebucht.',
    'pause_resume_on' => 'Fortsetzen am',
    'pause_resume_on_instructions' => 'Leer lassen, um ohne Datum zu pausieren. An diesem Tag läuft das Abo von selbst weiter.',
    'paused_bulk' => 'Das Abo ist pausiert.|:count Abos sind pausiert.',
    'pause_failed' => ':failed von :total Abos wurden nicht pausiert. Diese sind unverändert.',

    'resume' => 'Fortsetzen',
    'resume_confirm' => 'Dieses Abo fortsetzen? Abgebucht wird erst am nächsten regulären Abbuchungstag.|Diese :count Abos fortsetzen? Abgebucht wird erst am nächsten regulären Abbuchungstag.',
    'resumed_bulk' => 'Das Abo läuft wieder.|:count Abos laufen wieder.',
    'resume_failed' => ':failed von :total Abos wurden nicht fortgesetzt. Diese bleiben pausiert.',

    'switch' => 'Wechseln',
    'switch_to' => 'Neues Produkt',
    'switch_to_instructions' => 'Teurer: gilt sofort, der Unterschied für den laufenden Zeitraum wird anteilig abgebucht. Günstiger: gilt ab der nächsten Abbuchung.',
    'switch_confirm' => 'Dieses Abo wechseln?',
    'switched_bulk' => 'Das Abo wurde gewechselt.|:count Abos wurden gewechselt.',
    'switch_failed' => 'Das Abo wurde nicht gewechselt. Es ist unverändert.',
    'switch_line' => 'Wechsel zu :name, anteilig bis zur nächsten Abbuchung',

    // Laufender Gutschein (statamic-offers O6)
    'coupon_until' => 'Mit Gutschein :code, :off weniger, bis einschließlich :date.',
    'coupon_forever' => 'Mit Gutschein :code, :off weniger, dauerhaft.',
    'coupon_until_short' => ':code, :off weniger, bis einschließlich :date',
    'coupon_forever_short' => ':code, :off weniger, dauerhaft',

    // Control Panel: Detailansicht
    'field_paused_at' => 'Pausiert seit',
    'field_coupon' => 'Gutschein',
    'field_resumes_at' => 'Läuft weiter am',
    'field_card_expires_at' => 'Karte gültig bis',
    'detail_history' => 'Verlauf',
    'history_switch' => 'Gewechselt von :from zu :to, sofort, anteilig :amount abgebucht',
    'history_switch_later' => 'Gewechselt von :from zu :to, ab der nächsten Abbuchung',
    'history_proration_failed' => 'Die Differenz ist nicht eingegangen.',
    'history_pause' => 'Pausiert vom :from bis :to',

    // Kundenkonto
    'portal_pause_button' => 'Pausieren',
    'portal_pause_title' => 'Vertrag pausieren',
    'portal_pause_intro' => 'Während der Pause wird für :name nichts abgebucht.',
    'portal_paid_until' => 'Bezahlt bis',
    'portal_pause_effect' => 'Der bezahlte Zeitraum bleibt Ihnen. Beim Fortsetzen wird nicht sofort abgebucht, sondern am nächsten regulären Abbuchungstag.',
    'portal_pause_date_help' => 'Optional. Ohne Datum bleibt der Vertrag pausiert, bis Sie ihn hier fortsetzen.',
    'portal_pause_now' => 'Jetzt pausieren',
    'portal_pause_unavailable' => 'Dieser Vertrag lässt sich hier nicht pausieren.',
    'portal_pause_date_invalid' => 'Bitte wählen Sie ein Datum ab morgen.',
    'portal_pause_failed' => 'Das Pausieren hat nicht geklappt. Der Vertrag läuft unverändert weiter. Bitte versuchen Sie es noch einmal.',
    'portal_paused' => 'Der Vertrag ist pausiert.',
    'portal_paused_until' => 'Pausiert bis :date',
    'portal_paused_open' => 'Pausiert',

    'portal_resume_button' => 'Fortsetzen',
    'portal_resume_note' => 'Abgebucht wird erst am nächsten regulären Abbuchungstag.',
    'portal_resume_failed' => 'Das Fortsetzen hat nicht geklappt. Der Vertrag bleibt pausiert. Bitte versuchen Sie es noch einmal.',
    'portal_resumed' => 'Der Vertrag läuft wieder. Nächste Abbuchung am :date.',

    'portal_switch_button' => 'Tarif wechseln',
    'portal_switch_title' => 'Tarif wechseln',
    'portal_switch_intro' => 'Sie zahlen für :name derzeit :amount.',
    'portal_switch_now' => 'Wechseln',
    'portal_switch_now_charge' => 'Gilt sofort. Heute werden :amount anteilig für den laufenden Zeitraum abgebucht.',
    'portal_switch_now_free' => 'Gilt sofort. Für den laufenden Zeitraum wird nichts nachberechnet.',
    'portal_switch_later' => 'Gilt ab der nächsten Abbuchung am :date. Bis dahin bleibt alles wie es ist.',
    'portal_switch_unavailable' => 'Für diesen Vertrag ist hier kein Wechsel möglich.',
    'portal_switch_failed' => 'Der Wechsel hat nicht geklappt. Der Vertrag ist unverändert.',
    'portal_switched' => 'Gewechselt zu :name.',

    'portal_back' => 'Zurück zur Übersicht',
    'portal_cancel_hint' => 'Kündigen können Sie diesen Vertrag hier:',
    'portal_cancel_elsewhere' => 'Dieser Vertrag wird über die Kündigungsseite gekündigt, nicht hier im Konto.',
];
