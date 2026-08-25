<?php

namespace Goldnead\StatamicPayments\Tests\Support;

use InvalidArgumentException;

/**
 * A stand-in for the entitlements addon that is as strict as the real one.
 *
 * The point of this class is the `InvalidArgumentException`. The bridge's
 * earlier tests bound a stub that accepted anything, so they proved the bridge
 * *called* the sibling and nothing about whether the call was accepted. It was
 * not: the real addon refuses a bare string subject, and every paid order on a
 * real installation logged an error and granted nothing. Built, wired,
 * documented, never once working.
 *
 * So this fake refuses exactly what the real one refuses. A mock that says yes
 * to everything is a mock that tests nothing.
 */
class StrictEntitlements
{
    /** @var list<array{subject: mixed, slug: string, source: string, ref: ?string}> */
    public array $granted = [];

    public function grant(mixed $subject, string $slug, string $source = 'manual', ?string $sourceRef = null): object
    {
        if (is_string($subject)) {
            throw new InvalidArgumentException(
                'Cannot use string as an entitlement subject. Pass an Eloquent model or a SubjectReference.'
            );
        }

        $this->granted[] = compact('subject', 'slug', 'source') + ['ref' => $sourceRef];

        return (object) ['slug' => $slug];
    }
}
