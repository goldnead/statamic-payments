{{ __('statamic-payments::withdrawal.mail_greeting') }}

{{ __('statamic-payments::withdrawal.mail_receipt_body', ['date' => $date, 'time' => $time, 'zone' => $zone]) }}

{{ __('statamic-payments::withdrawal.mail_receipt_id') }}: {{ $id }}
{{ __('statamic-payments::withdrawal.mail_receipt_reference') }}: {{ $reference }}

{{ __('statamic-payments::withdrawal.mail_receipt_next') }}

{{ __('statamic-payments::withdrawal.mail_keep') }}
