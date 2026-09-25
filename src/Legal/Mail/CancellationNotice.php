<?php

namespace Goldnead\StatamicPayments\Legal\Mail;

use Goldnead\StatamicPayments\Legal\Cancellations;
use Goldnead\StatamicPayments\Legal\Moment;
use Goldnead\StatamicPayments\Models\Cancellation;
use Goldnead\StatamicPayments\Portal\Mail\SendsAsTheConfiguredSender;
use Goldnead\StatamicPayments\Support\Anrede;
use Goldnead\StatamicPayments\Support\LocalTime;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Die Meldung an den Händler: eine Kündigung ist eingegangen.
 *
 * Mit dem, was der Verbraucher nicht sieht: ob ein Abo gefunden wurde, ob es
 * beim Anbieter gekündigt werden konnte, und wenn nicht, warum nachzuarbeiten
 * ist. Die Fälle, die Arbeit machen, stehen oben.
 */
class CancellationNotice extends Mailable
{
    use SendsAsTheConfiguredSender;

    public function __construct(public Cancellation $cancellation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->configuredSender(),
            subject: (string) Anrede::trans('statamic-payments::cancellation.mail_merchant_subject', ['id' => $this->cancellation->public_id]),
        );
    }

    public function content(): Content
    {
        $moment = Moment::parts($this->cancellation->confirmed_at ?? $this->cancellation->declared_at);

        return new Content(
            view: 'statamic-payments::cancellation.mail.merchant-html',
            text: 'statamic-payments::cancellation.mail.merchant',
            with: [
                'cancellation' => $this->cancellation,
                'subscription' => $subscription = $this->cancellation->subscription,
                // Über die laufende Nummer getroffen: zugeordnet, nicht
                // gekündigt, und der Händler soll wissen, warum.
                'byNumber' => $subscription !== null && ! Cancellations::matchedByProviderId($this->cancellation, $subscription),
                'kind' => Anrede::trans('statamic-payments::cancellation.kind_'.$this->cancellation->kind),
                'effective' => $this->cancellation->effective_at ? LocalTime::portalDate($this->cancellation->effective_at) : null,
                'date' => $moment['date'],
                'time' => $moment['time'],
                'zone' => $moment['zone'],
                'cpUrl' => cp_route('utilities.cancellations'),
            ],
        );
    }
}
