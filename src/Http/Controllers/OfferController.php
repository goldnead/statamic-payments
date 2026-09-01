<?php

namespace Goldnead\StatamicPayments\Http\Controllers;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\FollowUp;
use Goldnead\StatamicPayments\Support\PaymentDetails;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Accepting a follow-up offer.
 *
 * A normal form post with a CSRF token, deliberately: this is a browser, a
 * person and an order, and it must look like one. The webhook endpoint next
 * door drops CSRF because its caller is a server; doing the same here would
 * mean a page on another site could place an order on this one.
 */
class OfferController
{
    public function __invoke(Request $request, FollowUp $followUp): RedirectResponse
    {
        $data = $request->validate([
            'payment' => ['required', 'integer'],
            'product' => ['required', 'string', 'max:191'],
            // The order button's own checkbox. It is what makes the request
            // distinguishable from a stray POST — and, since 1.17, it is also
            // written down: see `consent_*` below.
            'confirmed' => ['accepted'],
            // Der Wortlaut, den das Formular neben dem Haken gezeigt hat, als
            // verstecktes Feld mitgeschickt. Optional, weil ein Host das Feld
            // vergessen kann; dann steht der Wortlaut aus der Sprachdatei
            // dieses Addons in der Zeile, und der Host muss dafür sorgen, dass
            // sein Formular genau diesen zeigt.
            'consent_text' => ['nullable', 'string', 'max:'.PaymentDetails::CONSENT_TEXT_MAX],
        ]);

        $original = Payment::find($data['payment']);

        if (! $original) {
            return back()->withErrors(['offer' => __('statamic-payments::messages.offer_refused')]);
        }

        // § 356 Abs. 5 BGB: der Haken oben ist die Zustimmung, dass die
        // Lieferung sofort beginnt und das Widerrufsrecht damit erlischt.
        // Zeitpunkt und Wortlaut gehen mit dem ersten INSERT in die
        // Folgezahlung — nicht geerbt von der Erstbestellung, denn das hier ist
        // ein zweiter Vertrag mit eigener Bestellschaltfläche. Der Zeitpunkt
        // ist der Eingang des Formulars, nicht der Klick im Browser, weil nur
        // der eine belegbar ist. (Rechtliche Entscheidung 01.09.2026, von
        // Adrian zu prüfen. Keine Rechtsberatung.)
        $consentText = trim((string) ($data['consent_text'] ?? ''));

        $follow = $followUp->accept($original, $data['product'], [
            'accepted_at' => now()->toIso8601String(),
            'from' => (string) $request->headers->get('referer'),
        ], [
            'consent_at' => now(),
            'consent_text' => $consentText !== '' ? $consentText : (string) __('statamic-payments::messages.order_consent'),
        ]);

        if (! $follow) {
            // Refused. The buyer sees a plain message rather than a charge that
            // silently did not happen.
            Log::warning('statamic-payments: a follow-up offer could not be accepted.', [
                'parent_payment_id' => $original->getKey(),
                'product' => $data['product'],
            ]);

            return back()->withErrors(['offer' => __('statamic-payments::messages.offer_refused')]);
        }

        return back()->with('statamic-payments.offer', [
            'payment' => $follow->getKey(),
            'status' => $follow->status,
        ]);
    }
}
