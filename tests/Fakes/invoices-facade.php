<?php

/**
 * A stand-in for the invoicing addon's facade, declared under its own namespace.
 *
 * `Integrations\InvoiceBridge` couples by one class name and then by method
 * names and nothing else, so this is the whole of what it takes to test it
 * without installing an invoicing addon — which is the point of coupling that
 * way. Loaded by hand from the test that needs it; it is not autoloaded, and it
 * must never exist outside it.
 *
 * **It is at least as strict as the real one.** `Goldnead\Invoices\Facades\Invoices`
 * is a Laravel facade over `InvoiceWriter`, so `getFacadeRoot()` answers with an
 * object that has `forPayment()` on it, and calling an undeclared method on the
 * facade forwards to that object rather than returning null. Both properties are
 * reproduced here: a root that is not set is an error, not a quiet null.
 */

namespace Goldnead\Invoices\Facades;

class Invoices
{
    public static ?object $root = null;

    public static function getFacadeRoot(): ?object
    {
        return static::$root;
    }

    public static function __callStatic(string $method, array $arguments): mixed
    {
        if (static::$root === null) {
            throw new \RuntimeException('The invoices stand-in has no root bound.');
        }

        return static::$root->{$method}(...$arguments);
    }
}
