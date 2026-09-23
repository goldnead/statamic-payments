<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('statamic-payments::reminders.'.$kind.'_subject', ['plan' => $plan['name']]) }}</title>
</head>
{{--
    One layout for the three reminders, the dunning letter's: no images, inline
    styles, one link. The wording is in the reminders translation file; a site with its own
    look sets `reminders.<kind>.template` to an email-templates slug or publishes
    this view.
--}}
<body style="margin:0;padding:24px;background:#f6f6f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:#1a1a1a;">
<div style="max-width:520px;margin:0 auto;background:#ffffff;padding:32px;border-radius:8px;">
    <p style="margin:0 0 16px;font-size:16px;line-height:1.5;">
        {{ $buyer['name'] !== '' ? __('statamic-payments::reminders.greeting_name', ['name' => $buyer['name']]) : __('statamic-payments::reminders.greeting') }}
    </p>

    <p style="margin:0 0 16px;font-size:16px;line-height:1.5;">
        {{ __('statamic-payments::reminders.'.$kind.'_body', ['plan' => $plan['name'], 'amount' => $plan['display'], 'date' => $date_display]) }}
    </p>

    <p style="margin:0 0 24px;font-size:16px;line-height:1.5;">
        {{ __('statamic-payments::reminders.'.$kind.'_next') }}
    </p>

    <p style="margin:0 0 24px;">
        <a href="{{ $portal_url }}" style="display:inline-block;padding:12px 20px;background:#1a1a1a;color:#ffffff;text-decoration:none;border-radius:6px;font-size:16px;">
            {{ __('statamic-payments::reminders.'.$kind.'_button') }}
        </a>
    </p>

    <p style="margin:0;font-size:13px;line-height:1.5;color:#6b6b6b;">
        {{ __('statamic-payments::reminders.link_expires') }}
    </p>
</div>
</body>
</html>
