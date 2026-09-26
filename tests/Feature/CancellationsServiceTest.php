<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Facades\PaymentLog;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Portal\Mail\CancellationConfirmed;
use Goldnead\StatamicPayments\Support\CancellationOutcome;
use Goldnead\StatamicPayments\Support\Cancellations;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;

/**
 * The whole cancellation as one call, for callers that are not the portal.
 *
 * An app API or a CP action that ends an agreement owes the buyer the same
 * three things the portal does: the provider says yes first, the confirmation
 * in Textform (§ 312k Abs. 2 S. 4) goes out, and the payment's log says so.
 * Rebuilding that sequence in every caller is how one of them forgets the mail.
 */
class CancellationsServiceTest extends TestCase
{
    protected Subscription $subscription;

    protected Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->subscription = Subscription::create([
            'provider' => 'fake',
            'provider_id' => 'sub_1',
            'customer_reference' => 'cst_1',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'interval' => '1 month',
            'times_charged' => 3,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subMonths(3),
            'next_payment_at' => now()->addMonth(),
            'email' => 'anna@example.de',
        ]);

        $this->payment = Payment::create([
            'provider' => 'fake',
            'provider_id' => 'tr_1',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'anna@example.de',
            'paid_at' => now()->subDays(3),
            'subscription_id' => $this->subscription->getKey(),
        ]);

        $this->gateway->subscriptions['sub_1'] = [
            'customer' => 'cst_1',
            'status' => Subscription::STATUS_ACTIVE,
        ];
    }

    #[Test]
    public function it_cancels_at_the_provider_confirms_by_mail_and_logs_it(): void
    {
        $until = $this->subscription->next_payment_at;

        $outcome = app(Cancellations::class)->cancel($this->subscription);

        $this->assertInstanceOf(CancellationOutcome::class, $outcome);
        $this->assertSame(CancellationOutcome::CANCELLED, $outcome->status);
        $this->assertTrue($outcome->cancelled());
        $this->assertTrue($outcome->confirmationSent);
        $this->assertSame('Notenpaket', $outcome->name);
        $this->assertSame('anna@example.de', $outcome->email);
        $this->assertSame($until->toDateTimeString(), $outcome->until?->toDateTimeString());

        $fresh = $this->subscription->fresh();
        $this->assertSame(['sub_1'], $this->gateway->cancelled);
        $this->assertSame(Subscription::STATUS_CANCELLED, $fresh->status);
        $this->assertSame($fresh->cancelled_at->toDateTimeString(), $outcome->moment->toDateTimeString());

        Mail::assertSent(CancellationConfirmed::class, fn (CancellationConfirmed $mail) => $mail->hasTo('anna@example.de'));

        $this->assertSame(
            ['cancellation_confirmation'],
            PaymentLog::for($this->payment)->pluck('kind')->all(),
        );
    }

    #[Test]
    public function an_explicit_address_wins_over_the_one_on_the_row(): void
    {
        app(Cancellations::class)->cancel($this->subscription, 'konto@example.de');

        Mail::assertSent(CancellationConfirmed::class, fn (CancellationConfirmed $mail) => $mail->hasTo('konto@example.de'));
    }

    #[Test]
    public function copies_go_to_further_addresses_each_once_and_logged(): void
    {
        $outcome = app(Cancellations::class)->cancel($this->subscription, 'konto@example.de', [
            'rechnung@chor.example',
            'KONTO@example.de',  // the recipient already, in other case
            'rechnung@chor.example',
            'keine-adresse',
            '',
        ]);

        $this->assertTrue($outcome->confirmationSent);
        $this->assertSame(['rechnung@chor.example'], $outcome->copiedTo);
        Mail::assertSent(CancellationConfirmed::class, 2);
        Mail::assertSent(CancellationConfirmed::class, fn (CancellationConfirmed $mail) => $mail->hasTo('konto@example.de') && ! $mail->hasCc('rechnung@chor.example'));
        Mail::assertSent(CancellationConfirmed::class, fn (CancellationConfirmed $mail) => $mail->hasTo('rechnung@chor.example'));

        $this->assertEqualsCanonicalizing(
            ['konto@example.de', 'rechnung@chor.example'],
            PaymentLog::for($this->payment)->where('kind', 'cancellation_confirmation')->pluck('recipient')->all(),
        );
    }

    #[Test]
    public function a_copy_that_fails_does_not_undo_the_confirmation(): void
    {
        Mail::shouldReceive('to')->andReturnUsing(function ($to) {
            $pending = \Mockery::mock();
            $pending->shouldReceive('send')->andReturnUsing(function () use ($to) {
                if ($to === 'rechnung@chor.example') {
                    throw new \RuntimeException('smtp down');
                }
            });

            return $pending;
        });

        $outcome = app(Cancellations::class)->cancel($this->subscription, null, ['rechnung@chor.example']);

        $this->assertSame(CancellationOutcome::CANCELLED, $outcome->status);
        $this->assertTrue($outcome->confirmationSent);
        $this->assertSame([], $outcome->copiedTo);
    }

    #[Test]
    public function a_provider_that_refuses_changes_nothing_and_sends_nothing(): void
    {
        $this->gateway->refuseToCancel = true;

        $outcome = app(Cancellations::class)->cancel($this->subscription);

        $this->assertSame(CancellationOutcome::FAILED, $outcome->status);
        $this->assertFalse($outcome->cancelled());
        $this->assertFalse($outcome->confirmationSent);
        $this->assertSame(Subscription::STATUS_ACTIVE, $this->subscription->fresh()->status);
        Mail::assertNotSent(CancellationConfirmed::class);
        $this->assertCount(0, PaymentLog::for($this->payment));
    }

    #[Test]
    public function an_agreement_already_over_is_reported_and_not_cancelled_twice(): void
    {
        app(Cancellations::class)->cancel($this->subscription);
        Mail::fake();

        $outcome = app(Cancellations::class)->cancel($this->subscription->fresh());

        $this->assertSame(CancellationOutcome::ALREADY_ENDED, $outcome->status);
        $this->assertTrue($outcome->cancelled());
        $this->assertSame(['sub_1'], $this->gateway->cancelled);
        Mail::assertNotSent(CancellationConfirmed::class);
    }
}
