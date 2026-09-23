<?php

/*
| Pausing, resuming and switching subscriptions: Control Panel and customer portal.
*/

return [

    // Control Panel: actions
    'pause' => 'Pause',
    'pause_confirm' => 'Pause this subscription? Nothing is charged until it resumes.|Pause these :count subscriptions? Nothing is charged until they resume.',
    'pause_resume_on' => 'Resume on',
    'pause_resume_on_instructions' => 'Leave empty to pause without a date. On this day the subscription resumes by itself.',
    'paused_bulk' => 'The subscription is paused.|:count subscriptions are paused.',
    'pause_failed' => ':failed of :total subscriptions were not paused. They are unchanged.',

    'resume' => 'Resume',
    'resume_confirm' => 'Resume this subscription? The next charge falls on the regular billing day.|Resume these :count subscriptions? The next charge falls on the regular billing day.',
    'resumed_bulk' => 'The subscription is running again.|:count subscriptions are running again.',
    'resume_failed' => ':failed of :total subscriptions were not resumed. They stay paused.',

    'switch' => 'Switch',
    'switch_to' => 'New product',
    'switch_to_instructions' => 'More expensive: applies at once, the difference for the current period is charged pro rata. Cheaper: applies from the next charge.',
    'switch_confirm' => 'Switch this subscription?',
    'switched_bulk' => 'The subscription was switched.|:count subscriptions were switched.',
    'switch_failed' => 'The subscription was not switched. It is unchanged.',
    'switch_line' => 'Switch to :name, pro rata until the next charge',

    // Running coupon (statamic-offers O6)
    'coupon_until' => 'With coupon :code, :off less, up to and including :date.',
    'coupon_forever' => 'With coupon :code, :off less, for good.',
    'coupon_until_short' => ':code, :off less, up to and including :date',
    'coupon_forever_short' => ':code, :off less, for good',

    // Control Panel: detail
    'field_paused_at' => 'Paused since',
    'field_coupon' => 'Coupon',
    'field_resumes_at' => 'Resumes on',
    'field_card_expires_at' => 'Card valid until',
    'detail_history' => 'History',
    'history_switch' => 'Switched from :from to :to, at once, :amount charged pro rata',
    'history_switch_later' => 'Switched from :from to :to, from the next charge',
    'history_proration_failed' => 'The difference was not paid.',
    'history_pause' => 'Paused from :from to :to',

    // Customer portal
    'portal_pause_button' => 'Pause',
    'portal_pause_title' => 'Pause contract',
    'portal_pause_intro' => 'Nothing is charged for :name during the pause.',
    'portal_paid_until' => 'Paid until',
    'portal_pause_effect' => 'You keep the period you have paid for. On resuming, nothing is charged at once; the next charge falls on the regular billing day.',
    'portal_pause_date_help' => 'Optional. Without a date the contract stays paused until you resume it here.',
    'portal_pause_now' => 'Pause now',
    'portal_pause_unavailable' => 'This contract cannot be paused here.',
    'portal_pause_date_invalid' => 'Please choose a date from tomorrow on.',
    'portal_pause_failed' => 'Pausing did not work. The contract runs on unchanged. Please try again.',
    'portal_paused' => 'The contract is paused.',
    'portal_paused_until' => 'Paused until :date',
    'portal_paused_open' => 'Paused',

    'portal_resume_button' => 'Resume',
    'portal_resume_note' => 'The next charge falls on the regular billing day.',
    'portal_resume_failed' => 'Resuming did not work. The contract stays paused. Please try again.',
    'portal_resumed' => 'The contract is running again. Next charge on :date.',

    'portal_switch_button' => 'Change plan',
    'portal_switch_title' => 'Change plan',
    'portal_switch_intro' => 'You currently pay :amount for :name.',
    'portal_switch_now' => 'Change',
    'portal_switch_now_charge' => 'Applies at once. :amount is charged today for the rest of the current period.',
    'portal_switch_now_free' => 'Applies at once. Nothing extra is charged for the current period.',
    'portal_switch_later' => 'Applies from the next charge on :date. Until then everything stays as it is.',
    'portal_switch_unavailable' => 'This contract cannot be changed here.',
    'portal_switch_failed' => 'The change did not work. The contract is unchanged.',
    'portal_switched' => 'Changed to :name.',

    'portal_back' => 'Back to the overview',
    'portal_cancel_hint' => 'You can cancel this contract here:',
    'portal_cancel_elsewhere' => 'This contract is cancelled on the cancellation page, not here in your account.',
];
