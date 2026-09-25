{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.mail_greeting') }}

{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.mail_receipt_body', ['date' => $date, 'time' => $time, 'zone' => $zone]) }}

{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.mail_receipt_id') }}: {{ $id }}
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.mail_receipt_reference') }}: {{ $reference }}

{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.mail_receipt_next') }}

{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.mail_keep') }}
