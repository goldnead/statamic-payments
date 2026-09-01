<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('statamic-payments::withdrawal.mail_merchant_subject', ['id' => $withdrawal->public_id]) }}</title>
</head>
<body style="margin:0; padding:0; background:#f4f4f5;">
<div style="max-width:560px; margin:0 auto; padding:32px 24px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#18181b; font-size:15px; line-height:1.6;">

    <p style="margin:0 0 16px;">{{ __('statamic-payments::withdrawal.mail_merchant_body', ['id' => $withdrawal->public_id, 'date' => $date, 'time' => $time, 'zone' => $zone]) }}</p>

    <table style="border-collapse:collapse; margin:0 0 24px; font-size:15px; width:100%;">
        <tr><td style="padding:4px 16px 4px 0; color:#71717a; white-space:nowrap;">{{ __('statamic-payments::withdrawal.field_name') }}</td><td style="padding:4px 0;">{{ $withdrawal->name }}</td></tr>
        <tr><td style="padding:4px 16px 4px 0; color:#71717a; white-space:nowrap;">{{ __('statamic-payments::withdrawal.field_email') }}</td><td style="padding:4px 0;">{{ $withdrawal->email }}</td></tr>
        <tr><td style="padding:4px 16px 4px 0; color:#71717a; white-space:nowrap;">{{ __('statamic-payments::withdrawal.field_reference') }}</td><td style="padding:4px 0;">{{ $withdrawal->order_reference }}</td></tr>
        <tr><td style="padding:4px 16px 4px 0; color:#71717a; white-space:nowrap;">{{ __('statamic-payments::withdrawal.field_contact') }}</td><td style="padding:4px 0;">{{ $withdrawal->contact }}</td></tr>
        @if ($withdrawal->message)
            <tr><td style="padding:4px 16px 4px 0; color:#71717a; white-space:nowrap; vertical-align:top;">{{ __('statamic-payments::withdrawal.field_message') }}</td><td style="padding:4px 0;">{{ $withdrawal->message }}</td></tr>
        @endif
    </table>

    @if ($payment)
        <p style="margin:0 0 8px;">{{ __('statamic-payments::withdrawal.mail_merchant_matched', ['id' => $payment->getKey(), 'provider_id' => $payment->provider_id, 'product' => $payment->product, 'amount' => $payment->amount().' '.$payment->currency, 'status' => $payment->status]) }}</p>
        @if ($withinPeriod === true)
            <p style="margin:0 0 8px;">{{ __('statamic-payments::withdrawal.mail_merchant_within', ['days' => $days]) }}</p>
        @elseif ($withinPeriod === false)
            <p style="margin:0 0 8px; color:#854d0e;">{{ __('statamic-payments::withdrawal.mail_merchant_outside', ['days' => $days]) }}</p>
        @endif
        @if ($withdrawal->right_expired_hint)
            <p style="margin:0 0 8px; color:#854d0e;">{{ __('statamic-payments::withdrawal.mail_merchant_expired_hint') }}</p>
        @endif
    @else
        <p style="margin:0 0 8px; color:#854d0e;">{{ __('statamic-payments::withdrawal.mail_merchant_unmatched') }}</p>
    @endif

    <p style="margin:24px 0 0; font-size:13px; color:#71717a;"><a href="{{ $cpUrl }}" style="color:#18181b;">{{ __('statamic-payments::withdrawal.mail_merchant_cp') }}</a></p>
</div>
</body>
</html>
