{{ __('statamic-payments::withdrawal.mail_merchant_body', ['id' => $withdrawal->public_id, 'date' => $date, 'time' => $time, 'zone' => $zone]) }}

{{ __('statamic-payments::withdrawal.field_name') }}: {{ $withdrawal->name }}
{{ __('statamic-payments::withdrawal.field_email') }}: {{ $withdrawal->email }}
{{ __('statamic-payments::withdrawal.field_reference') }}: {{ $withdrawal->order_reference }}
{{ __('statamic-payments::withdrawal.field_contact') }}: {{ $withdrawal->contact }}
@if ($withdrawal->message)
{{ __('statamic-payments::withdrawal.field_message') }}: {{ $withdrawal->message }}
@endif

@if ($payment)
{{ __('statamic-payments::withdrawal.mail_merchant_matched', ['id' => $payment->getKey(), 'provider_id' => $payment->provider_id, 'product' => $payment->product, 'amount' => $payment->amount().' '.$payment->currency, 'status' => $payment->status]) }}
@if ($withinPeriod === true)
{{ __('statamic-payments::withdrawal.mail_merchant_within', ['days' => $days]) }}
@elseif ($withinPeriod === false)
{{ __('statamic-payments::withdrawal.mail_merchant_outside', ['days' => $days]) }}
@endif
@if ($withdrawal->right_expired_hint)
{{ __('statamic-payments::withdrawal.mail_merchant_expired_hint') }}
@endif
@else
{{ __('statamic-payments::withdrawal.mail_merchant_unmatched') }}
@endif

{{ __('statamic-payments::withdrawal.mail_merchant_cp') }}: {{ $cpUrl }}
