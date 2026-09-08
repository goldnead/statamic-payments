<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Events\PaymentRefunded;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Refunds;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * Money that went back.
 *
 * An amount and a time, never a status: an order half repaid is still a paid
 * order — the money moved and the thing was delivered — and a status forced to
 * choose would be wrong about the other half. Partial refunds are the whole
 * reason this is not a status.
 */
class RefundsTest extends TestCase
{
    private function zahlung(): Payment
    {
        return Payment::create([
            'provider' => 'fake', 'provider_id' => 'tr_1', 'product' => 'kurs',
            'amount_cent' => 10000, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
            'email' => 'wer@example.com', 'fulfilled_at' => now(),
        ]);
    }

    #[Test]
    public function a_partial_refund_leaves_the_payment_paid(): void
    {
        Event::fake([PaymentRefunded::class]);

        $zahlung = $this->zahlung();

        $this->assertTrue(app(Refunds::class)->record($zahlung, 3000, 're_1'));

        $frisch = $zahlung->fresh();

        $this->assertSame(3000, $frisch->refunded_cent);
        $this->assertSame(Payment::STATUS_PAID, $frisch->status);
        $this->assertNotNull($frisch->refunded_at);

        Event::assertDispatched(PaymentRefunded::class, fn (PaymentRefunded $e) => $e->amountCent === 3000 && $e->isFull === false);
    }

    #[Test]
    public function refunds_add_up_and_the_last_one_makes_it_full(): void
    {
        Event::fake([PaymentRefunded::class]);

        $zahlung = $this->zahlung();
        $dienst = app(Refunds::class);

        $dienst->record($zahlung, 4000, 're_1');
        $dienst->record($zahlung->fresh(), 6000, 're_2');

        $this->assertSame(10000, $zahlung->fresh()->refunded_cent);

        Event::assertDispatched(PaymentRefunded::class, fn (PaymentRefunded $e) => $e->isFull === true);
    }

    #[Test]
    public function the_same_refund_announced_twice_is_booked_once(): void
    {
        // „Der Kunde wurde dreimal erstattet" ist die Art Zahl, die in einer
        // Jahresmeldung landet.
        Event::fake([PaymentRefunded::class]);

        $zahlung = $this->zahlung();
        $dienst = app(Refunds::class);

        $this->assertTrue($dienst->record($zahlung, 3000, 're_1'));
        $this->assertFalse($dienst->record($zahlung->fresh(), 3000, 're_1'));

        $this->assertSame(3000, $zahlung->fresh()->refunded_cent);
        Event::assertDispatchedTimes(PaymentRefunded::class, 1);
    }

    #[Test]
    public function never_more_goes_back_than_came_in(): void
    {
        // Eine Bestellung mit negativem Erlös verfälscht still jede Auswertung.
        $zahlung = $this->zahlung();

        app(Refunds::class)->record($zahlung, 25000, 're_1');

        $this->assertSame(10000, $zahlung->fresh()->refunded_cent);
    }

    #[Test]
    public function a_fully_refunded_payment_takes_nothing_more(): void
    {
        $zahlung = $this->zahlung();
        $dienst = app(Refunds::class);

        $dienst->record($zahlung, 10000, 're_1');

        $this->assertFalse($dienst->record($zahlung->fresh(), 1, 're_2'));
        $this->assertSame(10000, $zahlung->fresh()->refunded_cent);
    }

    /**
     * Zwei Meldungen, die beide eine veraltete Zeile in der Hand halten.
     *
     * Das ist die Nebenlaeufigkeit, die mit Stripe erst entsteht: zwei
     * Teilerstattungen auf derselben Belastung kommen als zwei Ereignisse und
     * koennen in zwei Prozessen parallel landen. Beide lasen frueher ihren
     * eigenen, vor dem Commit des anderen geladenen Stand und speicherten blind
     * darueber — der erste Betrag verschwand, seine Referenz aus `meta`, und
     * die naechste Meldung buchte ihn ein zweites Mal.
     *
     * Zwei Instanzen derselben Zeile ohne `fresh()` dazwischen sind genau
     * dieser Zustand, in einem Prozess nachgestellt.
     */
    #[Test]
    public function two_stale_copies_of_the_same_payment_cannot_overwrite_each_other(): void
    {
        $zahlung = $this->zahlung();
        $dienst = app(Refunds::class);

        $veraltet = Payment::find($zahlung->getKey());

        $this->assertTrue($dienst->record($zahlung, 5000, 're_1'));
        // Diese Instanz weiss noch nichts von den 5000 oben.
        $this->assertSame(0, $veraltet->refunded_cent);
        $this->assertTrue($dienst->record($veraltet, 4000, 're_2'));

        $frisch = $zahlung->fresh();

        // 9000, nicht 4000: die zweite Buchung hat die erste nicht ueberschrieben.
        $this->assertSame(9000, $frisch->refunded_cent);
        $this->assertSame(['re_1', 're_2'], $frisch->meta['refunds']);

        // Und `re_1` gilt weiter als gebucht, auch von einer veralteten
        // Instanz aus gefragt. Sonst kaeme es beim naechsten Ereignis, das die
        // ganze Liste der Belastung mitbringt, ein zweites Mal.
        $this->assertFalse($dienst->record($veraltet, 5000, 're_1'));
        $this->assertSame(9000, $zahlung->fresh()->refunded_cent);
    }

    #[Test]
    public function nothing_and_negative_amounts_are_refused(): void
    {
        $zahlung = $this->zahlung();
        $dienst = app(Refunds::class);

        $this->assertFalse($dienst->record($zahlung, 0));
        $this->assertFalse($dienst->record($zahlung, -500));
        $this->assertSame(0, $zahlung->fresh()->refunded_cent);
    }
}
