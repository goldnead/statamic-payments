<?php

/*
 * The reminder about an open purchase. Publishable under
 * lang/vendor/statamic-payments/en/abandoned.php.
 */
return [
    'mail_subject' => 'Your purchase is not complete yet',
    'mail_greeting' => 'Hello,',
    'mail_greeting_name' => 'Hello :name,',
    'mail_body' => 'You started a purchase and did not finish it. If you would like to continue, your selection is still there.',
    'mail_total' => 'Total: :total :currency',
    'mail_button' => 'Continue the purchase',
    'mail_ignore' => 'If you have changed your mind, you can ignore this message. Nothing has been charged.',

    'log_suppressed' => 'No reminder sent: :email is on the suppression list.',

    'resume_unavailable' => 'This purchase can no longer be continued. It has already been completed, or the selection is no longer available.',

    // The order page behind the link (§ 312j (3) BGB).
    'resume_title' => 'Continue the purchase',
    'resume_intro' => 'This is your selection. The button below places a binding order and takes you to the payment.',
    'resume_discount' => 'Discount',
    'resume_total' => 'Total',
    'resume_price_hint' => 'All prices include VAT.',
    'resume_withdrawal_heading' => 'Right of withdrawal',
    'resume_withdrawal_text' => 'As a consumer you have a fourteen-day right of withdrawal.',
    'resume_policy_link' => 'Read the withdrawal instruction',
    'resume_button' => 'Order with obligation to pay',
    'resume_button_hint' => 'You will be taken to the payment next. Nothing is charged before that.',
    'resume_foot' => 'This page belongs to a reminder sent to :email.',
];
