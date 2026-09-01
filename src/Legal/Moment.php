<?php

namespace Goldnead\StatamicPayments\Legal;

use Illuminate\Support\Carbon;

/**
 * Ein Zeitpunkt, wie er in einer Eingangsbestätigung stehen muss.
 *
 * § 356a Abs. 4 und § 312k Abs. 2 S. 4 BGB verlangen die Zeitangabe. Datum,
 * Uhrzeit und die Zone, in der beides gilt — die Zone, weil „14:32 Uhr" ohne
 * sie eine Behauptung ist, die ein Server in UTC und ein Verbraucher in Berlin
 * verschieden lesen. Formatiert an einer Stelle, damit Mail, Seite und
 * Händlermeldung dieselben Ziffern zeigen.
 */
final class Moment
{
    /** @return array{date: string, time: string, zone: string} */
    public static function parts(Carbon $moment): array
    {
        $local = $moment->copy()->setTimezone((string) config('app.timezone', 'UTC'));

        return [
            'date' => $local->translatedFormat((string) __('statamic-payments::portal.date_format')),
            'time' => $local->translatedFormat((string) __('statamic-payments::portal.time_format')),
            'zone' => $local->tzName,
        ];
    }
}
