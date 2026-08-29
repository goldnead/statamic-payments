<?php

namespace Goldnead\StatamicPayments\Support;

use Closure;
use Illuminate\Support\Carbon;

/**
 * An invoice, as far as this package needs to know about one.
 *
 * A number, a date and a way to get the bytes. Deliberately not a model, not an
 * interface with twenty getters, and above all not a class from another package:
 * `goldnead/statamic-invoices` is being extended with PDF rendering and delivery
 * while this is being written, and anything here that named one of its classes
 * would be a guess about work that is not finished.
 *
 * **The bytes come from a closure, not from a string.** A portal listing five
 * orders would otherwise render five invoices to produce five links. The closure
 * is called by exactly one route — the download — and by nothing else.
 *
 * `contentType` and `filename` travel with the bytes because only the source
 * knows what it produced. HTML today, `application/pdf` the day the sibling can
 * make one, and nothing in this package changes on that day.
 */
final class InvoiceDocument
{
    /**
     * @param  Closure(): string  $contents  the document itself, rendered on demand
     */
    public function __construct(
        public readonly string $number,
        public readonly ?Carbon $issuedAt,
        public readonly Closure $contents,
        public readonly string $filename,
        public readonly string $contentType = 'application/pdf',
    ) {}

    public function bytes(): string
    {
        return ($this->contents)();
    }
}
