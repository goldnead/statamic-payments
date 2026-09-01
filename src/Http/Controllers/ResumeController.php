<?php

namespace Goldnead\StatamicPayments\Http\Controllers;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Checkout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Der Link aus der Erinnerung: den Checkout noch einmal starten.
 *
 * Signiert und befristet (`ValidateSignature` an der Route); der Link selbst
 * ist der Nachweis, dass die Mail bei der Adresse ankam. Was er tut, ist genau
 * das, was die Kasse der Seite auch getan hätte — `Checkout::start()` mit
 * denselben Positionen —, nur ohne dass jemand alles neu eintippt.
 *
 * Ein Kauf, der nicht mehr fortzusetzen ist (bezahlt, ohne Positionen, Produkt
 * nicht mehr im Katalog), bekommt eine Seite mit einem Satz, keine 404: der
 * Mensch hat auf einen Knopf in einer Mail gedrückt und soll erfahren, warum
 * nichts passiert.
 */
class ResumeController extends Controller
{
    public function __invoke(string $payPayment, Checkout $checkout): RedirectResponse|Response
    {
        $payment = Payment::query()->with('items')->whereKey((int) $payPayment)->first();

        if ($payment === null) {
            return $this->unavailable();
        }

        try {
            $result = $checkout->resume($payment);
        } catch (Throwable $e) {
            Log::error('statamic-payments: a checkout could not be resumed from a reminder link.', [
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

    protected function unavailable(): Response
    {
        return response()->view('statamic-payments::abandoned.unavailable', [], 410);
    }
}
