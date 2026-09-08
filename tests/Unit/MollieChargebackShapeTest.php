<?php

namespace Goldnead\StatamicPayments\Tests\Unit;

use Goldnead\StatamicPayments\Gateways\MollieGateway;
use Goldnead\StatamicPayments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

/**
 * The one place Mollie's own chargeback shape is decoded.
 *
 * Everything else about the Mollie chargeback path is proved through
 * `FakeGateway::markChargedBack()` — which is a fixture written to the same
 * assumption as the code, and so cannot disagree with it. This is the only test
 * that reads the real shape: `amountChargedBack` as an object carrying `value`
 * and `currency`, exactly as `Mollie\Api\Resources\Payment` declares it.
 *
 * Reflection on the protected method, the same way `MollieStatusTest` reaches
 * `normalise()`. Nothing here touches the network: the method is arithmetic.
 */
class MollieChargebackShapeTest extends TestCase
{
    protected function chargedBackCent(mixed $payment): ?int
    {
        $method = new ReflectionMethod(MollieGateway::class, 'chargedBackCent');

        return $method->invoke(app(MollieGateway::class), $payment);
    }

    #[Test]
    public function mollies_own_amount_object_becomes_minor_units(): void
    {
        $payment = (object) ['amountChargedBack' => (object) ['value' => '19.00', 'currency' => 'EUR']];

        $this->assertSame(1900, $this->chargedBackCent($payment));
    }

    #[Test]
    public function a_payment_mollie_says_nothing_about_is_null_and_not_zero(): void
    {
        // Null and zero are different answers, and the difference decides
        // whether anything is booked at all.
        $this->assertNull($this->chargedBackCent((object) []));
        $this->assertNull($this->chargedBackCent((object) ['amountChargedBack' => null]));
    }

    #[Test]
    public function the_currencys_own_decimals_decide_and_not_a_hundred(): void
    {
        // The yen has no minor unit; a hard-coded 100 would read a ¥1000
        // chargeback as ten. The dinar has three, and would read a tenth.
        $yen = (object) ['amountChargedBack' => (object) ['value' => '1000', 'currency' => 'JPY']];
        $dinar = (object) ['amountChargedBack' => (object) ['value' => '1.000', 'currency' => 'BHD']];

        $this->assertSame(1000, $this->chargedBackCent($yen));
        $this->assertSame(1000, $this->chargedBackCent($dinar));
    }

    #[Test]
    public function a_partial_chargeback_keeps_its_cents(): void
    {
        $payment = (object) ['amountChargedBack' => (object) ['value' => '4.99', 'currency' => 'EUR']];

        $this->assertSame(499, $this->chargedBackCent($payment));
    }
}
