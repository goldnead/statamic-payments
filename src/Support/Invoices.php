<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Contracts\InvoiceSource;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Who can produce the invoice for a payment.
 *
 * The same shape as {@see Catalogue}, for the same reason: another addon
 * contributes an answer, and this package neither knows nor names which one.
 * `statamic-invoices` registers itself through the bridge in
 * `Integrations\InvoiceBridge`; a host with its own accounting system registers
 * a closure from a service provider and gets the same download button.
 *
 * **Nothing here throws.** A source that breaks costs a download link on a page
 * about somebody's order history. It must never cost the page, and it must
 * certainly never cost a checkout — the same rule the insights registration
 * follows for the same reason.
 *
 * First answer wins. Sources are asked in registration order, and the ordinary
 * install has exactly one.
 */
class Invoices
{
    /**
     * @var list<callable(Payment): (InvoiceDocument|null)>
     */
    protected static array $sources = [];

    /**
     * Classes already registered, so a second boot does not register twice.
     *
     * Keyed by class name and only for `InvoiceSource` objects: a closure has no
     * identity worth comparing, and a host that registers two of them means two.
     * Under Octane, and in a test suite that reboots the container without
     * clearing this static, `app->booted()` runs again and the bridge would
     * otherwise be asked twice per order.
     *
     * @var array<class-string, true>
     */
    protected static array $registered = [];

    /**
     * @param  InvoiceSource|callable(Payment): (InvoiceDocument|null)  $source
     */
    public static function extend(InvoiceSource|callable $source): void
    {
        if ($source instanceof InvoiceSource) {
            if (isset(static::$registered[$source::class])) {
                return;
            }

            static::$registered[$source::class] = true;
            static::$sources[] = fn (Payment $payment) => $source->forPayment($payment);

            return;
        }

        static::$sources[] = $source;
    }

    /** For tests, and for a host that rebuilds its container between requests. */
    public static function forgetSources(): void
    {
        static::$sources = [];
        static::$registered = [];
    }

    /**
     * Whether anybody at all can answer.
     *
     * Cheap on purpose: it is asked once per row of a listing, and asking it
     * properly would mean rendering every invoice to find out whether one
     * exists. What it answers is "is an invoicing addon wired up", not "does
     * this order have an invoice" — the second question is
     * {@see self::forPayment()}, and the portal asks it only on the order's own
     * page, where one extra lookup is affordable.
     */
    public static function available(): bool
    {
        return static::$sources !== [];
    }

    public static function forPayment(Payment $payment): ?InvoiceDocument
    {
        foreach (static::$sources as $source) {
            try {
                $document = $source($payment);
            } catch (Throwable $e) {
                Log::warning('statamic-payments: an invoice source threw; the order is shown without a download.', [
                    'payment_id' => $payment->getKey(),
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            if ($document instanceof InvoiceDocument) {
                return $document;
            }
        }

        return null;
    }
}
