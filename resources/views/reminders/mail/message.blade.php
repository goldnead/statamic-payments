{{ $buyer['name'] !== '' ? \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::reminders.greeting_name', ['name' => $buyer['name']]) : \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::reminders.greeting') }}

{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::reminders.'.$kind.'_body', ['plan' => $plan['name'], 'amount' => $plan['display'], 'date' => $date_display]) }}

@if (($plan['coupon'] ?? '') !== '')
{{ $plan['coupon'] }}

@endif
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::reminders.'.$kind.'_next') }}

{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::reminders.'.$kind.'_button') }}:
{!! $portal_url !!}

{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::reminders.link_expires') }}
