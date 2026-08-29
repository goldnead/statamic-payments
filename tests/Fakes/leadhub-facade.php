<?php

/**
 * A stand-in for the CRM's facade, declared under the sibling's own namespace.
 *
 * The bridge couples by class name and nothing else, so this is the whole of
 * what it takes to test it without installing a CRM — which is the point of
 * coupling that way. Loaded by hand from the tests that need it; it is not
 * autoloaded, and it must never exist outside them.
 *
 * **It is stricter than the real one, on purpose.** `SourceEvent::fromArray()`
 * ignores a key it does not know, so a bridge that sent `utm_campaign` instead
 * of `attribution` would pass every green test and lose the campaign in
 * production — the exact failure this whole feature exists to prevent. Here an
 * unknown key is an error, and the shape is checked rather than assumed.
 */

namespace Goldnead\Leadhub\Facades;

class LeadHub
{
    public static ?object $root = null;

    public static function getFacadeRoot(): ?object
    {
        return static::$root;
    }

    public static function __callStatic(string $method, array $arguments): mixed
    {
        if (static::$root === null) {
            throw new \RuntimeException('The LeadHub stand-in has no root bound.');
        }

        return static::$root->{$method}(...$arguments);
    }
}
