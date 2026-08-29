<?php

/*
|--------------------------------------------------------------------------
| Customer self-service
|--------------------------------------------------------------------------
|
| Every word a buyer reads on these screens lives here and nowhere in the code.
|
| For the cancellation strings that is not convenience but the condition under
| which they can be shipped at all. § 312k BGB prescribes a button reading
| „Verträge hier kündigen" and a confirming button reading „Jetzt kündigen".
| That wording belongs in front of a lawyer, the statute has already been
| amended once, and a firm that wants a different phrase has to be able to
| change it without waiting for a release of this addon:
|
|     php artisan vendor:publish --tag=statamic-payments-translations
|
| Note that the English file keeps the German legal wording in the two button
| keys on purpose. The statute prescribes German words, and a shop that serves
| German consumers in an English interface still owes them those words. Change
| them only with advice.
|
| This is not legal advice.
|
*/

return [

    'request_title' => 'Your orders',
    'request_intro' => 'Enter the email address you ordered with. We will send you a link to your orders.',
    'request_label' => 'Email address',
    'request_placeholder' => 'name@example.com',
    'request_button' => 'Send me a link',
    'request_foot' => 'The link is valid for :minutes minutes. It opens your orders without a password.',

    'cancel_entry_title' => 'Cancel a contract',
    'cancel_entry_intro' => 'Enter the email address you took out the contract with. We will send you a link to your contracts, where you can cancel.',
    'cancel_entry_button' => 'Send me a link to cancel',

    'link_sent' => 'If there are orders for that address, the link is on its way.',
    'session_over' => 'The link has expired. Ask for a new one.',
    'signed_out' => 'You are signed out.',
    'sign_out' => 'Sign out',

    'orders_title' => 'Your orders',
    'orders_for' => 'Signed in as :email',
    'orders_none' => 'There are no orders on this address.',
    'orders_heading' => 'Orders',
    'order_view' => 'View',
    'order_refunded' => 'Refunded',

    'subscriptions_heading' => 'Running contracts',
    'subscriptions_none' => 'You have no running contracts.',

    'subscription_next' => 'Next charge on :date',
    'subscription_remaining' => ':count charges remaining',
    'subscription_ended' => 'Ended on :date',

    'status_initiated' => 'Being set up',
    'status_pending' => 'Starts later',
    'status_active' => 'Running',
    'status_suspended' => 'Suspended',
    'status_cancelled' => 'Cancelled',
    'status_completed' => 'Completed',

    'order_title' => 'Order of :date',
    'order_back' => 'Back to the overview',
    'order_lines' => 'Items',
    'order_total' => 'Total',
    'order_invoice' => 'Invoice',
    'order_invoice_download' => 'Download invoice :number',
    'order_invoice_none' => 'There is no invoice for this order.',

    // The statutory wording. German, deliberately — see the note at the top.
    'cancel_button' => 'Verträge hier kündigen',
    'cancel_now' => 'Jetzt kündigen',

    'cancel_title' => 'Cancel contract',
    'cancel_intro' => 'Please check which contract you want to cancel.',
    'cancel_contract' => 'Contract',
    'cancel_price' => 'Price',
    'cancel_started' => 'Started',
    'cancel_next' => 'Next charge',
    'cancel_effect' => 'The cancellation takes effect immediately. There will be no further charge.',
    'cancel_abort' => 'Do not cancel after all',
    'cancel_not_live' => 'This contract is no longer running. There is nothing to cancel.',

    'cancel_failed' => 'The cancellation could not be carried out: the payment provider did not confirm it. Nothing was changed and your contract is running unchanged. Please try again in a few minutes.',

    'cancelled_title' => 'Cancelled',
    'cancelled_confirmation' => 'Your contract ":name" was cancelled on :date at :time.',
    'cancelled_mailed' => 'A confirmation is on its way to :email.',
    'cancelled_not_mailed' => 'The cancellation is effective. The confirmation email to :email could not be delivered just now — please keep this page or get in touch with us.',
    'cancelled_back' => 'Back to the overview',

    'method_button' => 'Change payment method',
    'method_note' => 'To change it, the payment provider charges :amount once. There is no other way to put a new payment method on file.',
    'method_charge_description' => 'Put a new payment method on file',
    'method_returned' => 'The new payment method is being verified. The next charge will use the most recently confirmed one.',
    'method_unavailable' => 'The payment method for this contract cannot be changed.',
    'method_failed' => 'The payment provider did not accept the change just now. Please try again later.',

    'decimal_point' => '.',
    'thousands_separator' => ',',

    'interval_day' => 'daily|every :count days',
    'interval_week' => 'weekly|every :count weeks',
    'interval_month' => 'monthly|every :count months',
    'interval_year' => 'yearly|every :count years',

    'date_format' => 'j F Y',
    'time_format' => 'H:i',

    'mail_link_subject' => 'Your link to your orders',
    'mail_link_greeting' => 'Hello,',
    'mail_link_body' => 'this link opens your orders. It is valid for :minutes minutes.',
    'mail_link_button' => 'Go to my orders',
    'mail_link_ignore' => 'If you did not ask for this, you can ignore this message.',

    'mail_cancelled_subject' => 'Confirmation of your cancellation',
    'mail_cancelled_greeting' => 'Hello,',
    'mail_cancelled_body' => 'we confirm the cancellation of your contract ":product". The cancellation reached us on :date at :time and takes effect immediately.',
    'mail_cancelled_no_further' => 'There will be no further charge.',
    'mail_cancelled_keep' => 'Please keep this message as your record.',
];
