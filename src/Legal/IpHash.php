<?php

namespace Goldnead\StatamicPayments\Legal;

/**
 * Die Adresse des Absenders, so festgehalten, dass sie nicht mehr lesbar ist.
 *
 * Gesalzen mit dem Anwendungsschlüssel: der IPv4-Raum ist klein genug, dass
 * ein nackter SHA-256 in Minuten zurückgerechnet wäre. Mit dem Schlüssel ist
 * der Hash nur innerhalb dieser Installation vergleichbar — und genau das ist
 * der einzige Zweck: erkennen, ob zwei Erklärungen vom selben Anschluss kamen.
 *
 * Entscheidung 01.09.2026: Hash statt Klartext, von Adrian zu prüfen.
 */
final class IpHash
{
    public static function of(?string $ip): ?string
    {
        if (! is_string($ip) || trim($ip) === '') {
            return null;
        }

        return hash('sha256', trim($ip).'|'.(string) config('app.key', ''));
    }
}
