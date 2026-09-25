{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.mail_merchant_body', ['id' => $cancellation->public_id, 'date' => $date, 'time' => $time, 'zone' => $zone]) }}

@if ($subscription && $cancellation->provider_cancelled_at)
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.mail_merchant_cancelled', ['id' => $subscription->getKey(), 'provider_id' => $subscription->provider_id, 'product' => $subscription->product]) }}
@elseif ($subscription && $byNumber)
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.mail_merchant_matched_by_number', ['id' => $subscription->getKey(), 'provider_id' => $subscription->provider_id, 'product' => $subscription->product, 'status' => $subscription->status]) }}
@elseif ($subscription)
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.mail_merchant_matched_not_cancelled', ['id' => $subscription->getKey(), 'provider_id' => $subscription->provider_id, 'product' => $subscription->product, 'status' => $subscription->status]) }}
@else
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.mail_merchant_unmatched') }}
@endif

{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_name') }}: {{ $cancellation->name }}
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_email') }}: {{ $cancellation->email }}
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_identification') }}: {{ $cancellation->identification }}
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_kind') }}: {{ $kind }}
@if ($cancellation->reason)
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_reason') }}: {{ $cancellation->reason }}
@endif
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_effective') }}: {{ $effective ?? \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.effective_earliest') }}

{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.mail_merchant_cp') }}: {{ $cpUrl }}
