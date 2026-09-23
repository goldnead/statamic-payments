<?php

namespace Goldnead\StatamicPayments\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The shop's clock: Statamic's display time zone, else the application's.
 *
 * Every "which day is it" in this package that a person reads or plans by asks
 * here: a reminder "five days before", the day a pause may end, a date on the
 * subscription screen. The database keeps UTC; a German shop at 01:30 on the
 * 23rd is not on the 22nd.
 */
final class LocalTime
{
    public static function zone(): string
    {
        $zone = config('statamic.system.display_timezone') ?: config('app.timezone', 'UTC');

        return is_string($zone) && $zone !== '' ? $zone : 'UTC';
    }

    public static function now(): Carbon
    {
        return Carbon::now(self::zone());
    }

    public static function today(): Carbon
    {
        return self::now()->startOfDay();
    }

    /** A stored moment, in the shop's zone. */
    public static function of(?CarbonInterface $moment): ?Carbon
    {
        return $moment === null ? null : Carbon::instance($moment)->copy()->setTimezone(self::zone());
    }

    /** The day of a stored moment in the shop's zone, at midnight there. */
    public static function day(?CarbonInterface $moment): ?Carbon
    {
        return self::of($moment)?->startOfDay();
    }

    /** "05.10.2026 02:00" (`L LT` in the reader's language). */
    public static function moment(?CarbonInterface $moment): ?string
    {
        return self::of($moment)?->locale(app()->getLocale())->isoFormat('L LT');
    }

    /** "05.10.2026" (`L`). */
    public static function date(?CarbonInterface $moment): ?string
    {
        return self::of($moment)?->locale(app()->getLocale())->isoFormat('L');
    }
}
