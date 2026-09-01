<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

/**
 * `payment_items.offer`: über welches Angebot eine Position verkauft wurde.
 */
class OfferOnLinesTest extends TestCase
{
    protected function tearDown(): void
    {
        Catalogue::forgetResolvers();

        parent::tearDown();
    }

    #[Test]
    public function the_caller_names_the_offer_per_product(): void
    {
        app(Checkout::class)->start('noten-paket', ['email' => 'wer@example.com'], null, null, [
            'offer_handles' => ['noten-paket' => 'fruehlings-aktion'],
        ]);

        $this->assertSame('fruehlings-aktion', Payment::first()->items->first()->offer);
    }

    #[Test]
    public function without_a_caller_the_catalogue_entry_may_carry_it_and_otherwise_it_stays_null(): void
    {
        Catalogue::extend(fn (string $handle) => $handle === 'offer:upsell'
            ? ['name' => 'Upsell', 'amount_cent' => 4900, 'offer' => 'upsell', 'product' => 'noten-paket']
            : null);

        app(Checkout::class)->start(['noten-paket', 'offer:upsell'], ['email' => 'wer@example.com']);

        $items = Payment::first()->items;

        $this->assertNull($items[0]->offer);
        $this->assertSame('upsell', $items[1]->offer);
    }

    #[Test]
    public function a_malformed_map_is_refused_before_anything_is_written(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            app(Checkout::class)->start('noten-paket', ['email' => 'wer@example.com'], null, null, [
                'offer_handles' => ['noten-paket' => 12],
            ]);
        } finally {
            $this->assertSame(0, Payment::count());
        }
    }
}
