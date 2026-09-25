{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.mail_greeting') }}

{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.mail_receipt_body', ['date' => $date, 'time' => $time, 'zone' => $zone]) }}

{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.mail_receipt_id') }}: {{ $id }}
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_identification') }}: {{ $identification }}
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_kind') }}: {{ $kind }}
@if ($reason)
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_reason') }}: {{ $reason }}
@endif
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_effective') }}: {{ $effective ?? \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.effective_earliest') }}

{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.mail_receipt_next') }}

{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.mail_keep') }}
