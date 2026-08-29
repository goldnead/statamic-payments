<?php

namespace Goldnead\StatamicPayments\Tests\Feature\Portal;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Portal\Mail\PortalLinkMail;
use Goldnead\StatamicPayments\Support\Invoices;
use Goldnead\StatamicPayments\Tests\Support\StrictInvoiceSource;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * The invoice arrives through a seam, or it does not arrive.
 *
 * The point of the seam is what happens when the other side is missing, and
 * that is the first test here rather than the last: `statamic-payments` must
 * install and run on a site with no invoicing addon at all, and the order page
 * must show an order rather than an error.
 *
 * The stand-in is deliberately unforgiving. A double that answered for any
 * payment would prove that the seam is called; what has to be proved is that it
 * is called with the row the visitor is allowed to have, because the portal
 * looks that row up by an id out of a URL.
 */
class InvoiceSeamTest extends TestCase
{
    protected Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // The registry is static, so a source left behind by one test would be
        // asked in the next one. Cleared both ways round, like `Catalogue`.
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

        $this->signIn('anna@example.de');
    }

    protected function tearDown(): void
    {
        Invoices::forgetSources();

        parent::tearDown();
    }

    #[Test]
    public function with_no_invoicing_addon_the_order_shows_without_a_download(): void
    {
        $this->assertFalse(Invoices::available());

        $this->get(route('statamic-payments.portal.order', ['payOrder' => $this->payment->getKey()]))
            ->assertOk()
            ->assertSee(__('statamic-payments::portal.order_invoice_none'))
            ->assertDontSee(route('statamic-payments.portal.invoice', ['payOrder' => $this->payment->getKey()]));

        // And the route itself is not a way in either.
        $this->get(route('statamic-payments.portal.invoice', ['payOrder' => $this->payment->getKey()]))
            ->assertNotFound();
    }

    #[Test]
    public function a_registered_source_puts_the_document_in_the_buyers_hands(): void
    {
        $source = new StrictInvoiceSource($this->payment->getKey());
        Invoices::extend($source);

        $this->get(route('statamic-payments.portal.order', ['payOrder' => $this->payment->getKey()]))
            ->assertOk()
            ->assertSee('RE2026-08-001');

        $response = $this->get(route('statamic-payments.portal.invoice', ['payOrder' => $this->payment->getKey()]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="RE2026-08-001.pdf"');

        $this->assertSame('%PDF-1.4 fake', $response->getContent());

        // Asked about this order and no other. The strict source would have
        // thrown on anything else, so this is belt and braces — and it is the
        // assertion that would fail if the controller ever looked the row up
        // itself instead of going through `Portal\Orders`.
        $this->assertSame([$this->payment->getKey(), $this->payment->getKey()], $source->askedAbout);
    }

    #[Test]
    public function the_document_is_not_rendered_while_a_listing_is_drawn(): void
    {
        $source = new StrictInvoiceSource($this->payment->getKey());
        Invoices::extend($source);

        $this->get(route('statamic-payments.portal.show'))->assertOk();

        // A page listing six orders must not render six invoices to decide
        // whether to show six links.
        $this->assertSame(0, $source->asked);
    }

    #[Test]
    public function a_source_that_throws_costs_a_button_and_not_the_page(): void
    {
        Invoices::extend(function (Payment $payment): never {
            throw new RuntimeException('the invoicing addon is mid-upgrade');
        });

        $this->get(route('statamic-payments.portal.order', ['payOrder' => $this->payment->getKey()]))
            ->assertOk()
            ->assertSee(__('statamic-payments::portal.order_invoice_none'));

        $this->get(route('statamic-payments.portal.invoice', ['payOrder' => $this->payment->getKey()]))
            ->assertNotFound();
    }

    #[Test]
    public function a_source_that_has_nothing_yet_is_a_normal_answer(): void
    {
        // An order whose invoice has not been written is not an error, and the
        // page has to be able to say so without anybody catching anything.
        Invoices::extend(fn (Payment $payment) => null);

        $this->get(route('statamic-payments.portal.order', ['payOrder' => $this->payment->getKey()]))
            ->assertOk()
            ->assertSee(__('statamic-payments::portal.order_invoice_none'));
    }

    #[Test]
    public function a_buyer_cannot_download_somebody_elses_invoice(): void
    {
        $someoneElse = Payment::create([
            'provider' => 'fake',
            'provider_id' => 'tr_2',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'boris@example.de',
            'paid_at' => now(),
        ]);

        $source = new StrictInvoiceSource($someoneElse->getKey());
        Invoices::extend($source);

        $this->get(route('statamic-payments.portal.invoice', ['payOrder' => $someoneElse->getKey()]))
            ->assertNotFound();

        // The seam was never reached. A source that is asked about a stranger's
        // order has already been handed the row, which is the disclosure.
        $this->assertSame(0, $source->asked);
    }

    #[Test]
    public function an_unpaid_checkout_is_not_an_order_and_has_no_invoice(): void
    {
        $abandoned = Payment::create([
            'provider' => 'fake',
            'provider_id' => 'tr_3',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_OPEN,
            'email' => 'anna@example.de',
        ]);

        Invoices::extend(new StrictInvoiceSource($abandoned->getKey()));

        // The strict source throws when asked about an unpaid payment, exactly
        // as a real invoicing addon refuses to invoice money that never arrived.
        // Reaching it at all would be the bug; the row is not an order.
        $this->get(route('statamic-payments.portal.order', ['payOrder' => $abandoned->getKey()]))
            ->assertNotFound();
    }

    protected function signIn(string $email): void
    {
        Mail::fake();

        $this->post(route('statamic-payments.portal.request.send'), ['email' => $email]);

        $url = null;

        Mail::assertSent(PortalLinkMail::class, function (PortalLinkMail $mail) use (&$url) {
            $url ??= $mail->url;

            return true;
        });

        $this->get((string) $url)->assertRedirect(route('statamic-payments.portal.show'));
    }
}
