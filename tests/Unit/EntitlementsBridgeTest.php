<?php

namespace Goldnead\StatamicPayments\Tests\Unit;

use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Integrations\EntitlementsBridge;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The optional sibling. Almost every assertion here is that nothing happens.
 */
class EntitlementsBridgeTest extends TestCase
{
    protected function payment(): Payment
    {
        return Payment::create([
            'provider' => 'fake',
            'provider_id' => 'tr_1',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'kaeufer@example.com',
        ]);
    }

    #[Test]
    public function it_is_off_without_the_sibling(): void
    {
        config(['statamic-payments.entitlements.enabled' => true]);

        // The sibling is not installed in this suite, so `class_exists` is the
        // thing standing between an enabled flag and a fatal error.
        $this->assertFalse(app(EntitlementsBridge::class)->available());
    }

    #[Test]
    public function it_is_off_by_default_even_where_the_sibling_exists(): void
    {
        $this->assertFalse(config('statamic-payments.entitlements.enabled'));
        $this->assertFalse(app(EntitlementsBridge::class)->available());
    }

    #[Test]
    public function granting_is_a_no_op_when_the_bridge_is_unavailable(): void
    {
        // The listener runs on every paid payment on every site. If it did
        // anything but return here, installing this addon alone would break.
        $payment = $this->payment();

        PaymentPaid::dispatch($payment);

        $this->assertTrue(true);
    }
}
