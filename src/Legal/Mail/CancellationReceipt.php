<?php

namespace Goldnead\StatamicPayments\Legal\Mail;

use Goldnead\StatamicPayments\Legal\Moment;
use Goldnead\StatamicPayments\Models\Cancellation;
use Goldnead\StatamicPayments\Portal\Mail\SendsAsTheConfiguredSender;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Die Bestätigung nach § 312k Abs. 2 S. 4 BGB für eine Kündigung ohne Login.
 *
 * Inhalt, Datum und Uhrzeit des Eingangs, dazu der Zeitpunkt, zu dem der
 * Verbraucher kündigen wollte. Anders als `Portal\Mail\CancellationConfirmed`
 * sagt diese Mail nicht „gekündigt", sondern „eingegangen": ob und wann der
 * Vertrag endet, hängt davon ab, was zugeordnet werden konnte, und das erfährt
 * der Verbraucher vom Händler, nicht von einem Automaten, der raten müsste.
 */
class CancellationReceipt extends Mailable
{
    use SendsAsTheConfiguredSender;

    public function __construct(public Cancellation $cancellation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->configuredSender(),
            subject: (string) __('statamic-payments::cancellation.mail_receipt_subject', ['id' => $this->cancellation->public_id]),
        );
    }

    public function content(): Content
    {
        $moment = Moment::parts($this->cancellation->confirmed_at ?? $this->cancellation->declared_at);

        return new Content(
            view: 'statamic-payments::cancellation.mail.receipt-html',
            text: 'statamic-payments::cancellation.mail.receipt',
            with: [
                'id' => $this->cancellation->public_id,
                'identification' => $this->cancellation->identification,
                'kind' => __('statamic-payments::cancellation.kind_'.$this->cancellation->kind),
                // § 312k Abs. 2 S. 4: die Bestätigung nennt den Inhalt der
                // Erklärung, und bei der außerordentlichen gehört der Grund dazu.
                'reason' => $this->cancellation->isExtraordinary() ? $this->cancellation->reason : null,
                'effective' => $this->cancellation->effective_at?->translatedFormat((string) __('statamic-payments::portal.date_format')),
                'date' => $moment['date'],
                'time' => $moment['time'],
                'zone' => $moment['zone'],
            ],
        );
    }
}
