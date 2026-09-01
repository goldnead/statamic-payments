<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('statamic-payments::cancellation.mail_merchant_subject', ['id' => $cancellation->public_id]) }}</title>
</head>
<body style="margin:0; padding:0; background:#f4f4f5;">
<div style="max-width:560px; margin:0 auto; padding:32px 24px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#18181b; font-size:15px; line-height:1.6;">

    <p style="margin:0 0 16px;">{{ __('statamic-payments::cancellation.mail_merchant_body', ['id' => $cancellation->public_id, 'date' => $date, 'time' => $time, 'zone' => $zone]) }}</p>

    @if ($subscription && $cancellation->provider_cancelled_at)
        <p style="margin:0 0 16px;">{{ __('statamic-payments::cancellation.mail_merchant_cancelled', ['id' => $subscription->getKey(), 'provider_id' => $subscription->provider_id, 'product' => $subscription->product]) }}</p>
    @elseif ($subscription && $byNumber)
        <p style="margin:0 0 16px; color:#854d0e;">{{ __('statamic-payments::cancellation.mail_merchant_matched_by_number', ['id' => $subscription->getKey(), 'provider_id' => $subscription->provider_id, 'product' => $subscription->product, 'status' => $subscription->status]) }}</p>
    @elseif ($subscription)
        <p style="margin:0 0 16px; color:#854d0e;">{{ __('statamic-payments::cancellation.mail_merchant_matched_not_cancelled', ['id' => $subscription->getKey(), 'provider_id' => $subscription->provider_id, 'product' => $subscription->product, 'status' => $subscription->status]) }}</p>
    @else
        <p style="margin:0 0 16px; color:#854d0e;">{{ __('statamic-payments::cancellation.mail_merchant_unmatched') }}</p>
    @endif

    <table style="border-collapse:collapse; margin:0 0 24px; font-size:15px; width:100%;">
        <tr><td style="padding:4px 16px 4px 0; color:#71717a; white-space:nowrap;">{{ __('statamic-payments::cancellation.field_name') }}</td><td style="padding:4px 0;">{{ $cancellation->name }}</td></tr>
        <tr><td style="padding:4px 16px 4px 0; color:#71717a; white-space:nowrap;">{{ __('statamic-payments::cancellation.field_email') }}</td><td style="padding:4px 0;">{{ $cancellation->email }}</td></tr>
        <tr><td style="padding:4px 16px 4px 0; color:#71717a; white-space:nowrap;">{{ __('statamic-payments::cancellation.field_identification') }}</td><td style="padding:4px 0;">{{ $cancellation->identification }}</td></tr>
        <tr><td style="padding:4px 16px 4px 0; color:#71717a; white-space:nowrap;">{{ __('statamic-payments::cancellation.field_kind') }}</td><td style="padding:4px 0;">{{ $kind }}</td></tr>
        @if ($cancellation->reason)
            <tr><td style="padding:4px 16px 4px 0; color:#71717a; white-space:nowrap; vertical-align:top;">{{ __('statamic-payments::cancellation.field_reason') }}</td><td style="padding:4px 0;">{{ $cancellation->reason }}</td></tr>
        @endif
        <tr><td style="padding:4px 16px 4px 0; color:#71717a; white-space:nowrap;">{{ __('statamic-payments::cancellation.field_effective') }}</td><td style="padding:4px 0;">{{ $effective ?? __('statamic-payments::cancellation.effective_earliest') }}</td></tr>
    </table>

    <p style="margin:0; font-size:13px; color:#71717a;"><a href="{{ $cpUrl }}" style="color:#18181b;">{{ __('statamic-payments::cancellation.mail_merchant_cp') }}</a></p>
</div>
</body>
</html>
