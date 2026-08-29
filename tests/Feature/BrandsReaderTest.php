<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Brands;
use Goldnead\StatamicPayments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Whose rows a reader may see.
 *
 * `stampId()` and `readerId()` differ in exactly one place — no brand current —
 * and that one place is where a listing leaks. Zero is a real brand id as far
 * as a `where` clause is concerned: it is every row created where nobody said
 * which tenant. Handing it to `only()` shows those rows instead of showing
 * nothing, on a screen that looks perfectly correct everywhere else.
 */
class BrandsReaderTest extends TestCase
{
    /**
     * Bind a stand-in for statamic-brand-context's manager.
     *
     * No more permissive than the real one: it answers exactly the questions
     * `Brands` asks and nothing else, so a method that invented another would
     * fail here rather than pass against a double that says yes to everything.
     */
    protected function marke(bool $multi = true, ?int $current = 1): void
    {
        $this->app->instance('brand-context', new class($multi, $current)
        {
            public function __construct(
                protected bool $multi,
                protected ?int $current,
            ) {}

            public function multiBrandEnabled(): bool
            {
                return $this->multi;
            }

            public function hasCurrent(): bool
            {
                return $this->current !== null;
            }

            public function currentId(): ?int
            {
                return $this->current;
            }
        });
    }

    protected function zeile(int $brand, string $providerId): Payment
    {
        return Payment::create([
            'product' => 'noten-paket',
            'provider_id' => $providerId,
            'provider' => 'fake',
            'amount_cent' => 1000,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'brand_id' => $brand,
        ]);
    }

    #[Test]
    public function a_reader_in_a_brand_sees_that_brand(): void
    {
        $this->marke(current: 2);

        $this->assertSame(2, Brands::readerId());
    }

    #[Test]
    public function a_reader_with_no_brand_current_sees_nothing_rather_than_the_unassigned_rows(): void
    {
        // The bug this method exists to prevent, written as the assertion it
        // would have failed: `stampId()` answers zero here, and zero is the
        // brand of every row created by a webhook or a console command.
        $this->marke(current: null);

        $this->zeile(0, 'tr_niemandes');
        $this->zeile(1, 'tr_nordlicht');

        $this->assertSame(Brands::NONE, Brands::stampId());
        $this->assertNull(Brands::readerId());

        $sichtbar = Brands::only(Payment::query(), Brands::readerId())->count();
        $this->assertSame(0, $sichtbar);

        // And what the wrong answer would have shown.
        $mitStamp = Brands::only(Payment::query(), Brands::stampId())->count();
        $this->assertSame(1, $mitStamp, 'stampId() shows the unassigned rows — that is why it is not the reader.');
    }

    #[Test]
    public function a_single_brand_install_is_not_filtered_at_all(): void
    {
        // One tenant. Filtering on a column that is zero everywhere would be
        // theatre, and `only()` says so; `readerId()` answers null so that a
        // caller reaching for the value outside `only()` is not told "zero".
        $this->marke(multi: false);

        $this->zeile(0, 'tr_eins');
        $this->zeile(0, 'tr_zwei');

        $this->assertNull(Brands::readerId());
        $this->assertSame(2, Brands::only(Payment::query(), Brands::readerId())->count());
    }

    #[Test]
    public function without_the_sibling_installed_nothing_changes(): void
    {
        $this->zeile(0, 'tr_eins');

        $this->assertFalse($this->app->bound('brand-context'));
        $this->assertNull(Brands::readerId());
        $this->assertSame(1, Brands::only(Payment::query(), Brands::readerId())->count());
    }
}
