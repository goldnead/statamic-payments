<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Gateways\StripeGateway;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Ein Abo, das einem anderen Kunden gehoert, wird nicht angefasst.
 *
 * Stripes Abo-Endpunkte erzwingen die Zugehoerigkeit nicht selbst; das macht
 * `guardOwnership()` vor jeder schreibenden Anfrage. Die Kennung allein darf
 * nicht reichen, sonst kann ein verwechselter Wert in fremde Konten greifen.
 */
class StripeFremdesAboTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function stripe(): StripeGateway
    {
        return new StripeGateway('sk_test_notARealKey', 'https://api.stripe.com');
    }

    /** @return array<string, mixed> */
    protected function subscription(string $customer, string $status = 'active'): array
    {
        return [
            'id' => 'sub_fremd_1',
            'object' => 'subscription',
            'status' => $status,
            'customer' => $customer,
            'current_period_end' => 1789000000,
            'metadata' => ['product' => 'monatsabo'],
        ];
    }

    #[Test]
    public function a_subscription_of_another_customer_is_refused_before_anything_is_cancelled(): void
    {
        Http::fake([
            'api.stripe.com/v1/subscriptions/sub_fremd_1' => Http::response($this->subscription('cus_ANDERER')),
        ]);

        try {
            $this->stripe()->cancelSubscription('cus_TestBuyer', 'sub_fremd_1');
            $this->fail('Ein fremdes Abo darf nicht gekuendigt werden.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not belong to [cus_TestBuyer]', $e->getMessage());
        }

        // Die Pruefung ist eine Lesung. Geschrieben wurde nichts.
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_subscription_of_the_same_customer_is_cancelled_as_usual(): void
    {
        // Gegenprobe: die Pruefung darf nicht jedes Abo abweisen.
        Http::fake([
            'api.stripe.com/v1/subscriptions/sub_fremd_1' => Http::response($this->subscription('cus_TestBuyer', 'canceled')),
        ]);

        $remote = $this->stripe()->cancelSubscription('cus_TestBuyer', 'sub_fremd_1');

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_contains($r->url(), '/v1/subscriptions/sub_fremd_1'));
        $this->assertSame('sub_fremd_1', $remote->providerId);
    }
}
