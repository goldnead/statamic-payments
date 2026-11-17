<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Gateways\MollieGateway;
use Goldnead\StatamicPayments\Gateways\StripeGateway;
use Goldnead\StatamicPayments\Support\Fulfilment;
use Goldnead\StatamicPayments\Support\ProviderUnavailable;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mollie\Api\Fake\MockMollieClient;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Ein abgelehnter API-Schluessel ist keine Antwort ueber die Zahlung.
 *
 * Vorher las `Fulfilment::fetch()` einen 401 oder 403 wie einen 404: „unbekannt",
 * Webhook antwortet 200, der Anbieter hoert auf zu wiederholen. Nach einer
 * Schluesselrotation wurde so niemand mehr erfuellt, und die einzige Spur war
 * eine Warnung. Jetzt heisst es „spaeter nochmal" (503), bis der Schluessel
 * wieder stimmt.
 */
class RejectedKeyTest extends TestCase
{
    /** @return array<string, array{0: int}> */
    public static function refusals(): array
    {
        return ['401 unauthorized' => [401], '403 forbidden' => [403]];
    }

    protected function mollie(int $status): MollieGateway
    {
        if (! class_exists(MockMollieClient::class)) {
            $this->markTestSkipped('mollie/mollie-api-php v2 has no mock client');
        }

        return new MollieGateway(new MockMollieClient([
            GetPaymentRequest::class => MockResponse::error($status, 'Unauthorized', 'Invalid API key'),
        ]));
    }

    #[Test]
    #[DataProvider('refusals')]
    public function mollie_refusing_the_key_is_ask_again_later(int $status): void
    {
        $this->expectException(ProviderUnavailable::class);

        $this->mollie($status)->fetch('tr_abc123');
    }

    #[Test]
    #[DataProvider('refusals')]
    public function stripe_refusing_the_key_is_ask_again_later(int $status): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'Invalid API Key provided']], $status)]);

        $this->expectException(ProviderUnavailable::class);

        (new StripeGateway('sk_test_rotated', 'https://api.stripe.com'))->fetch('cs_test_abc');
    }

    #[Test]
    public function fulfilment_does_not_read_a_refused_key_as_an_unknown_payment(): void
    {
        $this->expectException(ProviderUnavailable::class);

        (new Fulfilment($this->mollie(401)))->handle('tr_abc123');
    }

    #[Test]
    public function a_rejected_key_raises_one_critical_per_hour_and_provider(): void
    {
        // Jede Zustellung schreibt ihren Fehler, und in der Flut geht der
        // eine Satz unter, der zaehlt. Einmal je Stunde und Anbieter laut.
        Log::spy();
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'Invalid API Key provided']], 401)]);
        $stripe = new StripeGateway('sk_test_rotated', 'https://api.stripe.com');

        foreach (range(1, 3) as $_) {
            try {
                $stripe->fetch('cs_test_abc');
            } catch (ProviderUnavailable) {
                // erwartet
            }
        }

        foreach (range(1, 2) as $_) {
            try {
                $this->mollie(401)->fetch('tr_abc123');
            } catch (ProviderUnavailable) {
                // erwartet
            }
        }

        Log::shouldHaveReceived('critical')->twice();

        $this->travel(61)->minutes();

        try {
            $stripe->fetch('cs_test_abc');
        } catch (ProviderUnavailable) {
            // erwartet
        }

        Log::shouldHaveReceived('critical')->times(3);
    }

    #[Test]
    public function a_404_stays_an_answer(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'No such checkout.session']], 404)]);

        $this->assertNull((new Fulfilment(new StripeGateway('sk_test_ok', 'https://api.stripe.com')))->handle('cs_test_fremd'));
    }
}
