<?php

namespace Goldnead\StatamicPayments\Mail;

use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Portal\Mail\SendsAsTheConfiguredSender;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "We could not collect this month's payment."
 *
 * Transactional, not marketing — a customer whose access is about to stop needs
 * to be told, and the suppression list is still the gatekeeper because somebody
 * who asked never to be written to meant it.
 *
 * Same shape as the abandoned-checkout mail: an email-templates slug when the
 * site has one, and the built-in Blade in both languages when it does not. No
 * layout, no images, one link.
 */
class DunningMail extends Mailable
{
    use SendsAsTheConfiguredSender;

    /**
     * @param  array<string, mixed>  $variables
     */
    public function __construct(
        public Subscription $subscription,
        public int $stage,
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
            view: 'statamic-payments::dunning.mail.notice-html',
            text: 'statamic-payments::dunning.mail.notice',
            with: $this->variables,
        );
    }
}
