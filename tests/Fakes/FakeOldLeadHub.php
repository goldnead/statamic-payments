<?php

namespace Goldnead\StatamicPayments\Tests\Fakes;

/**
 * A CRM old enough to ingest but not to keep a total.
 *
 * The version that shipped before the revenue ledger existed. An install
 * running it must get the half that works and not an error per sale — the same
 * courtesy `EntitlementsBridge` shows a sibling without `renew()`.
 */
class FakeOldLeadHub
{
    public array $ingested = [];

    public function ingest(array $event): ?object
    {
        $this->ingested[] = $event;

        return (object) ['wasRecentlyCreated' => true];
    }
}
