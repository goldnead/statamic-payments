{{ __('statamic-payments::cancellation.mail_greeting') }}

{{ __('statamic-payments::cancellation.mail_receipt_body', ['date' => $date, 'time' => $time, 'zone' => $zone]) }}

{{ __('statamic-payments::cancellation.mail_receipt_id') }}: {{ $id }}
{{ __('statamic-payments::cancellation.field_identification') }}: {{ $identification }}
{{ __('statamic-payments::cancellation.field_kind') }}: {{ $kind }}
{{ __('statamic-payments::cancellation.field_effective') }}: {{ $effective ?? __('statamic-payments::cancellation.effective_earliest') }}

{{ __('statamic-payments::cancellation.mail_receipt_next') }}

{{ __('statamic-payments::cancellation.mail_keep') }}
