<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\PaymentMethods;
use Goldnead\StatamicPayments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Zahlungsarten: was der Anbieter angeboten bekommt, und wann er gebeten wird,
 * sich den Käufer zu merken.
 */
class PaymentMethodsTest extends TestCase
{
    #[Test]
    public function without_configuration_no_method_is_sent(): void
    {
        app(Checkout::class)->start('noten-paket', ['email' => 'wer@example.com']);

        $this->assertArrayNotHasKey('method', $this->gateway->lastPayload);
    }

    #[Test]
    public function one_method_goes_as_a_string_and_several_as_a_list(): void
    {
        config(['statamic-payments.methods' => ['creditcard']]);
        app(Checkout::class)->start('noten-paket', ['email' => 'wer@example.com']);
        $this->assertSame('creditcard', $this->gateway->lastPayload['method']);

        config(['statamic-payments.methods' => ['ideal', 'Creditcard ', 'paypal', 'ideal']]);
        app(Checkout::class)->start('noten-paket', ['email' => 'wer@example.com']);
        $this->assertSame(['ideal', 'creditcard', 'paypal'], $this->gateway->lastPayload['method']);

        // Aus der Umgebung kommt eine Liste mit Kommas.
        config(['statamic-payments.methods' => 'klarna, banktransfer']);
        app(Checkout::class)->start('noten-paket', ['email' => 'wer@example.com']);
        $this->assertSame(['klarna', 'banktransfer'], $this->gateway->lastPayload['method']);
    }

    #[Test]
    public function the_buyer_is_remembered_only_where_a_method_can_hold_a_mandate(): void
    {
        config([
            'statamic-payments.follow_up.enabled' => true,
            'statamic-payments.follow_up.collect_mandate' => true,
        ]);

        config(['statamic-payments.methods' => ['klarna', 'banktransfer']]);
        app(Checkout::class)->start('noten-paket', ['email' => 'wer@example.com']);
        $this->assertArrayNotHasKey('customerId', $this->gateway->lastPayload);
        $this->assertArrayNotHasKey('sequenceType', $this->gateway->lastPayload);

        config(['statamic-payments.methods' => ['klarna', 'ideal']]);
        app(Checkout::class)->start('noten-paket', ['email' => 'wer@example.com']);
        $this->assertArrayHasKey('customerId', $this->gateway->lastPayload, 'iDEAL kann als erste Zahlung ein SEPA-Mandat hinterlassen');
        $this->assertSame('first', $this->gateway->lastPayload['sequenceType']);

        config(['statamic-payments.methods' => null]);
        app(Checkout::class)->start('noten-paket', ['email' => 'wer@example.com']);
        $this->assertArrayHasKey('customerId', $this->gateway->lastPayload, 'ohne Vorgabe entscheidet Mollie, und das darf ein Mandat sein');
    }

    #[Test]
    public function the_two_lists_say_what_the_readme_says(): void
    {
        foreach (['creditcard', 'directdebit', 'paypal', 'applepay', 'googlepay'] as $method) {
            $this->assertTrue(PaymentMethods::chargesAutomatically($method), $method);
        }

        foreach (['klarna', 'banktransfer', 'ideal', 'twint', 'billie'] as $method) {
            $this->assertFalse(PaymentMethods::chargesAutomatically($method), $method);
        }

        $this->assertTrue(PaymentMethods::canHoldMandate(['ideal']));
        $this->assertFalse(PaymentMethods::canHoldMandate(['klarna']));
        $this->assertTrue(PaymentMethods::canHoldMandate([]));
    }
}
