<?php

namespace Goldnead\StatamicPayments\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Ein Anbieter hat den API-Schluessel abgelehnt (401, 403).
 *
 * Jede Zustellung schreibt dann ihren eigenen Fehler, und in dieser Flut geht
 * der eine Satz unter, der zaehlt: niemand wird mehr erfuellt, bis der
 * Schluessel stimmt. Deshalb zusaetzlich einmal je Stunde und Anbieter ein
 * `Log::critical`, auf das eine Ueberwachung (Sentry, Bugsink, Log-Alarm)
 * anspringen kann. `Cache::add` ist der Anspruch: nur wer den Eintrag anlegt,
 * meldet.
 */
final class RejectedKey
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function report(string $provider, string $setting, array $context): void
    {
        Log::error("statamic-payments: {$provider} rejected the API key. Check {$setting}.", $context);

        if (Cache::add("statamic-payments:rejected-key:{$provider}", true, Carbon::now()->addHour())) {
            Log::critical("statamic-payments: {$provider} rejects the API key; no payment from {$provider} is fulfilled until {$setting} is fixed. Reported once per hour.", $context + ['provider' => $provider]);
        }
    }
}
