<?php

namespace Goldnead\StatamicPayments\Tests\Unit;

use Goldnead\StatamicPayments\Gateways\MollieGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

/**
 * The one line in this package that decides what "paid" means.
 *
 * It needs no network — it is a pure mapping — and it was the only class here
 * touching real money that had no test at all.
 */
class MollieStatusTest extends TestCase
{
    protected function normalise(string $status): string
    {
        $method = new ReflectionMethod(MollieGateway::class, 'normalise');

        return $method->invoke(app(MollieGateway::class), $status);
    }

    #[Test]
    public function only_paid_means_paid(): void
    {
        // Mollie's full vocabulary. Everything except one word must fail to
        // grant, and `authorized` is the trap: it sounds like money arrived and
        // means the money has only been reserved.
        $this->assertSame(Payment::STATUS_PAID, $this->normalise('paid'));

        foreach (['open', 'pending', 'authorized', 'failed', 'expired', 'canceled'] as $status) {
            $this->assertNotSame(Payment::STATUS_PAID, $this->normalise($status), $status.' must not read as paid');
        }
    }

    #[Test]
    public function the_whole_vocabulary_maps_where_it_should(): void
    {
        $this->assertSame(Payment::STATUS_OPEN, $this->normalise('open'));
        $this->assertSame(Payment::STATUS_OPEN, $this->normalise('pending'));
        $this->assertSame(Payment::STATUS_OPEN, $this->normalise('authorized'));
        $this->assertSame(Payment::STATUS_FAILED, $this->normalise('failed'));
        $this->assertSame(Payment::STATUS_EXPIRED, $this->normalise('expired'));
        $this->assertSame(Payment::STATUS_CANCELED, $this->normalise('canceled'));
        $this->assertSame(Payment::STATUS_CANCELED, $this->normalise('cancelled'));
    }

    #[Test]
    public function a_status_this_package_has_not_met_is_open_and_is_reported(): void
    {
        Log::spy();

        // Safe in the granting direction, but afterwards indistinguishable from
        // a real `open`. Without the log line, the provider could add a status
        // and change what this package does with no trace anywhere.
        $this->assertSame(Payment::STATUS_OPEN, $this->normalise('chargebacked'));

        Log::shouldHaveReceived('warning')->once();
    }
}
