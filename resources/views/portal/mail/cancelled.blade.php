{{ __('statamic-payments::portal.mail_cancelled_greeting') }}

{{ __('statamic-payments::portal.mail_cancelled_body', ['product' => $product, 'date' => $date, 'time' => $time]) }}

{{ $until ? __('statamic-payments::portal.mail_cancelled_until', ['date' => $until]) : __('statamic-payments::portal.mail_cancelled_no_further') }}

{{ __('statamic-payments::portal.mail_cancelled_keep') }}
