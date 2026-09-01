<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('statamic-payments::cancellation.mail_receipt_subject', ['id' => $id]) }}</title>
</head>
{{--
    Die Bestätigung nach § 312k Abs. 2 S. 4 BGB: Inhalt, Datum und Uhrzeit des
    Eingangs, der genannte Zeitpunkt. Beide Fassungen tragen dieselben Angaben.
--}}
<body style="margin:0; padding:0; background:#f4f4f5;">
<div style="max-width:560px; margin:0 auto; padding:32px 24px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#18181b; font-size:15px; line-height:1.6;">

    <p style="margin:0 0 16px;">{{ __('statamic-payments::cancellation.mail_greeting') }}</p>

    <p style="margin:0 0 16px;">{{ __('statamic-payments::cancellation.mail_receipt_body', ['date' => $date, 'time' => $time, 'zone' => $zone]) }}</p>

    <table style="border-collapse:collapse; margin:0 0 24px; font-size:15px;">
        <tr>
            <td style="padding:4px 16px 4px 0; color:#71717a;">{{ __('statamic-payments::cancellation.mail_receipt_id') }}</td>
            <td style="padding:4px 0; font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; letter-spacing:.06em;">{{ $id }}</td>
        </tr>
        <tr>
            <td style="padding:4px 16px 4px 0; color:#71717a;">{{ __('statamic-payments::cancellation.field_identification') }}</td>
            <td style="padding:4px 0;">{{ $identification }}</td>
        </tr>
        <tr>
            <td style="padding:4px 16px 4px 0; color:#71717a;">{{ __('statamic-payments::cancellation.field_kind') }}</td>
            <td style="padding:4px 0;">{{ $kind }}</td>
        </tr>
        <tr>
            <td style="padding:4px 16px 4px 0; color:#71717a;">{{ __('statamic-payments::cancellation.field_effective') }}</td>
            <td style="padding:4px 0;">{{ $effective ?? __('statamic-payments::cancellation.effective_earliest') }}</td>
        </tr>
    </table>

    <p style="margin:0 0 24px;">{{ __('statamic-payments::cancellation.mail_receipt_next') }}</p>

    <p style="margin:0; font-size:13px; color:#71717a;">{{ __('statamic-payments::cancellation.mail_keep') }}</p>
</div>
</body>
</html>
