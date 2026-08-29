<?php

namespace Goldnead\StatamicPayments\Integrations;

use Goldnead\StatamicPayments\Contracts\InvoiceSource;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\InvoiceDocument;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The invoice addon, if it is there — asked by shape, never by type.
 *
 * Registered from the service provider inside an `app->booted()` callback and
 * only when the facade class exists, the same three-guard pattern the insights
 * registration uses and for the same reason: a missing, half-installed or
 * mid-upgrade sibling must cost a download button, never a page and never a
 * checkout.
 *
 * **Nothing in this file type-hints, imports or constructs a class from
 * `goldnead/statamic-invoices`.** One string names its facade so the container
 * can be asked for it; everything after that is `method_exists` on whatever
 * comes back. That is not fastidiousness. PDF rendering and delivery are being
 * built in that package right now, and a bridge written against today's classes
 * would be a bet on work that is not finished — the kind of bet that fails as a
 * fatal error on somebody's live checkout page after a `composer update`.
 *
 * What the bridge asks of the invoice object it is handed, in order:
 * `pdf()`, `toPdf()`, `download()`, `html()`, `render()`. The first one that
 * exists and returns a non-empty string is the document. If the sibling grows
 * any of them, the download appears here with no change to this file. Until
 * then it answers null and the portal shows the order without a download, which
 * is exactly what an order with no invoice looks like anyway.
 *
 * A host that wants a document *now* does not have to wait for any of this:
 *
 *     Invoices::extend(fn (Payment $payment) => new InvoiceDocument(...));
 *
 * registers an answer that outranks nothing and is asked in the same list.
 */
class InvoiceBridge implements InvoiceSource
{
    /** The one string in this file that belongs to another package. */
    public const FACADE = '\Goldnead\Invoices\Facades\Invoices';

    /**
     * Method names that produce a document, and what each one produces.
     *
     * Ordered by preference, not alphabetically: a PDF is what a buyer expects
     * to keep, HTML is what a browser can at least display.
     *
     * @var array<string, string>
     */
    protected const PRODUCERS = [
        'pdf' => 'application/pdf',
        'toPdf' => 'application/pdf',
        'download' => 'application/pdf',
        'html' => 'text/html',
        'render' => 'text/html',
    ];

    public function forPayment(Payment $payment): ?InvoiceDocument
    {
        $invoice = $this->invoiceFor($payment);

        if ($invoice === null) {
            return null;
        }

        $number = $this->readString($invoice, 'number');

        if ($number === null) {
            return null;
        }

        $producer = $this->producerOn($invoice);

        if ($producer === null) {
            return null;
        }

        [$method, $contentType] = $producer;

        return new InvoiceDocument(
            number: $number,
            issuedAt: $this->readDate($invoice, 'issued_at'),
            // Not called here. A listing that renders every invoice to decide
            // whether to show a link would turn a page about six orders into
            // six PDF renders.
            contents: fn (): string => (string) $invoice->{$method}(),
            filename: $this->filenameFor($number, $contentType),
            contentType: $this->contentTypeOn($invoice) ?? $contentType,
        );
    }

    /** @return object|null whatever the sibling calls an invoice */
    protected function invoiceFor(Payment $payment): ?object
    {
        if (! class_exists(self::FACADE)) {
            return null;
        }

        try {
            $facade = self::FACADE;
            $writer = $facade::getFacadeRoot();

            // Asked of the object, never of the facade: a facade forwards
            // through `__callStatic` and declares none of what it forwards, so
            // the probe on the facade itself is always false.
            if (! is_object($writer) || ! method_exists($writer, 'forPayment')) {
                return null;
            }

            $invoice = $writer->forPayment($payment);

            return is_object($invoice) ? $invoice : null;
        } catch (Throwable) {
            // Deliberately silent, and the only silent catch in this class. A
            // sibling that refuses to look up an invoice — no seller configured,
            // no brand current, a migration not run — is a thing the sibling
            // logs about itself. Repeating it once per order row would bury it.
            return null;
        }
    }

    /** @return array{0: string, 1: string}|null the method to call and what it yields */
    protected function producerOn(object $invoice): ?array
    {
        foreach (self::PRODUCERS as $method => $contentType) {
            if (method_exists($invoice, $method)) {
                return [$method, $contentType];
            }
        }

        return null;
    }

    /** Whatever the object says about its own bytes, if it says anything. */
    protected function contentTypeOn(object $invoice): ?string
    {
        foreach (['contentType', 'mimeType'] as $method) {
            if (! method_exists($invoice, $method)) {
                continue;
            }

            try {
                $value = $invoice->{$method}();
            } catch (Throwable) {
                continue;
            }

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function filenameFor(string $number, string $contentType): string
    {
        $extension = str_contains($contentType, 'pdf') ? 'pdf' : 'html';

        // The number is a document identifier and lands in a `Content-Disposition`
        // header. Anything outside this set is dropped rather than escaped: a
        // header is not a place to be clever about quoting.
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $number) ?: 'rechnung';

        return $safe.'.'.$extension;
    }

    protected function readString(object $invoice, string $property): ?string
    {
        $value = $invoice->{$property} ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function readDate(object $invoice, string $property): ?Carbon
    {
        try {
            $value = $invoice->{$property} ?? null;

            return $value === null ? null : Carbon::parse(is_string($value) ? $value : (string) $value);
        } catch (Throwable) {
            return null;
        }
    }
}
