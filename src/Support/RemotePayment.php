<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Models\Payment;

/**
 * A payment as the provider currently sees it.
 *
 * The only source of truth about whether money moved. Everything else in this
 * package treats a caller's claim as a hint that a status *may* have changed,
 * never as the status itself.
 */
final readonly class RemotePayment
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $providerId,
        public string $status,
        public array $metadata = [],
        public ?string $email = null,
        /**
         * The agreement this payment is a cycle of, if it is one.
         *
         * Read from the provider, never from the caller: it is what decides
         * whether a payment extends somebody's access or is a one-off, and a
         * webhook that could assert it would be a way to extend a subscription
         * nobody is paying for.
         */
        public ?string $subscriptionId = null,
        /**
         * The buyer's country, as the provider recorded it.
         *
         * Worth more than what somebody typed in a form: it comes from the
         * card issuer or the bank, which is one of the two non-contradictory
         * pieces of evidence the EU asks for on a digital sale to a consumer.
         * Only ever used to fill a gap — a country already frozen at checkout
         * is not overwritten, because an invoice must not change after the fact.
         */
        public ?string $country = null,
        /**
         * Die letzten vier Stellen der Karte, mit der bezahlt wurde.
         *
         * Nur ein Wiedererkennungszeichen fuer den Kaeufer, kein Zahlungsmittel:
         * eine Seite, die gleich ohne erneute Karteneingabe abbucht, muss sagen
         * koennen, welche Karte sie meint. Fehlt bei jeder Zahlungsart, die
         * keine Karte ist — dann sagt die Seite eben nichts Genaueres.
         */
        public ?string $cardLast4 = null,
        /** Die Marke der Karte, wie der Anbieter sie nennt („Mastercard"). */
        public ?string $cardLabel = null,
    ) {}

    public function isPaid(): bool
    {
        return $this->status === Payment::STATUS_PAID;
    }
}
