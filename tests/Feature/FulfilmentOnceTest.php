<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Events\PaymentFailed;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Fulfilment;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * Granting twice is the failure that costs money in the other direction.
 *
 * Providers redeliver by design; a proxy can duplicate a request nobody
 * retried. If fulfilment ran per delivery, one payment would send two files,
 * grant two seats, or post two orders.
 */
class FulfilmentOnceTest extends TestCase
{
    protected function paid(): Payment
    {
        $payment = app(Checkout::class)->start('noten-paket', ['email' => 'kaeufer@example.com'])?->payment;
        $this->gateway->markPaid($payment->provider_id);

        return $payment;
    }

    #[Test]
    public function a_paid_payment_is_fulfilled(): void
    {
        Event::fake([PaymentPaid::class]);

        $payment = $this->paid();

        app(Fulfilment::class)->handle($payment->provider_id);

        $payment->refresh();

        $this->assertTrue($payment->isFulfilled());
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertNotNull($payment->paid_at);
        Event::assertDispatchedTimes(PaymentPaid::class, 1);
    }

    #[Test]
    public function five_deliveries_fulfil_once(): void
    {
        Event::fake([PaymentPaid::class]);

        $payment = $this->paid();

        foreach (range(1, 5) as $ignored) {
            $this->postJson('/!/statamic-payments/webhook', ['id' => $payment->provider_id])->assertOk();
        }

        Event::assertDispatchedTimes(PaymentPaid::class, 1);
    }

    #[Test]
    public function the_claim_is_in_the_database_before_any_listener_runs(): void
    {
        $payment = $this->paid();

        // This is the ordering the whole design rests on, and the one an
        // implementation gets wrong by writing `fulfilled_at` *after* the
        // listeners: for as long as the listeners run — a mail, a sibling API,
        // seconds in a bad minute — the row would still read "not fulfilled",
        // and a redelivery landing in that window would grant a second time.
        //
        // Two deliveries in a row would not show this. So the second delivery
        // is staged from inside the listener, which is exactly that window.
        $claimedWhenListenerRan = null;
        $seen = 0;

        Event::listen(PaymentPaid::class, function (PaymentPaid $event) use (&$claimedWhenListenerRan, &$seen) {
            $seen++;
            $claimedWhenListenerRan = Payment::query()->whereKey($event->payment->id)->value('fulfilled_at');

            app(Fulfilment::class)->handle($event->payment->provider_id);
        });

        app(Fulfilment::class)->handle($payment->provider_id);

        $this->assertNotNull($claimedWhenListenerRan, 'the claim must already stand while the listener runs');
        $this->assertSame(1, $seen);
        $this->assertSame(1, Payment::whereNotNull('fulfilled_at')->count());
    }

    #[Test]
    public function a_listener_that_throws_gives_the_claim_back(): void
    {
        $payment = $this->paid();

        // "Fulfilled once" must not quietly become "fulfilled at most once".
        // If the listener that grants access throws — mail server down, sibling
        // API 500 — and the claim stayed staked, the buyer would have paid, got
        // nothing, and no redelivery would ever help. The failure would be
        // invisible: the row says fulfilled.
        $attempts = 0;
        Event::listen(PaymentPaid::class, function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                throw new \RuntimeException('the mail server is down');
            }
        });

        try {
            app(Fulfilment::class)->handle($payment->provider_id);
            $this->fail('the exception should reach the caller, so the provider retries');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNull(Payment::first()->fulfilled_at, 'the claim must be released');

        // The provider redelivers, and this time it works.
        app(Fulfilment::class)->handle($payment->provider_id);

        $this->assertSame(2, $attempts);
        $this->assertNotNull(Payment::first()->fulfilled_at);
    }

    #[Test]
    public function a_fulfilled_payment_is_never_downgraded(): void
    {
        Event::fake([PaymentFailed::class]);

        $payment = $this->paid();
        app(Fulfilment::class)->handle($payment->provider_id);

        // Every status this package does not know becomes `open`, so one
        // unfamiliar word from the provider is enough to reach this path. A
        // listener on PaymentFailed that revokes access would then revoke it
        // from somebody who paid.
        $this->gateway->markStatus($payment->provider_id, Payment::STATUS_CANCELED);
        app(Fulfilment::class)->handle($payment->provider_id);

        $fresh = Payment::first();
        $this->assertSame(Payment::STATUS_PAID, $fresh->status);
        $this->assertNotNull($fresh->fulfilled_at);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    #[Test]
    public function a_failure_is_announced_once_however_often_it_is_delivered(): void
    {
        Event::fake([PaymentFailed::class]);

        $payment = app(Checkout::class)->start('noten-paket')?->payment;
        $this->gateway->markStatus($payment->provider_id, Payment::STATUS_FAILED);

        foreach (range(1, 3) as $ignored) {
            app(Fulfilment::class)->handle($payment->provider_id);
        }

        // Read-then-write would let two simultaneous deliveries both announce
        // it. "Your payment failed", twice, is a support ticket.
        Event::assertDispatchedTimes(PaymentFailed::class, 1);
        $this->assertNotNull(Payment::first()->failed_notified_at);
    }

    #[Test]
    public function a_payment_whose_id_was_never_stored_is_still_recognised(): void
    {
        Event::fake([PaymentPaid::class]);

        // The one way a real site loses a payment: the process dies between the
        // provider creating it and the id being written back. The buyer pays,
        // the webhook arrives, and nothing matches. That is why the checkout
        // sends our own id along as metadata.
        $payment = app(Checkout::class)->start('noten-paket', ['email' => 'kaeufer@example.com'])?->payment;
        $lost = 'tr_never-stored';
        Payment::query()->whereKey($payment->id)->update(['provider_id' => 'pending-abc']);
        $this->gateway->knows($lost, Payment::STATUS_PAID, ['payment_id' => $payment->id]);

        app(Fulfilment::class)->handle($lost);

        $fresh = Payment::first();
        $this->assertSame($lost, $fresh->provider_id);
        $this->assertNotNull($fresh->fulfilled_at);
        Event::assertDispatchedTimes(PaymentPaid::class, 1);
    }

    #[Test]
    public function a_payment_that_turns_paid_later_is_fulfilled_then(): void
    {
        Event::fake([PaymentPaid::class]);

        $payment = app(Checkout::class)->start('noten-paket')?->payment;

        // First delivery: still open. Ordinary — Mollie calls on every status
        // change, most of which are not "paid".
        app(Fulfilment::class)->handle($payment->provider_id);
        Event::assertNotDispatched(PaymentPaid::class);

        $this->gateway->markPaid($payment->provider_id);
        app(Fulfilment::class)->handle($payment->provider_id);

        Event::assertDispatchedTimes(PaymentPaid::class, 1);
    }

    #[Test]
    public function a_failed_payment_says_so_once(): void
    {
        Event::fake([PaymentFailed::class]);

        $payment = app(Checkout::class)->start('noten-paket')?->payment;
        $this->gateway->markStatus($payment->provider_id, Payment::STATUS_FAILED);

        app(Fulfilment::class)->handle($payment->provider_id);
        app(Fulfilment::class)->handle($payment->provider_id);

        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);
        Event::assertDispatchedTimes(PaymentFailed::class, 1);
    }

    #[Test]
    public function an_unknown_provider_status_is_never_read_as_paid(): void
    {
        Event::fake([PaymentPaid::class, PaymentFailed::class]);

        $payment = app(Checkout::class)->start('noten-paket')?->payment;
        $this->gateway->markStatus($payment->provider_id, 'authorized_but_not_captured');

        app(Fulfilment::class)->handle($payment->provider_id);

        // A status this package has not met must not grant, and must not cancel
        // an order that is merely pending either.
        $this->assertFalse($payment->fresh()->isFulfilled());
        Event::assertNotDispatched(PaymentPaid::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    #[Test]
    public function the_buyers_address_is_taken_from_the_provider_when_missing(): void
    {
        $payment = app(Checkout::class)->start('noten-paket')?->payment;
        $this->assertNull($payment->email);

        $this->gateway->markPaid($payment->provider_id, 'vom-anbieter@example.com');
        app(Fulfilment::class)->handle($payment->provider_id);

        $this->assertSame('vom-anbieter@example.com', $payment->fresh()->email);
    }

    #[Test]
    public function an_address_the_buyer_gave_is_not_overwritten(): void
    {
        $payment = app(Checkout::class)->start('noten-paket', ['email' => 'gewaehlt@example.com'])?->payment;

        $this->gateway->markPaid($payment->provider_id, 'anders@example.com');
        app(Fulfilment::class)->handle($payment->provider_id);

        // The buyer typed where the file should go. The provider's account
        // address may be a different person entirely — a spouse, an employer.
        $this->assertSame('gewaehlt@example.com', $payment->fresh()->email);
    }

    #[Test]
    public function it_runs_without_any_listener(): void
    {
        // The seam is optional. A site that installs this and listens to
        // nothing must still take payments — no entitlements package required.
        $payment = $this->paid();

        app(Fulfilment::class)->handle($payment->provider_id);

        $this->assertTrue($payment->fresh()->isFulfilled());
    }
}
