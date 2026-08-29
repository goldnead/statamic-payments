<?php

namespace Goldnead\StatamicPayments\Portal;

/**
 * Who is looking, and which tenant they came in through.
 *
 * The two travel together and are never available apart. A method that takes
 * only the address would compile, run, and show a buyer every brand's orders on
 * a multi-brand host — which is the failure this type exists to make impossible
 * to write by accident.
 */
final readonly class PortalAccess
{
    public function __construct(
        public string $email,
        public int $brandId,
    ) {}
}
