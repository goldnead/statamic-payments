<?php

namespace Goldnead\StatamicPayments\Legal;

/**
 * Wer im Haus von einem Widerruf oder einer Kündigung erfährt.
 *
 * Drei Stellen, in dieser Reihenfolge: die eigene `notify`-Adresse des
 * jeweiligen Blocks, der Absender des Portals, der Absender der Anwendung. Die
 * letzten beiden sind Absender und keine Empfänger — aber eine Kündigung, die
 * niemand liest, ist das schlechtere Ergebnis als eine, die im Postfach des
 * Absenders landet.
 */
final class MerchantAddress
{
    /** @param  'withdrawal'|'cancellation'  $flow */
    public static function for(string $flow): ?string
    {
        foreach ([
            config('statamic-payments.'.$flow.'.notify'),
            data_get(config('statamic-payments.portal.from', []), 'address'),
            config('mail.from.address'),
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }
}
