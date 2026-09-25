{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.mail_cancelled_greeting') }}

{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.mail_cancelled_body', ['product' => $product, 'date' => $date, 'time' => $time]) }}

{{ $until ? \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.mail_cancelled_until', ['date' => $until]) : \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.mail_cancelled_no_further') }}

{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.mail_cancelled_keep') }}
