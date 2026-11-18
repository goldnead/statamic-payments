<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Fulfilment;
use Goldnead\StatamicPayments\Support\SubscriptionReplacements;
use Goldnead\StatamicPayments\Support\Subscriptions;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

/**
 * Die Abo-Buchhaltung nach einer Zahlung darf scheitern, aber nicht still.
 *
 * `followTheAgreement` laeuft nach `PaymentPaid` und wirft bewusst nicht: die
 * Zahlung steht, der Kunde hat, was er bezahlt hat. Frueher blieb vom Fehler
 * nur eine Logzeile ohne Stacktrace, und nichts an der Zeile sagte, dass das
 * Abo oder die Rate nie nachgezaehlt wurde. Jetzt steht ein Reparatur-Marker
 * an der Zahlung, und `payments:agreement-catch-up` zieht nach.
 */
class AgreementPendingTest extends TestCase
{
    /** Wie oft der Abo-Schritt noch scheitern soll. */
    public static int $failures = 0;

    /** Wie oft der Abo-Schritt bis zum Ende durchlief. */
    public static int $completed = 0;

    protected function setUp(): void
    {
        parent::setUp();

        self::$failures = 0;
        self::$completed = 0;

        $this->app->bind(SubscriptionReplacements::class, fn ($app) => new class($app->make(Subscriptions::class), $app->make(Catalogue::class)) extends SubscriptionReplacements
        {
            public function creditDays(Payment $payment): int
            {
                if (AgreementPendingTest::$failures > 0) {
                    AgreementPendingTest::$failures--;

                    throw new RuntimeException('Abo-Tabelle gesperrt');
                }

                AgreementPendingTest::$completed++;

                return parent::creditDays($payment);
            }
        });
    }

    protected function paid(): Payment
    {
        $payment = app(Checkout::class)->start('noten-paket', ['email' => 'kaeufer@example.com'])?->payment;
        $this->gateway->markPaid($payment->provider_id);

        return $payment;
    }

    #[Test]
    public function a_failed_agreement_step_leaves_a_marker_and_a_stacktrace(): void
    {
        Event::fake([PaymentPaid::class]);
        Log::spy();
        self::$failures = 1;

        $payment = $this->paid();

        app(Fulfilment::class)->handle($payment->provider_id);

        $payment->refresh();

        // Die Zahlung steht trotzdem.
        $this->assertTrue($payment->isFulfilled());
        Event::assertDispatchedTimes(PaymentPaid::class, 1);

        $marker = $payment->meta['agreement_pending'] ?? null;
        $this->assertIsArray($marker);
        $this->assertSame('Abo-Tabelle gesperrt', $marker['error']);
        $this->assertNotEmpty($marker['since']);

        // Die Ausnahme selbst geht ins Log, nicht nur ihr Text: so schreibt
        // Laravel den Stacktrace mit.
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context = []): bool => str_contains($message, 'agreement')
                && ($context['exception'] ?? null) instanceof Throwable
        );
    }

    #[Test]
    public function a_successful_agreement_step_leaves_no_marker(): void
    {
        Event::fake([PaymentPaid::class]);

        $payment = $this->paid();

        app(Fulfilment::class)->handle($payment->provider_id);

        $this->assertArrayNotHasKey('agreement_pending', $payment->fresh()->meta ?? []);
        $this->assertSame(1, self::$completed);
    }

    #[Test]
    public function the_command_catches_up_and_clears_the_marker(): void
    {
        Event::fake([PaymentPaid::class]);
        self::$failures = 1;

        $payment = $this->paid();
        app(Fulfilment::class)->handle($payment->provider_id);
        $this->assertArrayHasKey('agreement_pending', $payment->fresh()->meta);
        $this->assertSame(0, self::$completed);

        $this->artisan('payments:agreement-catch-up')->assertSuccessful();

        $this->assertSame(1, self::$completed);
        $this->assertArrayNotHasKey('agreement_pending', $payment->fresh()->meta ?? []);

        // Nachziehen heisst nicht erneut ausliefern.
        Event::assertDispatchedTimes(PaymentPaid::class, 1);
    }

    #[Test]
    public function a_payment_that_still_fails_keeps_its_marker_and_the_command_says_so(): void
    {
        Event::fake([PaymentPaid::class]);
        self::$failures = 2;

        $payment = $this->paid();
        app(Fulfilment::class)->handle($payment->provider_id);

        $this->artisan('payments:agreement-catch-up')->assertFailed();

        $marker = $payment->fresh()->meta['agreement_pending'] ?? null;
        $this->assertIsArray($marker);
        $this->assertSame(2, $marker['attempts']);
        $this->assertSame(0, self::$completed);
    }

    #[Test]
    public function dry_run_shows_but_does_not_touch(): void
    {
        Event::fake([PaymentPaid::class]);
        self::$failures = 1;

        $payment = $this->paid();
        app(Fulfilment::class)->handle($payment->provider_id);

        $this->artisan('payments:agreement-catch-up', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, self::$completed);
        $this->assertArrayHasKey('agreement_pending', $payment->fresh()->meta);
    }
}
