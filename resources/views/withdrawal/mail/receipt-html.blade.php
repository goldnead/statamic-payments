<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('statamic-payments::withdrawal.mail_receipt_subject', ['id' => $id]) }}</title>
</head>
{{--
    Die Eingangsbestätigung nach § 356a Abs. 4 BGB. Beide Fassungen tragen
    dieselben drei Tatsachen — Kennung, Zeitpunkt mit Zone, Bestellkennung —
    weil ein Empfänger, dessen Client HTML verwirft, denselben Beleg bekommt
    wie einer, dessen Client es nicht tut.
--}}
<body style="margin:0; padding:0; background:#f4f4f5;">
<div style="max-width:560px; margin:0 auto; padding:32px 24px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#18181b; font-size:15px; line-height:1.6;">

    <p style="margin:0 0 16px;">{{ __('statamic-payments::withdrawal.mail_greeting') }}</p>

    <p style="margin:0 0 16px;">{{ __('statamic-payments::withdrawal.mail_receipt_body', ['date' => $date, 'time' => $time, 'zone' => $zone]) }}</p>

    <table style="border-collapse:collapse; margin:0 0 24px; font-size:15px;">
        <tr>
            <td style="padding:4px 16px 4px 0; color:#71717a;">{{ __('statamic-payments::withdrawal.mail_receipt_id') }}</td>
            <td style="padding:4px 0; font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; letter-spacing:.06em;">{{ $id }}</td>
        </tr>
        <tr>
            <td style="padding:4px 16px 4px 0; color:#71717a;">{{ __('statamic-payments::withdrawal.mail_receipt_reference') }}</td>
            <td style="padding:4px 0;">{{ $reference }}</td>
        </tr>
    </table>

    <p style="margin:0 0 24px;">{{ __('statamic-payments::withdrawal.mail_receipt_next') }}</p>

    <p style="margin:0; font-size:13px; color:#71717a;">{{ __('statamic-payments::withdrawal.mail_keep') }}</p>
</div>
</body>
</html>
