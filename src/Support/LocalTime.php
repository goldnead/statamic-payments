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
    /**
     * In dieser Reihenfolge, der erste gültige Wert gewinnt:
     *
     * 1. `statamic-payments.display_timezone` (im CP je Marke einstellbar,
     *    Einstellungs-Schicht von brand-context),
     * 2. `statamic-payments.legal.timezone` (der ältere Schlüssel für Belege),
     * 3. `statamic.system.display_timezone`,
     * 4. `app.timezone`.
     *
     * **`app.timezone` wird nie gedreht.** Die Zeitspalten halten UTC ohne
     * Kennung; eine andere Anwendungszone schriebe die Bedeutung jedes
     * gespeicherten Zeitstempels um. Angezeigt wird lokal, gespeichert UTC.
     * Ein unbekannter Name (Tippfehler im CP) fällt auf den nächsten zurück,
     * statt jede Seite mit einer Ausnahme zu beenden.
     */
    public static function zone(): string
    {
        foreach ([
            config('statamic-payments.display_timezone'),
            config('statamic-payments.legal.timezone'),
            config('statamic.system.display_timezone'),
            config('app.timezone'),
        ] as $candidate) {
            if (is_string($candidate) && ($candidate = trim($candidate)) !== '' && self::valid($candidate)) {
                return $candidate;
            }
        }

        return 'UTC';
    }

    private static function valid(string $zone): bool
    {
        try {
            new \DateTimeZone($zone);

            return true;
        } catch (\Throwable) {
            return false;
        }
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

    /**
     * A stored moment in the shop's zone, in a `translatedFormat()` pattern
     * (the portal's `date_format` / `time_format`). Empty for no moment.
     */
    public static function format(?CarbonInterface $moment, string $pattern): string
    {
        return self::of($moment)?->locale(app()->getLocale())->translatedFormat($pattern) ?? '';
    }

    /** The portal's date (`portal.date_format`) in the shop's zone. */
    public static function portalDate(?CarbonInterface $moment): string
    {
        return self::format($moment, (string) __('statamic-payments::portal.date_format'));
    }

    /** The portal's time (`portal.time_format`) in the shop's zone. */
    public static function portalTime(?CarbonInterface $moment): string
    {
        return self::format($moment, (string) __('statamic-payments::portal.time_format'));
    }

    /** "05.10.2026" (`L`). */
    public static function date(?CarbonInterface $moment): ?string
    {
        return self::of($moment)?->locale(app()->getLocale())->isoFormat('L');
    }
}
