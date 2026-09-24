<?php

/*
| The triggers in the Webhook Manager. Labelled "Payments: …" so they read as
| one group next to the other addons' triggers in the picker.
*/

return [
    'triggers' => [
        'paid' => 'Payments: payment received',
        'failed' => 'Payments: payment failed',
        'refunded' => 'Payments: payment refunded',
        'charged_back' => 'Payments: payment charged back',
        'checkout_abandoned' => 'Payments: checkout abandoned',
        'checkout_blocked' => 'Payments: checkout blocked',
        'subscription_started' => 'Payments: subscription started',
        'subscription_start_failed' => 'Payments: subscription start failed',
        'subscription_renewed' => 'Payments: subscription renewed',
        'subscription_attempt_failed' => 'Payments: subscription charge failed',
        'subscription_payment_upcoming' => 'Payments: subscription charge upcoming',
        'subscription_card_expiring' => 'Payments: subscription card expiring',
        'subscription_card_expired' => 'Payments: subscription card expired',
        'subscription_paused' => 'Payments: subscription paused',
        'subscription_resumed' => 'Payments: subscription resumed',
        'subscription_changed' => 'Payments: subscription changed',
        'subscription_replaced' => 'Payments: subscription replaced',
        'subscription_plan_completed' => 'Payments: instalment plan completed',
        'subscription_cancelled' => 'Payments: subscription cancelled',
        'subscription_ended' => 'Payments: subscription ended',
    ],

    'descriptions' => [
        'paid' => 'Once, when the provider confirms a payment as paid.',
        'failed' => 'When a payment is reported failed, expired or cancelled.',
        'refunded' => 'When a refund is booked against a payment, in full or in part.',
        'charged_back' => 'When the bank takes a payment back.',
        'checkout_abandoned' => 'When a checkout was started and not paid within the waiting time.',
        'checkout_blocked' => 'When the block list, the rate limit or the captcha turns a checkout away. Carries the network of the IP address, never the address.',
        'subscription_started' => 'When the provider confirms a subscription and its first period is paid.',
        'subscription_start_failed' => 'When a subscription payment came in but no subscription could be created behind it.',
        'subscription_renewed' => 'Once for every subscription period that was charged and paid.',
        'subscription_attempt_failed' => 'Once for every failed charge of a running subscription, with the number of failures in a row.',
        'subscription_payment_upcoming' => 'A configured number of days before a subscription is charged again.',
        'subscription_card_expiring' => 'When the card behind a subscription is about to expire.',
        'subscription_card_expired' => 'When the card behind a subscription has expired.',
        'subscription_paused' => 'When a subscription is paused, in the Control Panel or in the customer portal.',
        'subscription_resumed' => 'When a paused subscription runs again, by hand or on the date set.',
        'subscription_changed' => 'When a subscription moves to another product, up or down.',
        'subscription_replaced' => 'When a purchase ends an earlier subscription of the same person.',
        'subscription_plan_completed' => 'Once, when the last instalment is paid. "Subscription ended" fires at the same moment.',
        'subscription_cancelled' => 'When the provider confirms a subscription was cancelled.',
        'subscription_ended' => 'When a subscription reaches its own end.',
    ],
];
