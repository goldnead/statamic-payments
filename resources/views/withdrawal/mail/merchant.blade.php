{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.mail_merchant_body', ['id' => $withdrawal->public_id, 'date' => $date, 'time' => $time, 'zone' => $zone]) }}

{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.field_name') }}: {{ $withdrawal->name }}
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.field_email') }}: {{ $withdrawal->email }}
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.field_reference') }}: {{ $withdrawal->order_reference }}
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.field_contact') }}: {{ $withdrawal->contact }}
@if ($withdrawal->message)
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.field_message') }}: {{ $withdrawal->message }}
@endif

@if ($payment)
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.mail_merchant_matched', ['id' => $payment->getKey(), 'provider_id' => $payment->provider_id, 'product' => $payment->product, 'amount' => $payment->amount().' '.$payment->currency, 'status' => $payment->status]) }}
@if ($withinPeriod === true)
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.mail_merchant_within', ['days' => $days]) }}
@elseif ($withinPeriod === false)
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.mail_merchant_outside', ['days' => $days]) }}
@endif
@if ($withdrawal->right_expired_hint)
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.mail_merchant_expired_hint') }}
@endif
@else
{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.mail_merchant_unmatched') }}
@endif

{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.mail_merchant_cp') }}: {{ $cpUrl }}
