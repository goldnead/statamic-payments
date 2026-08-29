<?php

namespace Goldnead\StatamicPayments\Portal\Mail;

use Illuminate\Mail\Mailables\Address;

/**
 * Who the portal's mails leave as.
 *
 * One copy, because there were two and they were identical — and the day one of
 * them learns about a per-brand sender is the day the other one silently keeps
 * sending a cancellation confirmation from the wrong company.
 */
trait SendsAsTheConfiguredSender
{
    /**
     * Null where the site has said nothing, which lets the application's own
     * `mail.from` stand. That is right on a single-brand install, and it is what
     * lets a host wrap these mailables to put a brand's own sender on them: a
     * hard assignment here would quietly undo that, and the address is the half
     * of the pair a relay checks against the account the transport belongs to.
     */
    protected function configuredSender(): ?Address
    {
        $from = (array) config('statamic-payments.portal.from', []);

        if (empty($from['address']) || ! is_string($from['address'])) {
            return null;
        }

        $name = $from['name'] ?? null;

        return new Address($from['address'], is_string($name) && $name !== '' ? $name : null);
    }
}
