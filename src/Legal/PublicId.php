<?php

namespace Goldnead\StatamicPayments\Legal;

use Closure;
use RuntimeException;

/**
 * Die Kennung, die ein Verbraucher aus der Eingangsbestätigung abtippt.
 *
 * Acht Zeichen aus einem Alphabet ohne 0, O, 1 und I: was am Telefon
 * vorgelesen oder von einem Ausdruck abgeschrieben wird, darf keine Zeichen
 * enthalten, die in der falschen Schrift gleich aussehen. 32 Zeichen hoch acht
 * sind gut eine Billion Möglichkeiten — nicht geheim wie ein Token, aber zu
 * viele, um sie durchzuprobieren, und mehr braucht eine Kennung nicht, hinter
 * der auf einer öffentlichen Seite nur Kennung und Zeitpunkt stehen.
 */
final class PublicId
{
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const LENGTH = 8;

    /**
     * @param  Closure(string): bool  $taken  Ob es diese Kennung schon gibt.
     */
    public static function make(string $prefix, Closure $taken): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = $prefix.self::random();

            if (! $taken($candidate)) {
                return $candidate;
            }
        }

        // Zehn Kollisionen in Folge bei einer Billion Möglichkeiten sind kein
        // Pech, sondern ein kaputter Zufallsgenerator. Lieber laut scheitern
        // als eine doppelte Kennung ausgeben.
        throw new RuntimeException('statamic-payments: could not find a free public id in ten attempts.');
    }

    private static function random(): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::LENGTH; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }
}
