<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Integrations\InvoiceBridge;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Invoices;
use Goldnead\StatamicPayments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * The bridge to the invoicing addon, tested without the invoicing addon.
 *
 * That is not a shortcut, it is the property under test. The bridge names
 * exactly one class from `goldnead/statamic-invoices` — a facade, as a string —
 * and asks everything after that by method name, because PDF rendering and
 * delivery are being built in that package right now and a bridge written
 * against today's classes would be a bet on unfinished work. A stand-in facade
 * and a handful of anonymous invoices is therefore a complete test of the seam,
 * and it is the only kind that keeps working while the other side moves.
 *
 * The registry around it is tested from the outside in `Feature\Portal\InvoiceSeamTest`.
 * This is the piece underneath it, which that test deliberately does not touch.
 */
class InvoiceBridgeTest extends TestCase
{
    protected Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__.'/../Fakes/invoices-facade.php';

        Invoices::forgetSources();

        $this->payment = Payment::create([
            'provider' => 'fake',
            'provider_id' => 'tr_1',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'anna@example.de',
            'paid_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        \Goldnead\Invoices\Facades\Invoices::$root = null;
        Invoices::forgetSources();

        parent::tearDown();
    }

    /** Bind a writer that hands back the given invoice for any payment. */
    protected function writerReturning(?object $invoice): void
    {
        \Goldnead\Invoices\Facades\Invoices::$root = new class($invoice)
        {
            public int $asked = 0;

            public function __construct(protected ?object $invoice) {}

            public function forPayment(Payment $payment): ?object
            {
                $this->asked++;

                return $this->invoice;
            }
        };
    }

    #[Test]
    public function the_one_class_name_it_couples_by_is_the_one_the_sibling_publishes(): void
    {
        // If the sibling ever renames its facade, this is the line that says so
        // — rather than a download button that silently stops appearing.
        $this->assertSame('\Goldnead\Invoices\Facades\Invoices', InvoiceBridge::FACADE);
        $this->assertTrue(class_exists(InvoiceBridge::FACADE));
    }

    #[Test]
    public function an_invoice_that_can_make_a_pdf_becomes_a_download(): void
    {
        $this->writerReturning(new class
        {
            public string $number = 'RE2026-08-007';

            public string $issued_at = '2026-08-25 10:30:00';

            public function pdf(): string
            {
                return '%PDF-1.4 real';
            }
        });

        $document = (new InvoiceBridge)->forPayment($this->payment);

        $this->assertNotNull($document);
        $this->assertSame('RE2026-08-007', $document->number);
        $this->assertSame('2026-08-25', $document->issuedAt?->toDateString());
        $this->assertSame('application/pdf', $document->contentType);
        $this->assertSame('RE2026-08-007.pdf', $document->filename);
        $this->assertSame('%PDF-1.4 real', $document->bytes());
    }

    #[Test]
    public function the_bytes_are_not_produced_until_somebody_asks_for_them(): void
    {
        $invoice = new class
        {
            public string $number = 'RE1';

            public int $rendered = 0;

            public function pdf(): string
            {
                $this->rendered++;

                return 'bytes';
            }
        };

        $this->writerReturning($invoice);

        $document = (new InvoiceBridge)->forPayment($this->payment);

        // Building the handle must not render. A listing of six orders would
        // otherwise be six PDF renders to decide whether to draw six links.
        $this->assertSame(0, $invoice->rendered);

        $document?->bytes();

        $this->assertSame(1, $invoice->rendered);
    }

    #[Test]
    public function html_is_taken_where_there_is_no_pdf_yet(): void
    {
        // The state the sibling is actually in while its PDF work is unfinished.
        $this->writerReturning(new class
        {
            public string $number = 'RE2';

            public function html(): string
            {
                return '<h1>Rechnung</h1>';
            }
        });

        $document = (new InvoiceBridge)->forPayment($this->payment);

        $this->assertSame('text/html', $document?->contentType);
        $this->assertSame('RE2.html', $document?->filename);
    }

    #[Test]
    public function a_pdf_wins_over_html_where_both_exist(): void
    {
        $this->writerReturning(new class
        {
            public string $number = 'RE3';

            public function pdf(): string
            {
                return 'pdf';
            }

            public function html(): string
            {
                return 'html';
            }
        });

        // A buyer keeps an invoice. Where the sibling can produce both, the one
        // that survives being filed away is the one to hand over.
        $this->assertSame('pdf', (new InvoiceBridge)->forPayment($this->payment)?->bytes());
    }

    #[Test]
    public function an_invoice_that_names_its_own_content_type_is_believed(): void
    {
        $this->writerReturning(new class
        {
            public string $number = 'RE4';

            public function pdf(): string
            {
                return 'x';
            }

            public function contentType(): string
            {
                return 'application/pdf; charset=binary';
            }
        });

        $this->assertSame(
            'application/pdf; charset=binary',
            (new InvoiceBridge)->forPayment($this->payment)?->contentType,
        );
    }

    #[Test]
    public function todays_sibling_produces_nothing_and_that_is_not_an_error(): void
    {
        // An `Invoices\Models\Invoice` as it stands right now: a number, a date,
        // and no way to ask it for a document. The order page shows the order
        // without a download, which is what it also shows for an order whose
        // invoice has not been written.
        $this->writerReturning(new class
        {
            public string $number = 'RE5';

            public string $issued_at = '2026-08-25 10:30:00';
        });

        $this->assertNull((new InvoiceBridge)->forPayment($this->payment));
    }

    #[Test]
    public function a_writer_that_answers_null_is_a_normal_answer(): void
    {
        $this->writerReturning(null);

        $this->assertNull((new InvoiceBridge)->forPayment($this->payment));
    }

    #[Test]
    public function a_writer_that_throws_costs_a_button_and_not_the_page(): void
    {
        \Goldnead\Invoices\Facades\Invoices::$root = new class
        {
            public function forPayment(Payment $payment): never
            {
                // What the real one does with no seller configured, or no brand
                // current on a multi-brand host: it refuses, loudly.
                throw new RuntimeException('no seller details are configured');
            }
        };

        $this->assertNull((new InvoiceBridge)->forPayment($this->payment));
    }

    #[Test]
    public function a_root_without_the_method_is_walked_past(): void
    {
        // An older release of the sibling, or a mid-upgrade container. The probe
        // is on the resolved object and never on the facade, because a facade
        // forwards through `__callStatic` and declares none of what it forwards.
        \Goldnead\Invoices\Facades\Invoices::$root = new class
        {
            public function somethingElse(): void {}
        };

        $this->assertNull((new InvoiceBridge)->forPayment($this->payment));
    }

    #[Test]
    public function an_invoice_number_cannot_smuggle_anything_into_a_header(): void
    {
        $this->writerReturning(new class
        {
            public string $number = 'RE "2026"/08; drop';

            public function pdf(): string
            {
                return 'x';
            }
        });

        $filename = (new InvoiceBridge)->forPayment($this->payment)?->filename;

        // A `Content-Disposition` is not a place to be clever about quoting, so
        // anything outside `[A-Za-z0-9._-]` is dropped rather than escaped.
        $this->assertSame('RE-2026-08-drop.pdf', $filename);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]+$/', (string) $filename);
    }

    #[Test]
    public function an_invoice_with_no_number_is_not_a_document(): void
    {
        $this->writerReturning(new class
        {
            public function pdf(): string
            {
                return 'x';
            }
        });

        $this->assertNull((new InvoiceBridge)->forPayment($this->payment));
    }

    #[Test]
    public function the_bridge_registers_itself_once_and_only_where_the_sibling_is(): void
    {
        // The facade class is loaded by this test's setUp, so a fresh boot now
        // finds it — which is the whole registration condition in the provider.
        Invoices::forgetSources();
        $this->assertFalse(Invoices::available());

        Invoices::extend(new InvoiceBridge);
        Invoices::extend(new InvoiceBridge);

        $this->assertTrue(Invoices::available());

        // Twice registered is once asked. `app->booted()` runs again under
        // Octane and in any test that reboots the container.
        $this->writerReturning(new class
        {
            public string $number = 'RE6';

            public int $rendered = 0;

            public function pdf(): string
            {
                $this->rendered++;

                return 'x';
            }
        });

        $document = Invoices::forPayment($this->payment);

        $this->assertSame('RE6', $document?->number);
    }
}
