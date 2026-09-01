{{ $buyer['name'] !== '' ? __('statamic-payments::abandoned.mail_greeting_name', ['name' => $buyer['name']]) : __('statamic-payments::abandoned.mail_greeting') }}

{{ __('statamic-payments::abandoned.mail_body') }}

@if ($order['lines_text'] !== '')
{{ $order['lines_text'] }}

@endif
{{ __('statamic-payments::abandoned.mail_total', ['total' => $order['total'], 'currency' => $order['currency']]) }}

{{ __('statamic-payments::abandoned.mail_button') }}:
{!! $resume_url !!}

{{ __('statamic-payments::abandoned.mail_ignore') }}
