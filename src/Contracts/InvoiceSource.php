<?php

namespace Goldnead\StatamicPayments\Contracts;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\InvoiceDocument;
use Goldnead\StatamicPayments\Support\Invoices;

/**
 * Where a buyer's invoice comes from.
 *
 * This package takes money; it does not write invoices, and it must not have to
 * be installed with something that does. So the customer portal asks this
 * question of whoever is willing to answer it, and shows the order without a
 * download when nobody is.
 *
 * The contract is the seam, not the class behind it. `goldnead/statamic-invoices`
 * is the obvious answer on Adrian's own sites, and it is deliberately not named
 * in any type hint here — a second invoicing addon, a host with its own
 * accounting system, or a stub in a test all satisfy this the same way.
 *
 * **Returning null is a normal answer**, not an error: an order can legitimately
 * have no invoice yet, and the portal renders that as an order without a
 * download button. An implementation that cannot answer should return null
 * rather than throw; the registry catches throws as well, but a source that
 * relies on being caught is one that will one day be caught silently.
 *
 * @see Invoices  the registry that collects these
 */
interface InvoiceSource
{
    public function forPayment(Payment $payment): ?InvoiceDocument;
}
