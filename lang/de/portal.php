<?php

/*
|--------------------------------------------------------------------------
| Kundenselbstbedienung
|--------------------------------------------------------------------------
|
| Jedes Wort, das ein Käufer auf diesen Seiten liest, steht hier und nirgends
| sonst im Code.
|
| Das ist bei den Kündigungstexten keine Bequemlichkeit, sondern die Bedingung,
| unter der sie überhaupt ausgeliefert werden dürfen. § 312k BGB schreibt eine
| Schaltfläche mit dem Wortlaut „Verträge hier kündigen“ und eine
| Bestätigungsschaltfläche „Jetzt kündigen“ vor. Der Wortlaut gehört vor einen
| Anwalt, die Vorschrift ist bereits einmal geändert worden, und eine Kanzlei,
| die eine andere Formulierung will, muss sie ändern können, ohne auf ein
| Release dieses Addons zu warten:
|
|     php artisan vendor:publish --tag=statamic-payments-translations
|
| Was der Code besitzt, ist die Reihenfolge — Schaltfläche, Bestätigungsseite,
| Bestätigung in Textform. Was diese Datei besitzt, ist der Text.
|
| Dies ist keine Rechtsberatung.
|
*/

return [

    // Der Weg hinein
    'request_title' => 'Ihre Bestellungen',
    'request_intro' => 'Geben Sie die E-Mail-Adresse ein, mit der Sie bestellt haben. Sie erhalten einen Link zu Ihren Bestellungen.',
    'request_label' => 'E-Mail-Adresse',
    'request_placeholder' => 'name@beispiel.de',
    'request_button' => 'Link anfordern',
    'request_foot' => 'Der Link ist :minutes Minuten gültig. Er führt zu Ihren Bestellungen, ohne Passwort.',

    // Derselbe Weg, von der Kündigungsschaltfläche aus betreten.
    'cancel_entry_title' => 'Vertrag kündigen',
    'cancel_entry_intro' => 'Geben Sie die E-Mail-Adresse ein, mit der Sie den Vertrag abgeschlossen haben. Sie erhalten einen Link zu Ihren Verträgen und können dort kündigen.',
    'cancel_entry_button' => 'Link zum Kündigen anfordern',

    // Eine Antwort für jeden Ausgang. Die Seite verrät nicht, ob es zu dieser
    // Adresse eine Bestellung gibt.
    'link_sent' => 'Wenn es zu dieser Adresse Bestellungen gibt, ist der Link unterwegs.',
    'session_over' => 'Der Link ist abgelaufen. Fordern Sie einen neuen an.',
    'signed_out' => 'Sie sind abgemeldet.',
    'sign_out' => 'Abmelden',

    // Übersicht
    'orders_title' => 'Ihre Bestellungen',
    'orders_for' => 'Angemeldet als :email',
    'orders_none' => 'Zu dieser Adresse ist keine Bestellung eingetragen.',
    'orders_heading' => 'Bestellungen',
    'order_view' => 'Ansehen',
    'order_refunded' => 'Erstattet',

    'subscriptions_heading' => 'Laufende Verträge',
    'subscriptions_none' => 'Sie haben keine laufenden Verträge.',

    'subscription_next' => 'Nächste Abbuchung am :date',
    'subscription_remaining' => 'Noch :count Abbuchungen',
    'subscription_ended' => 'Beendet am :date',

    'status_initiated' => 'Wird eingerichtet',
    'status_pending' => 'Beginnt später',
    'status_active' => 'Läuft',
    'status_suspended' => 'Ausgesetzt',
    'status_cancelled' => 'Gekündigt',
    'status_completed' => 'Abgeschlossen',

    // Einzelne Bestellung
    'order_title' => 'Bestellung vom :date',
    'order_back' => 'Zurück zur Übersicht',
    'order_lines' => 'Positionen',
    'order_total' => 'Gesamt',
    'order_invoice' => 'Rechnung',
    'order_invoice_download' => 'Rechnung :number herunterladen',
    'order_invoice_none' => 'Zu dieser Bestellung liegt keine Rechnung vor.',

    /*
    | § 312k BGB. Der Wortlaut der beiden Schaltflächen ist der vom Gesetz
    | vorgeschriebene. Er wird hier nicht ausgeschmückt.
    */
    'cancel_button' => 'Verträge hier kündigen',
    'cancel_now' => 'Jetzt kündigen',

    'cancel_title' => 'Vertrag kündigen',
    'cancel_intro' => 'Bitte prüfen Sie, welchen Vertrag Sie kündigen möchten.',
    'cancel_contract' => 'Vertrag',
    'cancel_price' => 'Preis',
    'cancel_started' => 'Beginn',
    'cancel_next' => 'Nächste Abbuchung',
    'cancel_effect' => 'Die Kündigung wirkt sofort. Es folgt keine weitere Abbuchung.',
    'cancel_abort' => 'Doch nicht kündigen',
    'cancel_not_live' => 'Dieser Vertrag läuft nicht mehr. Es gibt nichts zu kündigen.',

    'cancel_failed' => 'Die Kündigung konnte nicht ausgeführt werden: Der Zahlungsdienstleister hat sie nicht bestätigt. Es wurde nichts geändert, Ihr Vertrag läuft unverändert weiter. Bitte versuchen Sie es in einigen Minuten erneut.',

    'cancelled_title' => 'Gekündigt',
    'cancelled_confirmation' => 'Ihr Vertrag „:name“ wurde am :date um :time Uhr gekündigt.',
    'cancelled_mailed' => 'Eine Bestätigung ist an :email unterwegs.',
    'cancelled_not_mailed' => 'Die Kündigung ist wirksam. Die Bestätigungs-E-Mail an :email konnte gerade nicht zugestellt werden — bitte bewahren Sie diese Seite auf oder wenden Sie sich an uns.',
    'cancelled_back' => 'Zurück zur Übersicht',

    // Zahlungsmittel
    'method_button' => 'Zahlungsmittel ändern',
    'method_note' => 'Für die Umstellung bucht der Zahlungsdienstleister einmalig :amount ab. Anders lässt sich kein neues Zahlungsmittel hinterlegen.',
    'method_charge_description' => 'Neues Zahlungsmittel hinterlegen',
    'method_returned' => 'Das neue Zahlungsmittel wird geprüft. Die nächste Abbuchung erfolgt über das zuletzt bestätigte Zahlungsmittel.',
    'method_unavailable' => 'Für diesen Vertrag lässt sich das Zahlungsmittel nicht ändern.',
    'method_failed' => 'Der Zahlungsdienstleister hat die Umstellung gerade nicht angenommen. Bitte versuchen Sie es später erneut.',

    // Wie ein Betrag und ein Rhythmus in dieser Sprache aussehen. Auch das
    // Dezimaltrennzeichen gehoert hierher: welches Zeichen eine Nachkommastelle
    // abtrennt, ist eine Eigenschaft der Sprache und nicht des Geldes.
    'decimal_point' => ',',
    'thousands_separator' => '.',

    // Aus der Anbieter-Vokabel („1 month", „12 weeks") ein Wort machen.
    // `trans_choice`, damit der Einzahlfall ein Wort ist und nicht eine Zahl mit
    // einer Einheit dahinter: „alle 1 Monat" sagt niemand.
    'interval_day' => 'täglich|alle :count Tage',
    'interval_week' => 'wöchentlich|alle :count Wochen',
    'interval_month' => 'monatlich|alle :count Monate',
    'interval_year' => 'jährlich|alle :count Jahre',

    // Datum und Uhrzeit, wie § 312k sie in der Bestätigung verlangt.
    'date_format' => 'd.m.Y',
    'time_format' => 'H:i',

    // E-Mails
    'mail_link_subject' => 'Ihr Link zu Ihren Bestellungen',
    'mail_link_greeting' => 'Guten Tag,',
    'mail_link_body' => 'über diesen Link kommen Sie zu Ihren Bestellungen. Er ist :minutes Minuten gültig.',
    'mail_link_button' => 'Zu meinen Bestellungen',
    'mail_link_ignore' => 'Wenn Sie das nicht angefordert haben, können Sie diese Nachricht ignorieren.',

    'mail_cancelled_subject' => 'Bestätigung Ihrer Kündigung',
    'mail_cancelled_greeting' => 'Guten Tag,',
    'mail_cancelled_body' => 'hiermit bestätigen wir die Kündigung Ihres Vertrags „:product“. Die Kündigung ist am :date um :time Uhr bei uns eingegangen und sofort wirksam.',
    'mail_cancelled_no_further' => 'Es erfolgt keine weitere Abbuchung.',
    'mail_cancelled_keep' => 'Bitte bewahren Sie diese Nachricht als Nachweis auf.',
];
