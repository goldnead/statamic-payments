<?php

return [
    'mail_subject' => 'We could not collect your payment',
    'mail_greeting' => 'Hello,',
    'mail_greeting_name' => 'Hello :name,',
    'mail_body' => 'We could not collect :amount for :plan. An expired card is the usual reason.',
    'mail_again' => 'We will try again. Update your payment method and it simply carries on.',
    'mail_final' => 'This is the last reminder. Without a working payment method your access ends in the next few days.',
    'mail_button' => 'Update payment method',
    'mail_expires' => 'The link is short-lived. If it has expired, the same page will send you a new one.',
    'log_suppressed' => 'Dunning letter not sent: :email is on the suppression list.',
];
