<?php

namespace Goldnead\StatamicPayments\Mail;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Portal\Mail\SendsAsTheConfiguredSender;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Die Erinnerung an einen offenen Kauf.
 *
 * Zwei Gestalten, eine Mailable: kommt `$html` aus einer email-templates-
 * Vorlage, geht genau das raus, wie die Vorschau im Control Panel es zeigt.
 * Kommt nichts, rendert die eingebaute Blade-Fassung dieselben Variablen —
 * schlicht, ohne Bilder, in beiden Sprachen, und veröffentlichbar unter
 * `views/vendor/statamic-payments/abandoned/mail`.
 */
class AbandonedCheckoutMail extends Mailable
{
    use SendsAsTheConfiguredSender;

    /**
     * @param  array<string, mixed>  $variables
     */
    public function __construct(
        public Payment $payment,
        public string $subjectLine,
        public ?string $bodyHtml,
        public array $variables,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->configuredSender(),
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        if ($this->bodyHtml !== null) {
            return new Content(htmlString: $this->bodyHtml);
        }

        return new Content(
            view: 'statamic-payments::abandoned.mail.reminder-html',
            text: 'statamic-payments::abandoned.mail.reminder',
            with: $this->variables,
        );
    }
}
