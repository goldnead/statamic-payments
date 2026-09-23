<?php

namespace Goldnead\StatamicPayments\Mail;

use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Portal\Mail\SendsAsTheConfiguredSender;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A heads-up before something goes wrong: a charge is due, a card runs out, a
 * card has run out.
 *
 * Transactional, like the dunning letter it exists to make unnecessary. Same
 * shape: an email-templates slug where the site has one, the built-in Blade in
 * both languages where it does not. One view for all three kinds; the wording
 * per kind is in the `reminders` translation file.
 */
class SubscriptionReminderMail extends Mailable
{
    use SendsAsTheConfiguredSender;

    /**
     * @param  array<string, mixed>  $variables
     */
    public function __construct(
        public Subscription $subscription,
        public string $kind,
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
            view: 'statamic-payments::reminders.mail.message-html',
            text: 'statamic-payments::reminders.mail.message',
            with: $this->variables + ['kind' => $this->kind],
        );
    }
}
