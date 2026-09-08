<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Events\PaymentChargedBack;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Integrations\EntitlementsBridge;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Chargebacks;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

/**
 * Money the bank took back, from both providers.
 *
 * A chargeback used to leave a paid purchase with open access and nothing
 * anywhere to notice it by — the same shape of hole `WithdrawOnRefund` closed
 * for refunds, without even the way to see it.
 */
class ChargebackTest extends TestCase
{
    protected string $secret = 'whsec_kJ8vN2mQ4pR7sT1uV3wX5yZ6aB8cD0eF';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.stripe.key', 'sk_test_notARealKey');
        $app['config']->set('statamic-payments.stripe.webhook_secret', $this->secret);
    }

    /** Die Uhr zurueckstellen, egal wie der Test ausging — siehe DunningTest. */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function paidPayment(string $provider, string $providerId): Payment
    {
        return Payment::create([
            'provider' => $provider,
            'provider_id' => $providerId,
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'paid_at' => now(),
            'fulfilled_at' => now(),
            'email' => 'kaeufer@example.com',
        ]);
    }

    // ------------------------------------------------------------- the writer

    #[Test]
    public function a_chargeback_is_its_own_state_and_never_a_refund(): void
    {
        $payment = $this->paidPayment('mollie', 'tr_1');

        $this->assertTrue(app(Chargebacks::class)->record($payment, 'chb_1', 1900, 'fraudulent'));

        $payment->refresh();

        $this->assertNotNull($payment->charged_back_at);
        // The money did move and the sale did happen. `refunded_cent` is for a
        // decision somebody here made, and counting a dispute into it would
        // make every revenue figure wrong about both.
        $this->assertSame(0, (int) $payment->refunded_cent);
        $this->assertNull($payment->refunded_at);
        $this->assertTrue($payment->isPaid());
    }

    #[Test]
    public function the_same_chargeback_twice_is_recorded_once(): void
    {
        $payment = $this->paidPayment('mollie', 'tr_2');
        Event::fake([PaymentChargedBack::class]);

        $service = app(Chargebacks::class);

        $this->assertTrue($service->record($payment, 'chb_2', 1900));
        $this->assertFalse($service->record($payment, 'chb_2', 1900));

        // Once, and that matters beyond tidiness: the event withdraws access,
        // and doing it twice is a second "your access was removed" for one
        // dispute.
        Event::assertDispatchedTimes(PaymentChargedBack::class, 1);
        $this->assertSame(1, DB::table('payment_chargebacks')->where('payment_id', $payment->getKey())->count());
    }

    #[Test]
    public function a_chargeback_without_a_reference_is_refused(): void
    {
        // Without one there is nothing to be idempotent about, and every
        // redelivery would revoke access again.
        $payment = $this->paidPayment('mollie', 'tr_3');

        $this->assertFalse(app(Chargebacks::class)->record($payment, '   ', 1900));
        $this->assertNull($payment->fresh()->charged_back_at);
    }

    #[Test]
    public function the_date_is_set_once_even_when_a_payment_is_disputed_twice(): void
    {
        $payment = $this->paidPayment('mollie', 'tr_4');
        $service = app(Chargebacks::class);

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        $service->record($payment, 'chb_a', 900);
        $first = $payment->fresh()->charged_back_at;

        Carbon::setTestNow(Carbon::parse('2026-09-05 10:00:00'));
        $service->record($payment, 'chb_b', 1000);

        $this->assertEquals($first, $payment->fresh()->charged_back_at);
        $this->assertSame(2, DB::table('payment_chargebacks')->where('payment_id', $payment->getKey())->count());

        Carbon::setTestNow();
    }

    // ------------------------------------------------------------ over Stripe

    protected function deliverStripe(string $eventId, string $type, array $object): TestResponse
    {
        $body = json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ], JSON_UNESCAPED_SLASHES);

        $timestamp = Carbon::now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $this->secret);

        return $this->call('POST', '/!/statamic-payments/webhook/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $body);
    }

    #[Test]
    public function a_stripe_dispute_is_recorded_over_the_real_webhook_path(): void
    {
        $payment = $this->paidPayment('stripe', 'cs_test_disputed');
        Event::fake([PaymentChargedBack::class]);

        Http::fake(['api.stripe.com/v1/checkout/sessions?*' => Http::response([
            'object' => 'list',
            'data' => [['id' => 'cs_test_disputed']],
        ])]);

        $this->deliverStripe('evt_dispute', 'charge.dispute.created', [
            'id' => 'dp_test_1',
            'object' => 'dispute',
            'charge' => 'ch_test_1',
            'payment_intent' => 'pi_test_1',
            'amount' => 1900,
            'reason' => 'fraudulent',
        ])->assertOk();

        $payment->refresh();

        $this->assertNotNull($payment->charged_back_at);
        $this->assertSame(0, (int) $payment->refunded_cent);
        Event::assertDispatched(PaymentChargedBack::class, fn ($e) => $e->reference === 'dp_test_1' && $e->reason === 'fraudulent');
    }

    #[Test]
    public function a_redelivered_stripe_dispute_changes_nothing_a_second_time(): void
    {
        $payment = $this->paidPayment('stripe', 'cs_test_twice');
        Event::fake([PaymentChargedBack::class]);

        Http::fake(['api.stripe.com/v1/checkout/sessions?*' => Http::response([
            'object' => 'list',
            'data' => [['id' => 'cs_test_twice']],
        ])]);

        $object = [
            'id' => 'dp_test_2',
            'object' => 'dispute',
            'charge' => 'ch_test_2',
            'payment_intent' => 'pi_test_2',
            'amount' => 1900,
        ];

        // Two different Stripe events about the same dispute — which is what a
        // `created` and a later redelivery under a new event id look like. The
        // webhook claim does not catch this one; the chargeback claim does.
        $this->deliverStripe('evt_d1', 'charge.dispute.created', $object)->assertOk();
        $this->deliverStripe('evt_d2', 'charge.dispute.created', $object)->assertOk();

        Event::assertDispatchedTimes(PaymentChargedBack::class, 1);
        $this->assertSame(1, DB::table('payment_chargebacks')->where('payment_id', $payment->getKey())->count());
    }

    // ------------------------------------------------------------ over Mollie

    #[Test]
    public function a_mollie_chargeback_is_recorded_over_the_real_webhook_path(): void
    {
        // Mollie has no dispute event: it announces the chargeback as a change
        // on the payment, and the ordinary webhook carries only the id.
        $payment = $this->paidPayment('fake', 'tr_mollie_cb');
        Event::fake([PaymentChargedBack::class]);

        $this->gateway->markPaid('tr_mollie_cb');
        $this->gateway->markChargedBack('tr_mollie_cb', 1900, 'chb_mollie_1');

        $this->postJson('/!/statamic-payments/webhook', ['id' => 'tr_mollie_cb'])->assertOk();

        $payment->refresh();

        $this->assertNotNull($payment->charged_back_at);
        $this->assertSame(0, (int) $payment->refunded_cent);
        Event::assertDispatched(PaymentChargedBack::class, fn ($e) => $e->reference === 'chb_mollie_1');
    }

    #[Test]
    public function a_second_mollie_delivery_about_the_same_chargeback_changes_nothing(): void
    {
        // Mollie sends the payment webhook again on every state change, and a
        // payment that has been charged back keeps reporting it for ever.
        $payment = $this->paidPayment('fake', 'tr_mollie_twice');
        Event::fake([PaymentChargedBack::class]);

        $this->gateway->markPaid('tr_mollie_twice');
        $this->gateway->markChargedBack('tr_mollie_twice', 1900, 'chb_mollie_2');

        $this->postJson('/!/statamic-payments/webhook', ['id' => 'tr_mollie_twice'])->assertOk();
        $this->postJson('/!/statamic-payments/webhook', ['id' => 'tr_mollie_twice'])->assertOk();

        Event::assertDispatchedTimes(PaymentChargedBack::class, 1);
        $this->assertSame(1, DB::table('payment_chargebacks')->where('payment_id', $payment->getKey())->count());
    }

    #[Test]
    public function a_charged_back_payment_is_never_fulfilled_afterwards(): void
    {
        // The trap: a charged-back Mollie payment still reads `paid`, and
        // `isPaid()` knows only the status. Without a guard the same delivery
        // recorded the dispute and then fulfilled the order — access to a
        // product whose money is gone by the package's own log. It happens when
        // the first successful delivery for an id arrives *after* the
        // chargeback, which one lost webhook is enough to cause.
        $payment = Payment::create([
            'provider' => 'fake',
            'provider_id' => 'tr_late',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_OPEN,
            'email' => 'kaeufer@example.com',
        ]);

        Event::fake([PaymentChargedBack::class, PaymentPaid::class]);

        $this->gateway->markPaid('tr_late');
        $this->gateway->markChargedBack('tr_late', 1900, 'chb_late');

        $this->postJson('/!/statamic-payments/webhook', ['id' => 'tr_late'])->assertOk();

        $payment->refresh();

        $this->assertNotNull($payment->charged_back_at);
        $this->assertNull($payment->fulfilled_at, 'a charged-back payment must not be fulfilled');
        Event::assertNotDispatched(PaymentPaid::class);
        Event::assertDispatched(PaymentChargedBack::class);
    }

    #[Test]
    public function the_access_is_withdrawn_with_its_own_reason(): void
    {
        // „Zugang entziehen" is a ticket requirement, and the event firing does
        // not prove it — the listener has to be discovered and has to call the
        // bridge. The reason matters too: sharing the refund wording would tell
        // a support reader that somebody here decided to give the money back,
        // when in fact it was taken.
        $bridge = new class extends EntitlementsBridge
        {
            public array $revoked = [];

            public function revokeFor(Payment $payment, bool $isFull, string $reason = 'Zahlung erstattet'): void
            {
                $this->revoked[] = ['payment' => $payment->getKey(), 'full' => $isFull, 'reason' => $reason];
            }
        };

        $this->app->instance(EntitlementsBridge::class, $bridge);

        $payment = $this->paidPayment('mollie', 'tr_access');

        app(Chargebacks::class)->record($payment, 'chb_access', 1900);

        $this->assertSame(
            [['payment' => $payment->getKey(), 'full' => true, 'reason' => 'Rückbuchung']],
            $bridge->revoked,
        );
    }

    #[Test]
    public function a_dispute_on_a_subscription_renewal_finds_its_row(): void
    {
        // The population this release is about, and the branch that was written
        // for it. A renewal's row carries the **invoice** id — not a session and
        // not the intent — so without the charge-to-invoice lookup a dispute on
        // a renewal matched nothing at all: money gone, access kept.
        //
        // And Stripe's dispute has `charge` non-nullable while `payment_intent`
        // is nullable, so this fixture is the shape that actually arrives.
        $payment = $this->paidPayment('stripe', 'in_renewal_1');
        Event::fake([PaymentChargedBack::class]);

        Http::fake([
            'api.stripe.com/v1/charges/ch_renewal*' => Http::response([
                'id' => 'ch_renewal',
                'object' => 'charge',
                'invoice' => 'in_renewal_1',
            ]),
        ]);

        $this->deliverStripe('evt_renewal_dispute', 'charge.dispute.created', [
            'id' => 'dp_renewal_1',
            'object' => 'dispute',
            'charge' => 'ch_renewal',
            'payment_intent' => null,
            'amount' => 1900,
            'reason' => 'product_not_received',
        ])->assertOk();

        $this->assertNotNull($payment->fresh()->charged_back_at);
        Event::assertDispatched(PaymentChargedBack::class, fn ($e) => $e->reference === 'dp_renewal_1');
    }

    #[Test]
    public function a_dispute_naming_neither_a_charge_nor_an_intent_is_refused(): void
    {
        $this->paidPayment('stripe', 'cs_test_orphan');
        Event::fake([PaymentChargedBack::class]);
        Http::fake();

        $this->deliverStripe('evt_orphan', 'charge.dispute.created', [
            'id' => 'dp_orphan',
            'object' => 'dispute',
            'amount' => 1900,
        ])->assertOk();

        Event::assertNotDispatched(PaymentChargedBack::class);
    }

    #[Test]
    public function the_payment_is_not_marked_refunded_and_stays_billable(): void
    {
        // Der Name sagt jetzt, was die Zusicherung sieht. Vorher hiess er „die
        // Rechnung wird nicht storniert" und pruefte drei Felder an der
        // Zahlung — ueber eine Rechnung sagte er nichts, und waere gruen
        // geblieben, wenn ein Listener genau das getan haette. Dass keine
        // Rechnung angefasst wird, steht als Abwesenheit im Code: kein Aufruf
        // irgendwo in diesem Pfad beruehrt die Rechnungs-Naht.
        //
        // Was hier wirklich geprueft wird: eine zurueckgebuchte Bestellung
        // bleibt bezahlt und erfuellt, weil das Geld geflossen und die Sache
        // geliefert ist. Ein Status, der zwischen beidem waehlen muesste, waere
        // ueber die andere Haelfte falsch.
        $payment = $this->paidPayment('mollie', 'tr_invoice');

        app(Chargebacks::class)->record($payment, 'chb_invoice', 1900);

        $payment->refresh();

        $this->assertTrue($payment->isPaid());
        $this->assertNotNull($payment->fulfilled_at);
        $this->assertNotNull($payment->paid_at);
    }
}
