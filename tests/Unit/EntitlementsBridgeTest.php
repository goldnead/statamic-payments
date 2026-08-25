<?php

namespace Goldnead\StatamicPayments\Tests\Unit;

use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Integrations\EntitlementsBridge;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Tests\Support\StrictEntitlements;
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

    /**
     * The regression that matters, and the one the old tests could not see.
     *
     * A stub that accepts anything proves the bridge made a call. It does not
     * prove the call was accepted, and it was not: the real addon refuses a bare
     * string subject, so every paid order on a real installation logged an error
     * and granted nothing. Found by installing both addons side by side and
     * paying with a real card, not by reading either of them.
     *
     * {@see StrictEntitlements} is
     * as strict as the sibling. If the bridge ever hands over a string again,
     * this fails here rather than in somebody's log.
     */
    #[Test]
    public function the_subject_is_handed_over_in_a_shape_the_sibling_accepts(): void
    {
        config(['statamic-payments.entitlements.enabled' => true]);
        config(['statamic-payments.products' => [
            'noten-paket' => ['name' => 'Noten', 'amount_cent' => 1900, 'grants' => 'noten'],
        ]]);

        $sibling = new StrictEntitlements;

        $this->bindEntitlements($sibling);

        app(EntitlementsBridge::class)->grantFor($this->payment());

        $this->assertCount(1, $sibling->granted, 'nothing was granted, so the sibling refused the subject');
        $this->assertIsNotString($sibling->granted[0]['subject']);
        $this->assertSame('noten', $sibling->granted[0]['slug']);
    }

    /**
     * Bind a stand-in behind the facade name the bridge looks for.
     *
     * The bridge finds the sibling with `class_exists` on the facade and then
     * asks the container for its root, which is exactly the seam a test needs.
     */
    protected function bindEntitlements(object $root): void
    {
        // The sibling is a dev dependency of this package *because of this
        // test*. Without it installed the test would skip, and a skipped test
        // is what let the bug through in the first place.
        $this->assertTrue(
            class_exists('Goldnead\\Entitlements\\Facades\\Entitlements'),
            'the sibling has to be installed for this test to mean anything',
        );

        app()->instance('Goldnead\\Entitlements\\EntitlementManager', $root);
    }
}
