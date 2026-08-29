<?php

namespace Goldnead\StatamicPayments\Tests\Support;

use Goldnead\StatamicPayments\Contracts\InvoiceSource;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\InvoiceDocument;
use RuntimeException;

/**
 * Stands in for an invoicing addon, and is as strict as one has to be.
 *
 * A double that answered for any payment would prove only that the seam is
 * called. What has to be proved is that the seam is called **with the right
 * payment** — the portal looks a row up by id out of a URL, and the whole
 * question is whether the row it hands on is the one the visitor is allowed to
 * have.
 *
 * So: it knows exactly one payment, by primary key, and it refuses anything
 * else. Not by returning null, which the contract allows and which would hide a
 * mix-up as "no invoice yet", but by throwing — a test that hands this the wrong
 * order fails loudly instead of rendering a page with a missing button.
 *
 * It also refuses an unpaid payment, because a real invoicing addon does: there
 * is no document for money that never arrived.
 */
class StrictInvoiceSource implements InvoiceSource
{
    public int $asked = 0;

    /** @var list<int> */
    public array $askedAbout = [];

    public function __construct(
        protected int $paymentId,
        public string $number = 'RE2026-08-001',
        public string $body = '%PDF-1.4 fake',
        public string $contentType = 'application/pdf',
    ) {}

    public function forPayment(Payment $payment): ?InvoiceDocument
    {
        $this->asked++;
        $this->askedAbout[] = (int) $payment->getKey();

        if (! $payment->isPaid()) {
            throw new RuntimeException('asked for an invoice for a payment that was never paid');
        }

        if ((int) $payment->getKey() !== $this->paymentId) {
            throw new RuntimeException(
                'asked for the invoice of payment '.$payment->getKey().', which this source knows nothing about'
            );
        }

        return new InvoiceDocument(
            number: $this->number,
            issuedAt: $payment->paid_at,
            contents: fn (): string => $this->body,
            filename: $this->number.'.pdf',
            contentType: $this->contentType,
        );
    }
}
