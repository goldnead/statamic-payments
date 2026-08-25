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

    public function grant(
        mixed $subject,
        string $slug,
        string $source = 'manual',
        ?string $sourceRef = null,
        mixed $startsAt = null,
        mixed $expiresAt = null,
    ): object {
        if (is_string($subject)) {
            throw new InvalidArgumentException(
                'Cannot use string as an entitlement subject. Pass an Eloquent model or a SubjectReference.'
            );
        }

        $this->granted[] = compact('subject', 'slug', 'source') + [
            'ref' => $sourceRef,
            'expires' => $expiresAt instanceof \DateTimeInterface ? $expiresAt->format('Y-m-d') : $expiresAt,
        ];

        return (object) ['slug' => $slug];
    }

    /** @var list<array{subject: mixed, slug: string, until: string}> */
    public array $renewed = [];

    /** Grants ohne Ablaufdatum, an denen closeFor() ansetzt. */
    public array $offeneGrants = [];

    public ?object $renewAntwort = null;

    /**
     * Verlaengern, so streng wie das Original.
     *
     * Gibt null zurueck, wenn es nichts zu verlaengern gibt — genau das ist das
     * Signal, auf das die Bruecke mit grant() antworten muss.
     */
    public function renew(mixed $subject, string $slug, mixed $until): ?object
    {
        if (is_string($subject)) {
            throw new InvalidArgumentException(
                'Cannot use string as an entitlement subject. Pass an Eloquent model or a SubjectReference.'
            );
        }

        $this->renewed[] = [
            'subject' => $subject,
            'slug' => $slug,
            'until' => $until instanceof \DateTimeInterface ? $until->format('Y-m-d') : (string) $until,
        ];

        return $this->renewAntwort;
    }

    /** Die Abfrage, die closeFor() benutzt. Liefert einen Bauteil-Doppelgaenger. */
    public function forSubject(mixed $subject): object
    {
        if (is_string($subject)) {
            throw new InvalidArgumentException('Cannot use string as an entitlement subject.');
        }

        return new class($this)
        {
            public function __construct(private $eltern) {}

            public function where(...$a): static
            {
                return $this;
            }

            public function whereNull(...$a): static
            {
                return $this;
            }

            public function orderByDesc(...$a): static
            {
                return $this;
            }

            public function first(): ?object
            {
                return $this->eltern->offeneGrants[0] ?? null;
            }
        };
    }
}
