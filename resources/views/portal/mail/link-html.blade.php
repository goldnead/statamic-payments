<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('statamic-payments::portal.mail_link_subject') }}</title>
</head>
{{--
    No layout, no build step, no images, no web fonts, no external stylesheet.
    Inline styles because a mail client is not a browser: half of them drop
    `<style>` blocks, and this mail has to be legible in the other half too.
--}}
<body style="margin:0; padding:0; background:#f4f4f5;">
<div style="max-width:560px; margin:0 auto; padding:32px 24px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#18181b; font-size:15px; line-height:1.6;">

    <p style="margin:0 0 16px;">{{ __('statamic-payments::portal.mail_link_greeting') }}</p>

    <p style="margin:0 0 24px;">{{ __('statamic-payments::portal.mail_link_body', ['minutes' => $minutes]) }}</p>

    {{--
        Escaped, and it has to be — the exact opposite of the plain-text body,
        for the exact same reason. An attribute value *is* an HTML context,
        `&amp;` is what a `&` is called inside one, and the client turns it back
        into `&` before the request ever reaches Laravel.
    --}}
    <p style="margin:0 0 24px;">
        <a href="{{ $url }}" style="display:inline-block; padding:11px 18px; border-radius:8px; background:#18181b; color:#ffffff; text-decoration:none;">{{ __('statamic-payments::portal.mail_link_button') }}</a>
    </p>

    <p style="margin:0 0 24px; font-size:13px; color:#71717a; word-break:break-all;">{{ $url }}</p>

    <p style="margin:0; font-size:13px; color:#a1a1aa;">{{ __('statamic-payments::portal.mail_link_ignore') }}</p>
</div>
</body>
</html>
