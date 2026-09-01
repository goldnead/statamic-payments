<?php

namespace Goldnead\StatamicPayments\Legal\Mail;

use Goldnead\StatamicPayments\Legal\Moment;
use Goldnead\StatamicPayments\Models\Withdrawal;
use Goldnead\StatamicPayments\Portal\Mail\SendsAsTheConfiguredSender;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Die Meldung an den Händler: es ist ein Widerruf eingegangen.
 *
 * Hier steht alles, was der Verbraucher nicht zu sehen bekommt — ob eine
 * Zahlung gefunden wurde, welche, ob die Frist nach der Konfiguration noch
 * lief, ob am Treffer eine Zustimmung nach § 356 Abs. 5 steht. Hinweise, keine
 * Entscheidungen: was daraus folgt, entscheidet, wer die Mail liest.
 */
class WithdrawalNotice extends Mailable
{
    use SendsAsTheConfiguredSender;

    public function __construct(
        public Withdrawal $withdrawal,
        public ?bool $withinPeriod,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->configuredSender(),
            subject: (string) __('statamic-payments::withdrawal.mail_merchant_subject', ['id' => $this->withdrawal->public_id]),
        );
    }

    public function content(): Content
    {
        $moment = Moment::parts($this->withdrawal->confirmed_at ?? $this->withdrawal->declared_at);
        $payment = $this->withdrawal->payment;

        return new Content(
            view: 'statamic-payments::withdrawal.mail.merchant-html',
            text: 'statamic-payments::withdrawal.mail.merchant',
            with: [
                'withdrawal' => $this->withdrawal,
                'payment' => $payment,
                'date' => $moment['date'],
                'time' => $moment['time'],
                'zone' => $moment['zone'],
                'withinPeriod' => $this->withinPeriod,
                'days' => (int) config('statamic-payments.withdrawal.days', 14),
                'cpUrl' => cp_route('utilities.withdrawals'),
            ],
        );
    }
}
