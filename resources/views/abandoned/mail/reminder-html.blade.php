<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('statamic-payments::abandoned.mail_subject') }}</title>
</head>
{{--
    Same rules as the portal mails: no layout, no images, inline styles. A site
    that wants its own look sets `abandoned.mail.template` to an email-templates
    slug or publishes this view.
--}}
<body style="margin:0; padding:0; background:#f4f4f5;">
<div style="max-width:560px; margin:0 auto; padding:32px 24px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#18181b; font-size:15px; line-height:1.6;">

    <p style="margin:0 0 16px;">{{ $buyer['name'] !== '' ? __('statamic-payments::abandoned.mail_greeting_name', ['name' => $buyer['name']]) : __('statamic-payments::abandoned.mail_greeting') }}</p>

    <p style="margin:0 0 16px;">{{ __('statamic-payments::abandoned.mail_body') }}</p>

    @if ($order['lines'] !== '')
        {{-- Built server-side from escaped values, see AbandonedReminder::variables(). --}}
        <div style="margin:0 0 16px; padding:12px 16px; background:#ffffff; border-radius:8px;">{!! $order['lines'] !!}</div>
    @endif

    <p style="margin:0 0 24px;">{{ __('statamic-payments::abandoned.mail_total', ['total' => $order['total'], 'currency' => $order['currency']]) }}</p>

    <p style="margin:0 0 24px;">
        <a href="{{ $resume_url }}" style="display:inline-block; padding:11px 18px; border-radius:8px; background:#18181b; color:#ffffff; text-decoration:none;">{{ __('statamic-payments::abandoned.mail_button') }}</a>
    </p>

    <p style="margin:0 0 24px; font-size:13px; color:#71717a; word-break:break-all;">{{ $resume_url }}</p>

    <p style="margin:0; font-size:13px; color:#a1a1aa;">{{ __('statamic-payments::abandoned.mail_ignore') }}</p>
</div>
</body>
</html>
