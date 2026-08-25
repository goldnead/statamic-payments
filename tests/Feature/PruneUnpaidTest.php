<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * Checkouts that were never paid, removed after a while.
 *
 * The reason is not tidiness. A paid order carries a retention obligation; an
 * abandoned checkout carries the opposite — the row holds the name and address
 * of somebody with whom no contract was ever concluded.
 *
 * Every test here is about what must **not** be deleted. Deleting a record is
 * the one operation with no undo, and the failure mode is invisible: nobody
 * notices a payment that is gone.
 */
class PruneUnpaidTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic-payments.prune_unpaid_after_days' => 30]);
    }

    private function zahlung(array $werte = []): Payment
    {
        return Payment::create(array_merge([
            'provider' => 'fake',
            'provider_id' => 'tr_'.bin2hex(random_bytes(4)),
            'product' => 'kurs',
            'amount_cent' => 10000,
            'currency' => 'EUR',
            'status' => Payment::STATUS_OPEN,
            'email' => 'wer@example.com',
            'created_at' => Carbon::now()->subDays(60),
        ], $werte));
    }

    #[Test]
    public function an_old_unpaid_checkout_is_deleted_with_its_lines(): void
    {
        $zahlung = $this->zahlung();
        $zahlung->items()->create([
            'product' => 'kurs', 'name' => 'Kurs', 'amount_cent' => 10000, 'quantity' => 1, 'kind' => 'primary',
        ]);

        $this->artisan('payments:prune-unpaid')->assertSuccessful();

        $this->assertNull(Payment::find($zahlung->id));
        $this->assertSame(0, \DB::table('payment_items')->count(), 'die Positionen blieben zurück');
    }

    #[Test]
    public function a_paid_payment_is_never_touched_however_old(): void
    {
        // Eine bezahlte Bestellung hat eine Aufbewahrungspflicht.
        $zahlung = $this->zahlung([
            'status' => Payment::STATUS_PAID,
            'paid_at' => Carbon::now()->subDays(400),
            'created_at' => Carbon::now()->subDays(400),
        ]);

        $this->artisan('payments:prune-unpaid');

        $this->assertNotNull(Payment::find($zahlung->id));
    }

    #[Test]
    public function a_failed_payment_is_a_record_too(): void
    {
        // Dass jemand es versucht hat und es schiefging, kann später eine
        // Frage sein — eine Rückbuchung, eine Beschwerde.
        foreach ([Payment::STATUS_FAILED, Payment::STATUS_EXPIRED, Payment::STATUS_CANCELED] as $status) {
            $zahlung = $this->zahlung(['status' => $status]);

            $this->artisan('payments:prune-unpaid');

            $this->assertNotNull(Payment::find($zahlung->id), "{$status} wurde gelöscht");
        }
    }

    #[Test]
    public function a_recent_checkout_is_left_alone(): void
    {
        $zahlung = $this->zahlung(['created_at' => Carbon::now()->subDays(5)]);

        $this->artisan('payments:prune-unpaid');

        $this->assertNotNull(Payment::find($zahlung->id));
    }

    #[Test]
    public function a_checkout_inside_a_running_reminder_sequence_survives(): void
    {
        // Eine Automation, deren Auslöser unter ihr verschwindet, scheitert
        // mitten drin — und niemand weiß warum.
        $zahlung = $this->zahlung(['abandoned_notified_at' => Carbon::now()->subDay()]);

        $this->artisan('payments:prune-unpaid');

        $this->assertNotNull(Payment::find($zahlung->id));
    }

    #[Test]
    public function a_fulfilled_checkout_survives_whatever_its_status_says(): void
    {
        // Erfüllt heißt geliefert. Der Status kann aus vielen Gründen hinterher
        // hinken; die Lieferung ist die Tatsache.
        $zahlung = $this->zahlung(['fulfilled_at' => Carbon::now()->subDays(50)]);

        $this->artisan('payments:prune-unpaid');

        $this->assertNotNull(Payment::find($zahlung->id));
    }

    #[Test]
    public function it_does_nothing_at_all_until_a_site_names_a_number(): void
    {
        config(['statamic-payments.prune_unpaid_after_days' => 0]);

        $zahlung = $this->zahlung();

        $this->artisan('payments:prune-unpaid')
            ->expectsOutputToContain('Abgeschaltet')
            ->assertSuccessful();

        $this->assertNotNull(Payment::find($zahlung->id));
    }

    #[Test]
    public function the_dry_run_counts_without_deleting(): void
    {
        $zahlung = $this->zahlung();

        $this->artisan('payments:prune-unpaid', ['--dry-run' => true])
            ->expectsOutputToContain('1 unbezahlte Checkouts wären zu löschen')
            ->assertSuccessful();

        $this->assertNotNull(Payment::find($zahlung->id));
    }
}
