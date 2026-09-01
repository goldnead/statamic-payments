<?php

namespace Goldnead\StatamicPayments\Legal;

use Illuminate\Support\Facades\Log;

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
            'notify' => config('statamic-payments.'.$flow.'.notify'),
            'portal' => data_get(config('statamic-payments.portal.from', []), 'address'),
            'mail' => config('mail.from.address'),
        ] as $source => $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            if ($source === 'mail') {
                // Der Absender der Anwendung ist ein Postfach, aus dem gesendet
                // wird — nicht eines, das jemand liest. Laut, damit das vor der
                // ersten echten Kündigung auffällt und nicht danach.
                Log::warning('statamic-payments: no merchant address for '.$flow.' notifications; falling back to mail.from. Set statamic-payments.'.$flow.'.notify.', [
                    'address' => trim($candidate),
                ]);
            }

            return trim($candidate);
        }

        return null;
    }
}
