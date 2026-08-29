<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('statamic-payments::portal.mail_cancelled_subject') }}</title>
</head>
{{--
    The confirmation § 312k Abs. 2 S. 4 BGB asks for. Both parts carry the same
    three facts — what was cancelled, on which date, at which time — because a
    recipient whose client strips HTML is owed the same evidence as one whose
    client does not.
--}}
<body style="margin:0; padding:0; background:#f4f4f5;">
<div style="max-width:560px; margin:0 auto; padding:32px 24px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#18181b; font-size:15px; line-height:1.6;">

    <p style="margin:0 0 16px;">{{ __('statamic-payments::portal.mail_cancelled_greeting') }}</p>

    <p style="margin:0 0 16px;">{{ __('statamic-payments::portal.mail_cancelled_body', ['product' => $product, 'date' => $date, 'time' => $time]) }}</p>

    <p style="margin:0 0 24px;">{{ __('statamic-payments::portal.mail_cancelled_no_further') }}</p>

    <p style="margin:0; font-size:13px; color:#71717a;">{{ __('statamic-payments::portal.mail_cancelled_keep') }}</p>
</div>
</body>
</html>
