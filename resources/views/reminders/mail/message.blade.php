{{ $buyer['name'] !== '' ? __('statamic-payments::reminders.greeting_name', ['name' => $buyer['name']]) : __('statamic-payments::reminders.greeting') }}

{{ __('statamic-payments::reminders.'.$kind.'_body', ['plan' => $plan['name'], 'amount' => $plan['display'], 'date' => $date_display]) }}

@if (($plan['coupon'] ?? '') !== '')
{{ $plan['coupon'] }}

@endif
{{ __('statamic-payments::reminders.'.$kind.'_next') }}

{{ __('statamic-payments::reminders.'.$kind.'_button') }}:
{!! $portal_url !!}

{{ __('statamic-payments::reminders.link_expires') }}
