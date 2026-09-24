<?php

/*
| Die Auslöser im Webhook-Manager. Beschriftung „Zahlungen: …", damit sie in der
| Auswahlliste neben den Auslösern der anderen Addons als Gruppe lesbar sind.
| Die Wörter sind dieselben wie in den Automationen.
*/

return [
    'triggers' => [
        'paid' => 'Zahlungen: Zahlung eingegangen',
        'failed' => 'Zahlungen: Zahlung fehlgeschlagen',
        'refunded' => 'Zahlungen: Zahlung erstattet',
        'charged_back' => 'Zahlungen: Zahlung zurückgebucht',
        'checkout_abandoned' => 'Zahlungen: Kauf abgebrochen',
        'checkout_blocked' => 'Zahlungen: Kauf gesperrt',
        'subscription_started' => 'Zahlungen: Abo gestartet',
        'subscription_start_failed' => 'Zahlungen: Abo-Start fehlgeschlagen',
        'subscription_renewed' => 'Zahlungen: Abo verlängert',
        'subscription_attempt_failed' => 'Zahlungen: Abo-Abbuchung fehlgeschlagen',
        'subscription_payment_upcoming' => 'Zahlungen: Abo-Abbuchung steht bevor',
        'subscription_card_expiring' => 'Zahlungen: Abo-Karte läuft bald ab',
        'subscription_card_expired' => 'Zahlungen: Abo-Karte abgelaufen',
        'subscription_paused' => 'Zahlungen: Abo pausiert',
        'subscription_resumed' => 'Zahlungen: Abo fortgesetzt',
        'subscription_changed' => 'Zahlungen: Abo gewechselt',
        'subscription_replaced' => 'Zahlungen: Abo ersetzt',
        'subscription_plan_completed' => 'Zahlungen: Ratenzahlung abgeschlossen',
        'subscription_cancelled' => 'Zahlungen: Abo gekündigt',
        'subscription_ended' => 'Zahlungen: Abo beendet',
    ],

    'descriptions' => [
        'paid' => 'Einmal, sobald der Anbieter eine Zahlung als bezahlt bestätigt.',
        'failed' => 'Wenn eine Zahlung als fehlgeschlagen, abgelaufen oder abgebrochen gemeldet wird.',
        'refunded' => 'Wenn eine Erstattung zu einer Zahlung verbucht wird, ganz oder teilweise.',
        'charged_back' => 'Wenn die Bank eine Zahlung zurückholt.',
        'checkout_abandoned' => 'Wenn ein Kauf begonnen und über die Wartezeit hinaus nicht bezahlt wurde.',
        'checkout_blocked' => 'Wenn die Sperrliste, die Mengenbremse oder das Captcha einen Kauf abweist. Enthält nur das Netz der IP-Adresse, nicht die Adresse.',
        'subscription_started' => 'Wenn der Anbieter ein Abo bestätigt und der erste Zeitraum bezahlt ist.',
        'subscription_start_failed' => 'Wenn eine Abo-Zahlung eingegangen ist, aber kein Abo dahinter angelegt wurde.',
        'subscription_renewed' => 'Einmal je Abo-Zeitraum, der abgebucht und bezahlt ist.',
        'subscription_attempt_failed' => 'Einmal je fehlgeschlagener Abbuchung eines laufenden Abos, mit der Zahl der Fehlschläge in Folge.',
        'subscription_payment_upcoming' => 'Eine eingestellte Zahl von Tagen, bevor ein Abo wieder abgebucht wird.',
        'subscription_card_expiring' => 'Wenn die Karte hinter einem Abo bald abläuft.',
        'subscription_card_expired' => 'Wenn die Karte hinter einem Abo abgelaufen ist.',
        'subscription_paused' => 'Wenn ein Abo pausiert wird, im Control Panel oder im Kundenbereich.',
        'subscription_resumed' => 'Wenn ein pausiertes Abo wieder läuft, von Hand oder am gesetzten Datum.',
        'subscription_changed' => 'Wenn ein Abo auf ein anderes Produkt wechselt, nach oben oder nach unten.',
        'subscription_replaced' => 'Wenn ein Kauf ein früheres Abo derselben Person beendet.',
        'subscription_plan_completed' => 'Einmal, wenn die letzte Rate bezahlt ist. Im selben Moment feuert auch „Abo beendet".',
        'subscription_cancelled' => 'Wenn der Anbieter bestätigt, dass ein Abo gekündigt wurde.',
        'subscription_ended' => 'Wenn ein Abo sein eigenes Ende erreicht.',
    ],
];
