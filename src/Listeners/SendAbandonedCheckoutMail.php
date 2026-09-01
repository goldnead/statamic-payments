<?php

namespace Goldnead\StatamicPayments\Listeners;

use Goldnead\StatamicPayments\Events\CheckoutAbandoned;
use Goldnead\StatamicPayments\Facades\PaymentLog;
use Goldnead\StatamicPayments\Mail\AbandonedCheckoutMail;
use Goldnead\StatamicPayments\Models\PaymentCommunication;
use Goldnead\StatamicPayments\Support\AbandonedReminder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Die Erinnerung an einen offenen Kauf, einmal je angekündigtem Checkout.
 *
 * „Einmal" kommt vom Ereignis: `Abandonment::announce()` beansprucht die
 * Zeile mit einem bedingten UPDATE, bevor es feuert. Hier wird nur noch
 * entschieden, ob die Mail darf — Schalter, Adresse, Sperrliste — und dann
 * verschickt und protokolliert. Ein Fehler beim Versand bricht den Sweep
 * nicht; er steht im Log und als `failed` im Protokoll der Zahlung.
 */
class SendAbandonedCheckoutMail
{
    public function __construct(protected AbandonedReminder $reminder) {}

    public function handle(CheckoutAbandoned $event): void
    {
        if (! $this->reminder->enabled()) {
            return;
        }

        $payment = $event->payment;
        $email = is_string($payment->email) ? trim($payment->email) : '';

        if ($email === '') {
            return;
        }

        if ($this->reminder->suppressed($email, (int) $payment->brand_id)) {
            PaymentLog::note($payment, 'abandoned_suppressed', __('statamic-payments::abandoned.log_suppressed', ['email' => $email]));

            return;
        }

        $payment->loadMissing('items');

        try {
            $rendered = $this->reminder->render($payment);
            $mailable = new AbandonedCheckoutMail($payment, $rendered['subject'], $rendered['html'], $rendered['variables']);

            Mail::to($email)->send($mailable);

            PaymentLog::mail($payment, 'abandoned', $email, $rendered['subject']);
        } catch (Throwable $e) {
            Log::error('statamic-payments: the abandoned-checkout reminder could not be sent.', [
                'payment_id' => $payment->getKey(),
                'exception' => $e->getMessage(),
            ]);

            PaymentLog::mail($payment, 'abandoned', $email, null, PaymentCommunication::STATUS_FAILED, ['error' => $e->getMessage()]);
        }
    }
}
