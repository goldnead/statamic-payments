{{ $buyer['name'] !== '' ? \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::dunning.mail_greeting_name', ['name' => $buyer['name']]) : \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::dunning.mail_greeting') }}

{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::dunning.mail_body', ['plan' => $plan['name'], 'amount' => $plan['display']]) }}

@if ($final)
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::dunning.mail_final') }}
@else
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::dunning.mail_again') }}
@endif

{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::dunning.mail_button') }}:
{!! $portal_url !!}

{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::dunning.mail_expires') }}
