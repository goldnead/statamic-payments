<?php

namespace Goldnead\StatamicPayments\Portal\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The way in, mailed.
 *
 * **Two bodies, not one**, and that is a measured decision rather than
 * thoroughness. A signed link is around three hundred characters. Sent as
 * `text/plain` only, a client that does not linkify wraps it, a client that does
 * linkify has to guess where it ends, and either way somebody is asked to
 * reassemble a URL by hand to reach a page about their own purchases. The text
 * part stays — it is what renders where HTML is stripped — and the HTML part
 * carries the same URL as something to press.
 *
 * The two parts escape the URL differently and both are right. Each template
 * says why at the line that does it.
 */
class PortalLinkMail extends Mailable
{
    use SendsAsTheConfiguredSender;

    public function __construct(public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->configuredSender(),
            subject: (string) __('statamic-payments::portal.mail_link_subject'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'statamic-payments::portal.mail.link-html',
            text: 'statamic-payments::portal.mail.link',
            with: [
                'url' => $this->url,
                'minutes' => max(1, (int) config('statamic-payments.portal.link_ttl_minutes', 30)),
            ],
        );
    }
}
