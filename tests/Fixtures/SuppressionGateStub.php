<?php

namespace Goldnead\Suppression\Facades;

/**
 * Stand-in for statamic-suppression's gate, which is not a dependency of this
 * package. Loaded by a test only when the real class is absent; the shape is
 * the sibling's own (`isSuppressed(string $email, ?int $brandId)`).
 */
class SuppressionGate
{
    /** @var list<string> */
    public static array $suppressed = [];

    public static bool $throws = false;

    public static function isSuppressed(string $email, ?int $brandId = null): bool
    {
        if (self::$throws) {
            throw new \RuntimeException('the suppression list is unreachable');
        }

        return in_array(mb_strtolower(trim($email)), self::$suppressed, true);
    }
}
