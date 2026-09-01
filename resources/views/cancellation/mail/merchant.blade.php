{{ __('statamic-payments::cancellation.mail_merchant_body', ['id' => $cancellation->public_id, 'date' => $date, 'time' => $time, 'zone' => $zone]) }}

@if ($subscription && $cancellation->provider_cancelled_at)
{{ __('statamic-payments::cancellation.mail_merchant_cancelled', ['id' => $subscription->getKey(), 'provider_id' => $subscription->provider_id, 'product' => $subscription->product]) }}
@elseif ($subscription && $byNumber)
{{ __('statamic-payments::cancellation.mail_merchant_matched_by_number', ['id' => $subscription->getKey(), 'provider_id' => $subscription->provider_id, 'product' => $subscription->product, 'status' => $subscription->status]) }}
@elseif ($subscription)
{{ __('statamic-payments::cancellation.mail_merchant_matched_not_cancelled', ['id' => $subscription->getKey(), 'provider_id' => $subscription->provider_id, 'product' => $subscription->product, 'status' => $subscription->status]) }}
@else
{{ __('statamic-payments::cancellation.mail_merchant_unmatched') }}
@endif

{{ __('statamic-payments::cancellation.field_name') }}: {{ $cancellation->name }}
{{ __('statamic-payments::cancellation.field_email') }}: {{ $cancellation->email }}
{{ __('statamic-payments::cancellation.field_identification') }}: {{ $cancellation->identification }}
{{ __('statamic-payments::cancellation.field_kind') }}: {{ $kind }}
@if ($cancellation->reason)
{{ __('statamic-payments::cancellation.field_reason') }}: {{ $cancellation->reason }}
@endif
{{ __('statamic-payments::cancellation.field_effective') }}: {{ $effective ?? __('statamic-payments::cancellation.effective_earliest') }}

{{ __('statamic-payments::cancellation.mail_merchant_cp') }}: {{ $cpUrl }}
