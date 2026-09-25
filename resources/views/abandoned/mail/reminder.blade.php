{{ $buyer['name'] !== '' ? \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::abandoned.mail_greeting_name', ['name' => $buyer['name']]) : \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::abandoned.mail_greeting') }}

{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::abandoned.mail_body') }}

@if ($order['lines_text'] !== '')
{{ $order['lines_text'] }}

@endif
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::abandoned.mail_total', ['total' => $order['total'], 'currency' => $order['currency']]) }}

{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::abandoned.mail_button') }}:
{!! $resume_url !!}

{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::abandoned.mail_ignore') }}
