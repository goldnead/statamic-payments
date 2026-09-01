<?php

/*
|--------------------------------------------------------------------------
| Withdrawal — § 356a BGB
|--------------------------------------------------------------------------
|
| Every word a consumer reads on the way through a withdrawal lives here. Two
| of them are prescribed by statute: the button „Vertrag widerrufen" (para. 1)
| and the confirming button „Widerruf bestätigen" (para. 3). The English file
| keeps the German legal wording in those two keys on purpose — the statute
| prescribes German words, and a shop that serves German consumers in an
| English interface still owes them those words. Change them only with advice.
|
|     php artisan vendor:publish --tag=statamic-payments-translations
|
| Legal decisions taken on 1 September 2026, documented for review. This is
| not legal advice.
|
*/

return [

    'button' => 'Vertrag widerrufen',

    'title' => 'Withdraw from a contract',
    'intro' => 'Here you can withdraw from a contract concluded through this website. Tell us who you are and which order you mean. On the next page you will see your details again and confirm the withdrawal.',
    'errors_intro' => 'Please check the highlighted fields.',
    'field_name' => 'Name',
    'field_email' => 'Email address',
    'field_email_help' => 'The address you ordered with. The acknowledgement goes there.',
    'field_reference' => 'Order number or reference',
    'field_reference_help' => 'From the order confirmation or the invoice. If you cannot find it, describe the order in the message.',
    'field_contact' => 'How should we reach you?',
    'field_contact_placeholder' => 'Email (default), phone or postal address',
    'field_contact_help' => 'Left empty, we use your email address.',
    'field_message' => 'Message (optional)',
    'continue' => 'Continue to confirmation',
    'continue_hint' => 'Nothing is withdrawn yet with this step.',
    'policy_link' => 'Withdrawal instruction',
    'foot' => 'Your details are stored to process the withdrawal.',

    'confirm_title' => 'Confirm withdrawal',
    'confirm_intro' => 'Please check your details. The button below declares the withdrawal.',
    'confirm_effect' => 'After confirming you will immediately receive an acknowledgement with a reference and the time, by email.',

    'confirm_button' => 'Widerruf bestätigen',
    'confirm_back' => 'Change details',
    'restart' => 'This page is no longer available. Please start again.',

    'done_title' => 'Your withdrawal has been received',
    'done_received' => 'The withdrawal reached us on :date at :time (:zone).',
    'done_id_label' => 'Your withdrawal reference',
    'done_mailed' => 'An acknowledgement with this reference is on its way to your email address.',
    'done_not_mailed' => 'The withdrawal has been received. The acknowledgement email could not be delivered just now — please note the reference and the time, or keep this page.',
    'done_keep' => 'Please keep the reference as proof. We will get back to you through the contact details you gave.',

    'mail_receipt_subject' => 'Receipt of your withdrawal :id',
    'mail_greeting' => 'Hello,',
    'mail_receipt_body' => 'the withdrawal reached us on :date at :time (:zone).',
    'mail_receipt_id' => 'Withdrawal reference',
    'mail_receipt_reference' => 'Your order reference',
    'mail_receipt_next' => 'We will review the matter and get back to you. This message confirms receipt of your declaration.',
    'mail_keep' => 'Please keep this message as proof.',

    'mail_merchant_subject' => 'Withdrawal received: :id',
    'mail_merchant_body' => 'A withdrawal with reference :id was received on :date at :time (:zone).',
    'mail_merchant_matched' => 'Matched payment: #:id (:provider_id), :product, :amount, status :status.',
    'mail_merchant_within' => 'The withdrawal arrived within the configured period of :days days from payment.',
    'mail_merchant_outside' => 'Note: the withdrawal arrived after the configured period of :days days from payment. Whether the period has actually run is for you to check.',
    'mail_merchant_expired_hint' => 'Note: the payment carries a recorded consent under § 356 (5) BGB (immediate delivery of digital content). Whether the right of withdrawal has thereby expired is for you to check.',
    'mail_merchant_unmatched' => 'No unambiguous payment could be matched. The withdrawal has been received all the same and needs handling by hand.',
    'mail_merchant_cp' => 'Open in the Control Panel',
];
