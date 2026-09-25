<?php

namespace Goldnead\StatamicPayments\Portal\Mail;

use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Anrede;
use Goldnead\StatamicPayments\Support\LocalTime;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/**
 * The confirmation § 312k Abs. 2 S. 4 BGB asks for.
 *
 * **In Textform, which is not a screen.** The statute wants the consumer to
 * receive confirmation of the cancellation, its content, and the date and time
 * it takes effect, on a durable medium. A green box on the page they are already
 * looking at is none of those things: it is gone on reload and it proves nothing
 * afterwards. So the confirmation is a mail, the screen is a courtesy, and the
 * screen says so.
 *
 * **Nothing in this class decides wording.** Every string comes from
 * `statamic-payments::portal`, because the sentences a cancellation confirmation
 * has to contain are a lawyer's business and not an addon author's — and a
 * German phrase compiled into a PHP file cannot be corrected by the site that
 * gets it wrong. Two placeholders are filled with facts rather than text: the
 * moment, and what was cancelled.
 *
 * **It is sent after the provider agreed and the row was written**, never before.
 * A confirmation for a cancellation that did not happen is worse than no
 * confirmation: the buyer stops watching their statement.
 */
class CancellationConfirmed extends Mailable
{
    use SendsAsTheConfiguredSender;

    public function __construct(
        public Subscription $subscription,
        public Carbon $moment,
        public string $productName,
        // Nicht `$until`: öffentliche Eigenschaften landen in der Vorlage und
        // überschrieben dort den formatierten Wert gleichen Namens.
        public ?Carbon $paidUntil = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->configuredSender(),
            subject: (string) Anrede::trans('statamic-payments::portal.mail_cancelled_subject'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'statamic-payments::portal.mail.cancelled-html',
            text: 'statamic-payments::portal.mail.cancelled',
            with: [
                'subscription' => $this->subscription,
                'product' => $this->productName,
                // Formatted here, where the locale is, and not in the template:
                // the date and the time are the two facts the statute names, and
                // a template that formats them is a template that can drop one.
                // In der Anzeige-Zone des Ladens, nicht in `app.timezone`: eine
                // Anwendung in UTC schrieb sonst „19:07 Uhr" für 21:07 in Berlin.
                'date' => LocalTime::portalDate($this->moment),
                'time' => LocalTime::portalTime($this->moment),
                // Bis wann bezahlt ist; der Vertrag endet mit diesem Zeitraum.
                'until' => $this->paidUntil ? LocalTime::portalDate($this->paidUntil) : null,
            ],
        );
    }
}
