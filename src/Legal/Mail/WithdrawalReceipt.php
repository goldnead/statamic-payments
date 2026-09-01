<?php

namespace Goldnead\StatamicPayments\Legal\Mail;

use Goldnead\StatamicPayments\Legal\Moment;
use Goldnead\StatamicPayments\Models\Withdrawal;
use Goldnead\StatamicPayments\Portal\Mail\SendsAsTheConfiguredSender;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Die Eingangsbestätigung nach § 356a Abs. 4 BGB.
 *
 * Drei Tatsachen, in beiden Fassungen: die Kennung, der Zeitpunkt des Eingangs
 * mit Zone, die eingegebene Bestellkennung. Kein Wort dazu, ob eine Bestellung
 * gefunden wurde oder ob das Widerrufsrecht noch besteht — das erste wäre ein
 * Orakel, das zweite eine Rechtsauskunft, und beides gehört nicht in eine
 * automatisch verschickte Mail.
 *
 * Jeder Satz kommt aus `statamic-payments::withdrawal`, aus denselben Gründen
 * wie beim Portal: der Wortlaut geht vor einen Anwalt, nicht vor einen
 * Compiler.
 */
class WithdrawalReceipt extends Mailable
{
    use SendsAsTheConfiguredSender;

    public function __construct(public Withdrawal $withdrawal) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->configuredSender(),
            subject: (string) __('statamic-payments::withdrawal.mail_receipt_subject', ['id' => $this->withdrawal->public_id]),
        );
    }

    public function content(): Content
    {
        $moment = Moment::parts($this->withdrawal->confirmed_at ?? $this->withdrawal->declared_at);

        return new Content(
            view: 'statamic-payments::withdrawal.mail.receipt-html',
            text: 'statamic-payments::withdrawal.mail.receipt',
            with: [
                'id' => $this->withdrawal->public_id,
                'reference' => $this->withdrawal->order_reference,
                'date' => $moment['date'],
                'time' => $moment['time'],
                'zone' => $moment['zone'],
            ],
        );
    }
}
