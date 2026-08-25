<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Discount;
use Goldnead\StatamicPayments\Support\Fulfilment;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * Two things a basket can be that a provider cannot handle: cheaper than its
 * lines, and worth nothing at all.
 *
 * Both are places where the addon's one rule looks like it is bending, so both
 * get tests that press on exactly that: the amount is still never taken from a
 * request, and a free order is still fulfilled exactly once.
 */
class FreeAndDiscountTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'kurs' => ['name' => 'Kurs', 'amount_cent' => 10000],
            'gratis' => ['name' => 'Gratisheft', 'amount_cent' => 0],
        ]);
    }

    protected function checkout(): Checkout
    {
        return app(Checkout::class);
    }

    #[Test]
    public function a_discount_lowers_the_total_and_says_why(): void
    {
        $result = $this->checkout()->start('kurs', [], null, new Discount('FRUEHLING', 2500));

        $payment = $result->payment;

        $this->assertSame(7500, $payment->amount_cent);
        $this->assertSame('FRUEHLING', $payment->discount_code);
        $this->assertSame(2500, $payment->discount_cent);
    }

    #[Test]
    public function a_discount_cannot_make_a_payment_negative(): void
    {
        // A fixed 200 off a 100 basket is a free order, not a refund.
        $result = $this->checkout()->start('kurs', [], null, new Discount('ZUVIEL', 20000));

        $this->assertSame(0, $result->payment->amount_cent);
        $this->assertSame(Payment::STATUS_PAID, $result->payment->status);
    }

    #[Test]
    public function the_lines_still_say_what_things_cost(): void
    {
        $result = $this->checkout()->start('kurs', [], null, new Discount('FRUEHLING', 2500));

        // The discount is on the payment, not smeared across the lines. An old
        // receipt has to keep saying the course was 100, and that 25 came off.
        $this->assertSame(10000, $result->payment->items()->first()->amount_cent);
    }

    #[Test]
    public function a_basket_worth_nothing_never_reaches_the_provider(): void
    {
        $result = $this->checkout()->start('gratis');

        $this->assertNotNull($result);
        $this->assertSame(0, $result->payment->amount_cent);
        $this->assertSame('free', $result->payment->provider);
        // A provider that was never asked cannot have been asked wrongly.
        $this->assertSame(0, $this->gateway->created);
    }

    #[Test]
    public function a_free_order_is_fulfilled_like_any_other(): void
    {
        Event::fake([PaymentPaid::class]);

        $result = $this->checkout()->start('gratis');

        // The same event, so a listener that grants access cannot tell the
        // difference and a free product is not an account with nothing in it.
        Event::assertDispatched(PaymentPaid::class, fn ($e) => $e->payment->is($result->payment));

        $this->assertNotNull($result->payment->fresh()->fulfilled_at);
    }

    #[Test]
    public function a_free_order_is_fulfilled_once_even_if_asked_twice(): void
    {
        Event::fake([PaymentPaid::class]);

        $result = $this->checkout()->start('gratis');

        // Somebody double-clicking the button gets a second payment row, which
        // is correct — but the *first* one must not be delivered twice.
        app(Fulfilment::class)->fulfilFree($result->payment->fresh());

        Event::assertDispatchedTimes(PaymentPaid::class, 1);
    }

    #[Test]
    public function a_discounted_basket_that_is_still_worth_something_goes_to_the_provider(): void
    {
        $result = $this->checkout()->start('kurs', [], null, new Discount('KLEIN', 100));

        $this->assertSame(9900, $result->payment->amount_cent);
        $this->assertNotSame('free', $result->payment->provider);
        $this->assertSame(1, $this->gateway->created);
        // What the provider is asked for is the discounted total, in major
        // units. Sending the gross would charge the buyer the undiscounted
        // price and nobody would notice until a chargeback.
        $this->assertSame('99.00', $this->gateway->lastPayload['amount']['value']);
    }
}
