<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every test here TRIES to get something for free.
 *
 * A payment webhook is an unauthenticated endpoint that decides who has paid.
 * Proving the happy path says nothing about that; only an attempt that has to
 * fail does.
 */
class WebhookTrustTest extends TestCase
{
    protected function startPayment(): Payment
    {
        return app(Checkout::class)->start('noten-paket', ['email' => 'kaeufer@example.com'])->payment;
    }

    protected function callWebhook(array $body): TestResponse
    {
        return $this->postJson('/!/statamic-payments/webhook', $body);
    }

    #[Test]
    public function a_forged_paid_claim_does_not_fulfil(): void
    {
        Event::fake([PaymentPaid::class]);

        $payment = $this->startPayment();

        // The whole attack in one line: the caller says paid, the provider says
        // open. Anything that reads the request body would grant access here.
        $this->callWebhook([
            'id' => $payment->provider_id,
            'status' => 'paid',
            'paid' => true,
            'amount' => ['value' => '0.01'],
        ])->assertOk();

        $payment->refresh();

        $this->assertFalse($payment->isFulfilled());
        $this->assertNotSame(Payment::STATUS_PAID, $payment->status);
        Event::assertNotDispatched(PaymentPaid::class);
    }

    #[Test]
    public function the_status_always_comes_from_the_provider(): void
    {
        $payment = $this->startPayment();

        $this->callWebhook(['id' => $payment->provider_id]);

        // Not "it happens to be right" but "it asked". If a future change added
        // a shortcut for some case, this fails.
        $this->assertSame([$payment->provider_id], $this->gateway->fetched);
    }

    #[Test]
    public function an_id_this_site_never_issued_creates_nothing(): void
    {
        $this->gateway->markPaid('tr_fremd');

        $this->callWebhook(['id' => 'tr_fremd'])->assertOk();

        // Even genuinely paid at the provider: an id we did not issue is not
        // evidence of an order here, and creating a row from one would let
        // anybody conjure orders.
        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function the_endpoint_reveals_nothing_about_which_ids_exist(): void
    {
        $payment = $this->startPayment();

        $known = $this->callWebhook(['id' => $payment->provider_id]);
        $unknown = $this->callWebhook(['id' => 'tr_gibt-es-nicht']);

        // Same status, same body. Otherwise the endpoint answers, for anyone
        // who asks, which payment ids this site has seen.
        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->getContent(), $unknown->getContent());
    }

    #[Test]
    public function a_missing_id_is_refused(): void
    {
        $this->callWebhook([])->assertStatus(422);
        $this->callWebhook(['id' => ''])->assertStatus(422);
        $this->callWebhook(['id' => ['nested']])->assertStatus(422);
        $this->callWebhook(['id' => str_repeat('x', 500)])->assertStatus(422);
    }

    #[Test]
    public function the_price_comes_from_the_catalogue_not_the_buyer(): void
    {
        $payment = app(Checkout::class)->start('noten-paket', [
            'email' => 'kaeufer@example.com',
            'amount_cent' => 1,
            'amount' => 1,
            'price' => 1,
        ])->payment;

        // The oldest mistake in online payments: buying a €19 thing for a cent
        // because the amount travelled with the request.
        $this->assertSame(1900, $payment->amount_cent);
    }

    #[Test]
    public function an_unknown_product_starts_no_payment(): void
    {
        $this->assertNull(app(Checkout::class)->start('gibt-es-nicht'));
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, $this->gateway->created);
    }

    #[Test]
    public function a_product_without_a_sane_amount_is_not_a_product(): void
    {
        // Zero is **not** in this list any more, and that is a deliberate
        // change: since free offers exist, an explicit `0` means "this one is
        // free" (see FreeAndDiscountTest). What is still refused is an amount
        // that arrived by accident — a negative number, or a price typed as a
        // string — because those are the shapes a mistake takes, and a mistake
        // must never turn into a giveaway.
        config(['statamic-payments.products' => [
            'auch-kaputt' => ['name' => 'Auch kaputt', 'amount_cent' => -100],
            'ganz-kaputt' => ['name' => 'Ganz kaputt', 'amount_cent' => '19,00'],
            'gar-keiner' => ['name' => 'Ohne Preis'],
        ]]);

        foreach (['auch-kaputt', 'ganz-kaputt', 'gar-keiner'] as $handle) {
            $this->assertNull(app(Checkout::class)->start($handle), $handle.' should not be sellable');
        }

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, $this->gateway->created);
    }

    #[Test]
    public function nothing_ships_that_can_be_bought(): void
    {
        // Same rule as consent's services: an addon that shipped a price would
        // be wrong about every site that installed it.
        $fresh = require __DIR__.'/../../config/statamic-payments.php';

        $this->assertSame([], $fresh['products']);
    }
}
