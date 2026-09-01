<?php

namespace Goldnead\StatamicPayments\Events;

use Goldnead\StatamicPayments\Models\PaymentCommunication;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Eine Zeile ist ins Kommunikationsprotokoll einer Zahlung gekommen.
 *
 * Für alles, was mitschreiben will — ein CRM, das die Rechnungs-Mail auf der
 * Zeitleiste des Kontakts sehen möchte. Wer selbst einträgt, nimmt die Fassade
 * `PaymentLog`, nicht dieses Ereignis.
 */
class PaymentCommunicationLogged
{
    use Dispatchable;

    public function __construct(public readonly PaymentCommunication $communication) {}
}
