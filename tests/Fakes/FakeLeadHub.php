<?php

namespace Goldnead\StatamicPayments\Tests\Fakes;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * The object behind the stand-in facade.
 *
 * Every rule here is one the real CRM enforces, or one it silently forgives in
 * a way that would hide a bug. Nothing is looser than the original.
 */
class FakeLeadHub
{
    /** Exactly what `SourceEvent::fromArray()` reads. Anything else is a lost value. */
    public const INGEST_KEYS = [
        'email', 'type', 'summary', 'source_type', 'source_id', 'dedupe_key',
        'occurred_at', 'payload', 'contact', 'tags', 'source', 'phone',
        'default_status', 'attribution',
    ];

    /** @var array<int, array<string, mixed>> */
    public array $ingested = [];

    /** @var array<string, array<string, mixed>> */
    public array $revenue = [];

    /** Emails this CRM knows. The bridge must create through ingest, not assume. */
    public array $contacts = [];

    public bool $knowsRevenue = true;

    public function ingest(array $event): ?object
    {
        foreach (array_keys($event) as $key) {
            if (! in_array($key, self::INGEST_KEYS, true)) {
                throw new InvalidArgumentException("LeadHub would silently drop the key [{$key}].");
            }
        }

        $email = $event['email'] ?? null;

        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        if (! isset($event['type']) || ! is_string($event['type']) || $event['type'] === '') {
            throw new InvalidArgumentException('An ingested event needs a type.');
        }

        if (! isset($event['dedupe_key']) || ! is_string($event['dedupe_key'])) {
            throw new InvalidArgumentException('An ingested event needs a dedupe key or it cannot be idempotent.');
        }

        // `SourceEvent::$occurredAt` is typed `?DateTimeInterface`; anything
        // else is a TypeError in production and must not pass here either.
        if (isset($event['occurred_at']) && ! $event['occurred_at'] instanceof DateTimeInterface) {
            throw new InvalidArgumentException('`occurred_at` has to be a DateTimeInterface or absent.');
        }

        foreach ($this->ingested as $existing) {
            if ($existing['dedupe_key'] === $event['dedupe_key']) {
                return (object) ['wasRecentlyCreated' => false];
            }
        }

        // Resolve or create, like the real resolver.
        $this->contacts[mb_strtolower(trim($email))] = true;
        $this->ingested[] = $event;

        return (object) ['wasRecentlyCreated' => true];
    }

    public function recordRevenue(
        string $email,
        string $reference,
        int $amountCent,
        string $currency = 'EUR',
        ?DateTimeInterface $occurredAt = null,
        ?string $source = null,
        array $meta = [],
    ): ?array {
        if ($reference === '') {
            throw new InvalidArgumentException('A revenue entry needs a reference.');
        }

        if ($amountCent < 0) {
            throw new InvalidArgumentException('A revenue entry cannot be negative.');
        }

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('A revenue entry needs an ISO 4217 currency, got: '.$currency);
        }

        // Never creates a contact. The real one does not either.
        if (! isset($this->contacts[mb_strtolower(trim($email))])) {
            return null;
        }

        if (isset($this->revenue[$reference])) {
            return ['reference' => $reference];
        }

        $this->revenue[$reference] = [
            'email' => $email,
            'amount_cent' => $amountCent,
            'refunded_cent' => 0,
            'currency' => $currency,
            'occurred_at' => $occurredAt,
            'source' => $source,
            'meta' => $meta,
        ];

        return ['reference' => $reference];
    }

    public function refundRevenue(string $reference, int $refundedCent): ?array
    {
        if (! isset($this->revenue[$reference])) {
            return null;
        }

        $this->revenue[$reference]['refunded_cent'] = $refundedCent;

        return ['reference' => $reference];
    }
}
