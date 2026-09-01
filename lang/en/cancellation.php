<?php

/*
|--------------------------------------------------------------------------
| Cancellation without login — § 312k BGB
|--------------------------------------------------------------------------
|
| Two keys keep the German statutory wording on purpose: the cancellation
| button „Verträge hier kündigen" (para. 2 s. 2) and the confirming button
| „jetzt kündigen" (para. 2 s. 3). A shop serving German consumers through an
| English interface still owes them those words. Change them only with advice.
|
|     php artisan vendor:publish --tag=statamic-payments-translations
|
| Legal decisions taken on 1 September 2026, documented for review. This is
| not legal advice.
|
*/

return [

    'button' => 'Verträge hier kündigen',

    'title' => 'Cancel a contract',
    'intro' => 'Here you can cancel a contract concluded through this website without logging in. On the next page you will see your details again and confirm the cancellation.',
    'errors_intro' => 'Please check the highlighted fields.',
    'field_name' => 'Name',
    'field_email' => 'Email address',
    'field_email_help' => 'The address you took out the contract with. The confirmation goes there.',
    'field_identification' => 'Contract or customer number',
    'field_identification_help' => 'From the confirmation or the last invoice. If you cannot find it, describe the contract under reason.',
    'field_kind' => 'Type of cancellation',
    'kind_ordinary' => 'Ordinary cancellation',
    'kind_ordinary_help' => 'At the earliest possible date, or a date you name.',
    'kind_extraordinary' => 'Extraordinary cancellation',
    'kind_extraordinary_help' => 'For cause. Please state the reason.',
    'field_reason' => 'Reason',
    'field_reason_help' => 'Required for an extraordinary cancellation, optional otherwise.',
    'field_effective' => 'Date the cancellation should take effect',
    'field_effective_help' => 'Left empty: at the earliest possible date.',
    'effective_earliest' => 'earliest possible',
    'continue' => 'Continue to confirmation',
    'continue_hint' => 'Nothing is cancelled yet with this step.',
    'policy_link' => 'Notes on terms and notice periods',
    'foot' => 'Your details are stored to process the cancellation.',

    'confirm_title' => 'Confirm cancellation',
    'confirm_intro' => 'Please check your details. The button below declares the cancellation.',
    'confirm_effect' => 'After confirming you will immediately receive a confirmation with the date and time of receipt, by email.',

    'confirm_button' => 'jetzt kündigen',
    'confirm_back' => 'Change details',

    'done_title' => 'Your cancellation has been received',
    'done_received' => 'The cancellation reached us on :date at :time (:zone).',
    'done_effective' => 'Requested date: :date.',
    'done_effective_earliest' => 'Requested date: earliest possible.',
    'done_id_label' => 'Your cancellation reference',
    'done_mailed' => 'A confirmation with this reference is on its way to your email address.',
    'done_not_mailed' => 'The cancellation has been received. The confirmation email could not be delivered just now — please note the reference and the time, or keep this page.',
    'done_keep' => 'Please keep the reference as proof. We will let you know when the contract ends.',

    'mail_receipt_subject' => 'Receipt of your cancellation :id',
    'mail_greeting' => 'Hello,',
    'mail_receipt_body' => 'we confirm receipt of your cancellation on :date at :time (:zone).',
    'mail_receipt_id' => 'Cancellation reference',
    'mail_receipt_next' => 'We will let you know separately when the contract ends. This message confirms receipt of your declaration and its content.',
    'mail_keep' => 'Please keep this message as proof.',

    'mail_merchant_subject' => 'Cancellation received: :id',
    'mail_merchant_body' => 'A cancellation with reference :id was received on :date at :time (:zone).',
    'mail_merchant_cancelled' => 'Subscription #:id (:provider_id, :product) was matched unambiguously and cancelled at the payment provider. No further charge will follow.',
    'mail_merchant_matched_by_number' => 'Subscription #:id (:provider_id, :product, status :status) was matched by customer number, not by the provider reference — so it was not cancelled automatically. Please check and cancel it in the Control Panel.',
    'restart' => 'This page is no longer available. Please start again.',
    'mail_merchant_matched_not_cancelled' => 'Subscription #:id (:provider_id, :product, status :status) was matched but not cancelled at the provider — it is no longer running or the provider did not confirm. Please check.',
    'mail_merchant_unmatched' => 'No unambiguous subscription could be matched. The cancellation has been received all the same and needs handling by hand.',
    'mail_merchant_cp' => 'Open in the Control Panel',
];
