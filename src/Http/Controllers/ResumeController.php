<?php

namespace Goldnead\StatamicPayments\Http\Controllers;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Der Link aus der Erinnerung: den Kauf noch einmal bestellen.
 *
 * Zwei Schritte, und der erste legt nichts an. Der signierte GET aus der Mail
 * zeigt die Positionen, den Gesamtpreis, die Widerrufsbelehrung, den Haken
 * nach § 356 Abs. 5 und genau eine Schaltfläche „Zahlungspflichtig bestellen".
 * Erst der POST dahinter startet über `Checkout::resume()` eine neue Zahlung
 * — mit einer Zustimmung, die eben auf dieser Seite gesetzt wurde, oder ohne.
 *
 * § 312j Abs. 3 BGB verlangt für einen Vertragsschluss eine Schaltfläche mit
 * eindeutigem Wortlaut und die wesentlichen Angaben unmittelbar davor. Ein
 * Link, der beim Anklicken schon eine Zahlung anlegt und zum Anbieter leitet,
 * hätte beides übersprungen — und ein Mail-Client, der Links vorab abruft,
 * hätte bestellt. (Entscheidung 02.09.2026, von Adrian zu prüfen. Keine
 * Rechtsberatung.)
 *
 * Ein Kauf, der nicht mehr fortzusetzen ist (bezahlt, ohne Positionen, Produkt
 * nicht mehr im Katalog), bekommt eine Seite mit einem Satz, keine 404.
 */
class ResumeController extends Controller
{
    /** Wie lange der Bestell-POST nach dem Öffnen der Seite gültig bleibt. */
    protected const ORDER_LINK_MINUTES = 60;

    public function show(string $payPayment): Response
    {
        $payment = $this->resumable($payPayment);

        if ($payment === null) {
            return $this->unavailable();
        }

        return response()->view('statamic-payments::abandoned.resume', [
            'payment' => $payment,
            'lines' => $payment->items->sortBy('id')->values()->map(fn (PaymentItem $item) => [
                'name' => $item->name,
                'quantity' => $item->quantity,
                'amount' => Money::format($item->lineTotalCent(), $payment->currency),
            ])->all(),
            'total' => $payment->amount(),
            'currency' => $payment->currency,
            'discount' => $payment->discount_cent ? Money::format((int) $payment->discount_cent, $payment->currency) : null,
            'consentText' => self::consentTextFor($payment),
            'policyUrl' => config('statamic-payments.withdrawal.policy_url'),
            'action' => URL::temporarySignedRoute(
                'statamic-payments.resume.start',
                Carbon::now()->addMinutes(self::ORDER_LINK_MINUTES),
                ['payPayment' => $payment->getKey()],
            ),
        ]);
    }

    public function start(Request $request, string $payPayment, Checkout $checkout): RedirectResponse|Response
    {
        $payment = $this->resumable($payPayment);

        if ($payment === null) {
            return $this->unavailable();
        }

        // Nur was auf der Seite stand, und nur wenn der Haken gesetzt war.
        // Der Wortlaut kommt nicht aus dem Request: was dort ankäme, wäre
        // eine Behauptung, keine Zustimmung.
        $consent = $request->boolean('consent') ? self::consentTextFor($payment) : null;

        try {
            $result = $checkout->resume($payment, $consent);
        } catch (Throwable $e) {
            Log::error('statamic-payments: a checkout could not be resumed from a reminder.', [
                'payment_id' => $payment->getKey(),
                'exception' => $e->getMessage(),
            ]);

            $result = null;
        }

        if ($result === null) {
            return $this->unavailable();
        }

        return redirect()->away($result->checkoutUrl);
    }

    /**
     * Der Wortlaut, dem zugestimmt wird: die Belehrung, die der Kasse der
     * Seite an die Zahlung geheftet hat (`meta.withdrawal`, als Text oder als
     * `['text' => …]`), sonst der Satz des Pakets.
     */
    public static function consentTextFor(Payment $payment): string
    {
        $withdrawal = data_get($payment->meta, 'withdrawal');

        if (is_array($withdrawal)) {
            $withdrawal = $withdrawal['text'] ?? null;
        }

        if (is_string($withdrawal) && trim($withdrawal) !== '') {
            return trim($withdrawal);
        }

        return (string) __('statamic-payments::messages.order_consent');
    }

    protected function resumable(string $payPayment): ?Payment
    {
        $payment = Payment::query()->with('items')->whereKey((int) $payPayment)->first();

        if ($payment === null || $payment->isPaid() || $payment->isFulfilled() || $payment->items->isEmpty()) {
            return null;
        }

        return $payment;
    }

    protected function unavailable(): Response
    {
        return response()->view('statamic-payments::abandoned.unavailable', [], 410);
    }
}
