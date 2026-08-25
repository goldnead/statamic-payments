<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * More than one thing in one payment.
 *
 * An order bump is a checkbox at checkout: "add the exercise sheets for €9".
 * One payment, two lines. Everything here is about what the buyer is charged
 * versus what the page offered them, because that is where the money goes
 * wrong.
 */
class OrderBumpTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'noten-paket' => ['name' => 'Notenpaket', 'amount_cent' => 1900],
            'uebungsblaetter' => ['name' => 'Übungsblätter', 'amount_cent' => 900],
            'in-dollar' => ['name' => 'Etwas in Dollar', 'amount_cent' => 500, 'currency' => 'USD'],
        ]);
    }

    #[Test]
    public function a_bump_adds_a_line_and_the_total_is_their_sum(): void
    {
        $result = app(Checkout::class)->start(['noten-paket', 'uebungsblaetter']);

        $payment = $result->payment;

        $this->assertSame(2800, $payment->amount_cent);
        $this->assertSame('28.00', $payment->amount());
        $this->assertSame(2, $payment->items()->count());

        // The recomputed sum and the stored total have to agree. Storing the
        // total is what the provider is handed; recomputing it is what makes
        // the two checkable rather than equal by assumption.
        $this->assertSame($payment->amount_cent, $payment->fresh()->itemsTotalCent());
    }

    #[Test]
    public function the_first_line_is_what_the_buyer_came_for(): void
    {
        $payment = app(Checkout::class)->start(['noten-paket', 'uebungsblaetter'])->payment;

        $items = $payment->items()->orderBy('id')->get();

        $this->assertSame(PaymentItem::KIND_PRIMARY, $items[0]->kind);
        $this->assertSame(PaymentItem::KIND_BUMP, $items[1]->kind);

        // And it stays on the payment itself, so grouping a report by product
        // does not mean joining a table.
        $this->assertSame('noten-paket', $payment->product);
    }

    #[Test]
    public function a_single_handle_still_works_and_still_gets_a_line(): void
    {
        // The old call shape. Every site using this addon today passes a
        // string, and none of them should notice this change.
        $payment = app(Checkout::class)->start('noten-paket', ['email' => 'kaeufer@example.com'])->payment;

        $this->assertSame(1900, $payment->amount_cent);
        $this->assertSame(1, $payment->items()->count());
        $this->assertSame(PaymentItem::KIND_PRIMARY, $payment->items()->first()->kind);
    }

    #[Test]
    public function an_unknown_bump_refuses_the_whole_checkout(): void
    {
        // Dropping the unknown line instead would charge the buyer for less
        // than the page offered, and the first anyone hears of it is a customer
        // who paid for two things and received one.
        $this->assertNull(app(Checkout::class)->start(['noten-paket', 'gibt-es-nicht']));
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, PaymentItem::count());
    }

    #[Test]
    public function the_price_of_every_line_comes_from_the_catalogue(): void
    {
        // The oldest mistake in online payments, now with two lines: buying a
        // €28 order for two cents because the amounts travelled with the
        // request.
        $payment = app(Checkout::class)->start(
            ['noten-paket', 'uebungsblaetter'],
            ['email' => 'k@example.com', 'amount_cent' => 1, 'price' => 1, 'amount' => 1],
        )->payment;

        $this->assertSame(2800, $payment->amount_cent);
        $this->assertSame([1900, 900], $payment->items()->orderBy('id')->pluck('amount_cent')->all());
    }

    #[Test]
    public function the_same_handle_twice_is_a_quantity_and_not_two_lines(): void
    {
        $payment = app(Checkout::class)->start(['uebungsblaetter', 'uebungsblaetter'])->payment;

        $this->assertSame(1, $payment->items()->count());
        $this->assertSame(2, $payment->items()->first()->quantity);
        $this->assertSame(1800, $payment->amount_cent);
    }

    #[Test]
    public function quantities_can_be_given_directly(): void
    {
        $payment = app(Checkout::class)->start(['noten-paket' => 1, 'uebungsblaetter' => 3])->payment;

        $this->assertSame(1900 + 2700, $payment->amount_cent);
    }

    #[Test]
    public function a_quantity_below_one_refuses_the_checkout(): void
    {
        // Zero or negative would subtract from the total. A negative line is a
        // discount, and a discount that anybody can post is a free order.
        $this->assertNull(app(Checkout::class)->start(['noten-paket' => 0]));
        $this->assertNull(app(Checkout::class)->start(['noten-paket' => -2]));
        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function two_currencies_in_one_payment_are_refused(): void
    {
        // A provider is handed one amount and one currency. Mixing them is not
        // a rounding problem, it is a wrong charge.
        $this->assertNull(app(Checkout::class)->start(['noten-paket', 'in-dollar']));
        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function the_line_keeps_the_name_it_was_sold_under(): void
    {
        $payment = app(Checkout::class)->start(['noten-paket'])->payment;

        // The product is renamed a year later.
        config(['statamic-payments.products.noten-paket.name' => 'Notenpaket (alt)']);

        $this->assertSame('Notenpaket', $payment->items()->first()->name);
    }

    #[Test]
    public function deleting_a_payment_takes_its_lines_with_it(): void
    {
        $payment = app(Checkout::class)->start(['noten-paket', 'uebungsblaetter'])->payment;

        $payment->delete();

        // Orphaned lines would count towards every revenue report ever run.
        $this->assertSame(0, PaymentItem::count());
    }
}
