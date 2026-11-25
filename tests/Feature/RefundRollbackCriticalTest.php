<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Events\PaymentRefunded;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Refunds;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Wenn schon das Zuruecknehmen scheitert.
 *
 * Der Listener wirft, und das Zuruecknehmen der Buchung wirft auch. Dann ist
 * der Zustand nicht mehr zu retten, und das einzige Signal ist ein kritischer
 * Log-Eintrag mit dem Auftrag, die Zahlung von Hand zu pruefen. Der urspruengliche
 * Fehler geht trotzdem weiter, damit der Anbieter erneut zustellt.
 */
class RefundRollbackCriticalTest extends TestCase
{
    protected function paidPayment(string $provider, string $providerId): Payment
    {
        return Payment::create([
            'provider' => $provider,
            'provider_id' => $providerId,
            'product' => 'kurs',
            'amount_cent' => 10000,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'paid_at' => now(),
            'fulfilled_at' => now(),
            'email' => 'wer@example.com',
        ]);
    }

    #[Test]
    public function a_rollback_that_fails_too_is_logged_as_critical_and_the_original_error_still_leaves(): void
    {
        $zahlung = $this->paidPayment('fake', 'tr_kritisch');
        Log::spy();

        $verbindungWeg = false;
        DB::beforeExecuting(function () use (&$verbindungWeg) {
            if ($verbindungWeg) {
                throw new RuntimeException('Verbindung verloren');
            }
        });

        Event::listen(PaymentRefunded::class, function () use (&$verbindungWeg) {
            // Ab diesem Moment ist die Verbindung weg. Die Buchung selbst lief
            // schon durch (Commit vor dem Ereignis), also scheitert nur das
            // Rueckgaengigmachen. Kein DDL, damit MySQL/pgsql-Laeufe heil bleiben.
            $verbindungWeg = true;

            throw new RuntimeException('entitlements down');
        });

        try {
            app(Refunds::class)->record($zahlung, 10000, 're_kritisch');
            $this->fail('Der urspruengliche Fehler muss den Aufrufer erreichen.');
        } catch (RuntimeException $e) {
            $verbindungWeg = false;
            // Nicht der Fehler vom Zuruecknehmen, sondern der vom Listener.
            $this->assertSame('entitlements down', $e->getMessage());
        }

        Log::shouldHaveReceived('critical', [
            Mockery::on(fn ($message) => is_string($message) && str_contains($message, 'Check this payment by hand')),
            Mockery::on(fn ($context) => is_array($context)
                && ($context['payment_id'] ?? null) === $zahlung->getKey()
                && ($context['reference'] ?? null) === 're_kritisch'
                && ($context['original'] ?? null) === 'entitlements down'),
        ]);

        // Das Zuruecknehmen ist gescheitert, also gibt es nichts, was es als
        // erfolgreich gemeldet haben koennte.
        Log::shouldNotHaveReceived('error', [
            Mockery::on(fn ($message) => is_string($message) && str_contains($message, 'booking was rolled back')),
            Mockery::any(),
        ]);

        $verbindungWeg = false;

        // Die Buchung bleibt stehen, wie sie ist. Genau das ist der Grund fuer
        // die kritische Meldung: der Zugang kann noch offen sein.
        $this->assertSame(10000, (int) $zahlung->fresh()->refunded_cent);
    }
}
