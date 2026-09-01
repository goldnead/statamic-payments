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
];
