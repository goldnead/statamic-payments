<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Discount;
use Goldnead\StatamicPayments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The two facts an invoice needs, recorded at the moment they still exist.
 *
 * Neither can be reconstructed later. The VAT rate on a digital sale to a
 * consumer in the EU depends on the buyer's country, and a country not written
 * down at the time of payment is gone — the address may change, the record may
 * be deleted, and "we looked it up afterwards" is not evidence. A discount
 * spread across lines at different rates is the same: once the payment carries
 * a single number, the split is unrecoverable.
 *
 * So these tests are about timing as much as about columns.
 */
class TaxFactsSurviveTheCheckoutTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'noten' => ['name' => 'Notenpaket', 'amount_cent' => 1000],
            'kurs' => ['name' => 'Kurs', 'amount_cent' => 3000],
        ]);
    }

    #[Test]
    public function the_country_the_buyer_named_is_frozen_on_the_payment(): void
    {
        $ergebnis = app(Checkout::class)->start(['noten'], [
            'email' => 'wer@example.com',
            'country' => 'AT',
        ]);

        $this->assertSame('AT', $ergebnis->payment->country);
        $this->assertSame('checkout', $ergebnis->payment->country_source);
    }

    #[Test]
    public function anything_that_is_not_a_country_code_is_dropped_rather_than_stored(): void
    {
        // Eine Spalte, die mal "Deutschland", mal "DE" und mal "de" haelt, ist
        // eine, aus der niemand einen Steuersatz rechnen kann. Und ein falscher
        // Satz ist schlimmer als ein fehlender, weil er wie eine Antwort
        // aussieht.
        foreach (['Deutschland', 'DEU', 'd', '', '12', 'D-'] as $eingabe) {
            $ergebnis = app(Checkout::class)->start(['noten'], ['country' => $eingabe]);

            $this->assertNull(
                $ergebnis->payment->country,
                "[{$eingabe}] haette nicht gespeichert werden duerfen",
            );
        }
    }

    #[Test]
    public function lower_case_is_a_shape_and_not_an_error(): void
    {
        $ergebnis = app(Checkout::class)->start(['noten'], ['country' => 'de']);

        $this->assertSame('DE', $ergebnis->payment->country);
    }

    #[Test]
    public function a_checkout_without_a_country_says_so_instead_of_guessing(): void
    {
        $ergebnis = app(Checkout::class)->start(['noten'], ['email' => 'wer@example.com']);

        $this->assertNull($ergebnis->payment->country);
        $this->assertNull($ergebnis->payment->country_source);
    }

    #[Test]
    public function the_discount_is_recorded_per_line_and_the_lines_add_up(): void
    {
        // Noten 1000, Kurs 3000, zusammen 4000. 400 Rabatt: ein Zehntel.
        $ergebnis = app(Checkout::class)->start(
            ['noten', 'kurs'],
            ['email' => 'wer@example.com'],
            null,
            new Discount('FRUEHLING', 400),
        );

        $zahlung = $ergebnis->payment->fresh(['items']);

        $this->assertSame(400, $zahlung->discount_cent);
        $this->assertSame(
            400,
            $zahlung->items->sum('discount_cent'),
            'die Positionen summieren sich nicht auf den Rabatt der Zahlung',
        );

        $nach = $zahlung->items->keyBy('product');

        $this->assertSame(100, $nach['noten']->discount_cent);
        $this->assertSame(300, $nach['kurs']->discount_cent);
    }

    #[Test]
    public function a_checkout_without_a_discount_leaves_the_lines_at_zero(): void
    {
        $ergebnis = app(Checkout::class)->start(['noten', 'kurs'], ['email' => 'wer@example.com']);

        foreach ($ergebnis->payment->fresh(['items'])->items as $position) {
            $this->assertSame(0, $position->discount_cent);
        }
    }

    #[Test]
    public function an_old_payment_without_these_facts_stays_readable(): void
    {
        // Rueckwaertskompatibel: bestehende Zeilen haben kein Land, und alles
        // dahinter muss das aushalten, statt beim ersten Altbestand umzufallen.
        $alt = Payment::create([
            'provider' => 'fake', 'provider_id' => 'tr_alt', 'product' => 'noten',
            'amount_cent' => 1000, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
        ]);

        $this->assertNull($alt->fresh()->country);
        $this->assertNull($alt->fresh()->country_source);
    }
}
