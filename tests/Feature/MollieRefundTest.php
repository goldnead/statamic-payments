<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Events\PaymentRefunded;
use Goldnead\StatamicPayments\Gateways\MollieGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Fulfilment;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Mollie\Api\Fake\MockMollieClient;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\GetPaginatedPaymentRefundsRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use PHPUnit\Framework\Attributes\Test;

/**
 * Erstattungen bei Mollie.
 *
 * Mollie meldet eine Erstattung nicht als eigenes Ereignis, sondern ruft den
 * Webhook der Zahlung auf (https://docs.mollie.com/docs/refunds, Spalte
 * „Webhook" bei processing, refunded, failed). Bis hierher las niemand die
 * Erstattungen: `refunded_cent` blieb 0, `WithdrawOnRefund` lief nie, der
 * Zugang blieb offen.
 */
class MollieRefundTest extends TestCase
{
    protected function fulfilled(): Payment
    {
        $payment = app(Checkout::class)->start('noten-paket', ['email' => 'kaeufer@example.com'])?->payment;
        $this->gateway->markPaid($payment->provider_id);
        app(Fulfilment::class)->handle($payment->provider_id);

        return $payment->fresh();
    }

    #[Test]
    public function a_refund_on_the_payment_is_booked_once(): void
    {
        $payment = $this->fulfilled();
        Event::fake([PaymentRefunded::class]);

        $this->gateway->markRefunded($payment->provider_id, [
            ['id' => 're_voll', 'amount' => $payment->amount_cent],
        ]);

        app(Fulfilment::class)->handle($payment->provider_id);
        app(Fulfilment::class)->handle($payment->provider_id);

        $this->assertSame($payment->amount_cent, (int) $payment->fresh()->refunded_cent);
        Event::assertDispatchedTimes(PaymentRefunded::class, 1);
        Event::assertDispatched(PaymentRefunded::class, fn (PaymentRefunded $e) => $e->isFull === true);
    }

    #[Test]
    public function a_second_partial_refund_is_booked_on_its_own(): void
    {
        $payment = $this->fulfilled();
        Event::fake([PaymentRefunded::class]);

        $this->gateway->markRefunded($payment->provider_id, [['id' => 're_eins', 'amount' => 100]]);
        app(Fulfilment::class)->handle($payment->provider_id);

        $this->gateway->markRefunded($payment->provider_id, [
            ['id' => 're_eins', 'amount' => 100],
            ['id' => 're_zwei', 'amount' => 200],
        ]);
        app(Fulfilment::class)->handle($payment->provider_id);

        $this->assertSame(300, (int) $payment->fresh()->refunded_cent);
        Event::assertDispatchedTimes(PaymentRefunded::class, 2);
    }

    #[Test]
    public function a_refund_seen_on_the_first_delivery_is_booked_after_fulfilment(): void
    {
        // Der erste Webhook ging verloren, die erste erfolgreiche Zustellung
        // kommt erst nach der Erstattung. Erst erfuellen, dann erstatten: so
        // nimmt die volle Erstattung den eben gewaehrten Zugang wieder mit,
        // statt vor ihm zu laufen und ihn stehen zu lassen.
        $order = [];
        Event::listen(PaymentPaid::class, function () use (&$order) {
            $order[] = 'paid';
        });
        Event::listen(PaymentRefunded::class, function () use (&$order) {
            $order[] = 'refunded';
        });

        $payment = app(Checkout::class)->start('noten-paket', ['email' => 'kaeufer@example.com'])?->payment;
        $this->gateway->markPaid($payment->provider_id);
        $this->gateway->markRefunded($payment->provider_id, [['id' => 're_frueh', 'amount' => $payment->amount_cent]]);

        app(Fulfilment::class)->handle($payment->provider_id);

        $this->assertSame(['paid', 'refunded'], $order);
        $this->assertSame($payment->amount_cent, (int) $payment->fresh()->refunded_cent);
    }

    // ---------------------------------------------------------- the real SDK

    protected function mollie(\Closure $responses): MollieGateway
    {
        if (! class_exists(MockMollieClient::class)) {
            $this->markTestSkipped('mollie/mollie-api-php v2 has no mock client');
        }

        return new MollieGateway(new MockMollieClient($responses()));
    }

    /** @param  array<string, mixed>  $extra */
    protected static function payment(array $extra = []): array
    {
        return array_merge([
            'resource' => 'payment',
            'id' => 'tr_erstattet',
            'mode' => 'test',
            'status' => 'paid',
            'amount' => ['value' => '19.00', 'currency' => 'EUR'],
            'metadata' => null,
            '_links' => ['self' => ['href' => 'https://api.mollie.com/v2/payments/tr_erstattet', 'type' => 'application/hal+json']],
        ], $extra);
    }

    /** @return array<string, mixed> */
    protected static function refund(string $id, string $value, string $status): array
    {
        return [
            'resource' => 'refund',
            'id' => $id,
            'mode' => 'test',
            'amount' => ['value' => $value, 'currency' => 'EUR'],
            'status' => $status,
            'paymentId' => 'tr_erstattet',
            'createdAt' => '2026-11-18T01:00:00+00:00',
        ];
    }

    #[Test]
    public function the_gateway_reads_only_completed_refunds(): void
    {
        // Nur refunded ist endgueltig. processing kann laut Mollie noch
        // `failed` werden, und eine dann schon gebuchte Erstattung nimmt
        // niemand zurueck. queued und pending lassen sich noch stornieren,
        // failed und canceled gehen nie hinaus (https://docs.mollie.com/docs/refunds).
        $gateway = $this->mollie(fn () => [
            GetPaymentRequest::class => MockResponse::ok(self::payment([
                'amountRefunded' => ['value' => '7.00', 'currency' => 'EUR'],
            ])),
            GetPaginatedPaymentRefundsRequest::class => MockResponse::ok([
                'count' => 6,
                '_embedded' => ['refunds' => [
                    self::refund('re_fertig', '5.00', 'refunded'),
                    self::refund('re_unterwegs', '2.00', 'processing'),
                    self::refund('re_wartet', '1.00', 'queued'),
                    self::refund('re_bereit', '1.00', 'pending'),
                    self::refund('re_fehl', '1.00', 'failed'),
                    self::refund('re_storno', '1.00', 'canceled'),
                ]],
                '_links' => ['self' => ['href' => 'x', 'type' => 'application/hal+json'], 'previous' => null, 'next' => null],
            ]),
        ]);

        $remote = $gateway->fetch('tr_erstattet');

        $this->assertSame([
            ['id' => 're_fertig', 'amount' => 500],
        ], $remote->refunds);
    }

    #[Test]
    public function a_refund_that_fails_after_processing_was_never_booked(): void
    {
        // Erst processing (Webhook), dann failed (Webhook). Gebucht wird
        // keines von beiden, Zugang und Umsatz bleiben.
        if (! class_exists(MockMollieClient::class)) {
            $this->markTestSkipped('mollie/mollie-api-php v2 has no mock client');
        }

        $liste = fn (string $status) => MockResponse::ok([
            'count' => 1,
            '_embedded' => ['refunds' => [self::refund('re_wackel', '19.00', $status)]],
            '_links' => ['self' => ['href' => 'x', 'type' => 'application/hal+json'], 'previous' => null, 'next' => null],
        ]);
        $zahlung = MockResponse::ok(self::payment(['amountRefunded' => ['value' => '19.00', 'currency' => 'EUR']]));

        $this->assertSame([], $this->mollie(fn () => [
            GetPaymentRequest::class => $zahlung,
            GetPaginatedPaymentRefundsRequest::class => $liste('processing'),
        ])->fetch('tr_erstattet')->refunds);

        $this->assertSame([], $this->mollie(fn () => [
            GetPaymentRequest::class => $zahlung,
            GetPaginatedPaymentRefundsRequest::class => $liste('failed'),
        ])->fetch('tr_erstattet')->refunds);
    }

    #[Test]
    public function two_refunds_in_one_delivery_and_only_the_second_makes_it_full(): void
    {
        $payment = $this->fulfilled();
        Event::fake([PaymentRefunded::class]);

        $this->gateway->markRefunded($payment->provider_id, [
            ['id' => 're_teil', 'amount' => 100],
            ['id' => 're_rest', 'amount' => $payment->amount_cent - 100],
        ]);

        app(Fulfilment::class)->handle($payment->provider_id);

        $this->assertSame($payment->amount_cent, (int) $payment->fresh()->refunded_cent);
        Event::assertDispatchedTimes(PaymentRefunded::class, 2);
        Event::assertDispatched(PaymentRefunded::class, fn (PaymentRefunded $e) => $e->amountCent === 100 && $e->isFull === false);
        Event::assertDispatched(PaymentRefunded::class, fn (PaymentRefunded $e) => $e->amountCent === $payment->amount_cent - 100 && $e->isFull === true);
    }

    #[Test]
    public function a_payment_without_refunded_money_asks_for_no_refund_list(): void
    {
        // Ein Aufruf mehr je gewoehnlicher Zustellung waere Last ohne Nutzen.
        // Der Mock wirft bei jeder nicht erwarteten Anfrage.
        $gateway = $this->mollie(fn () => [
            GetPaymentRequest::class => MockResponse::ok(self::payment([
                'amountRefunded' => ['value' => '0.00', 'currency' => 'EUR'],
            ])),
        ]);

        $this->assertSame([], $gateway->fetch('tr_erstattet')->refunds);
    }
}
