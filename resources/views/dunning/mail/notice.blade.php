{{ $buyer['name'] !== '' ? __('statamic-payments::dunning.mail_greeting_name', ['name' => $buyer['name']]) : __('statamic-payments::dunning.mail_greeting') }}

{{ __('statamic-payments::dunning.mail_body', ['plan' => $plan['name'], 'amount' => $plan['display']]) }}

@if ($final)
{{ __('statamic-payments::dunning.mail_final') }}
@else
{{ __('statamic-payments::dunning.mail_again') }}
@endif

{{ __('statamic-payments::dunning.mail_button') }}:
{!! $portal_url !!}

{{ __('statamic-payments::dunning.mail_expires') }}
