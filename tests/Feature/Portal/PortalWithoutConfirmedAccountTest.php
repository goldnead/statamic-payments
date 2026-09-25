<?php

namespace Goldnead\StatamicPayments\Tests\Feature\Portal;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Portal\Mail\PortalLinkMail;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * Buying does not ask for a confirmed account, so the portal must not either
 * (Gesamtprüfung 25.09.2026, Befund d).
 *
 * The decision: the portal link goes to the address on the order, and
 * following it is the proof of that address. Whether a user account with the
 * same address exists, or has confirmed it, does not matter to the portal. A
 * buyer who paid and never clicked the confirmation mail of the site must still
 * be able to cancel (§ 312k BGB) and see their orders.
 */
class PortalWithoutConfirmedAccountTest extends TestCase
{
    #[Test]
    public function a_buyer_whose_account_is_unconfirmed_reaches_the_portal_through_the_link(): void
    {
        User::make()->email('neu@example.test')->data(['name' => 'Neu'])->save();

        Payment::create([
            'provider' => 'fake',
            'provider_id' => 'tr_unconfirmed',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'paid_at' => now(),
            'email' => 'neu@example.test',
        ]);

        Mail::fake();

        $this->post(route('statamic-payments.portal.request.send'), ['email' => 'neu@example.test']);

        $url = null;

        Mail::assertSent(PortalLinkMail::class, function (PortalLinkMail $mail) use (&$url) {
            $url ??= $mail->url;

            return $mail->hasTo('neu@example.test');
        });

        $this->get((string) $url)->assertRedirect(route('statamic-payments.portal.show'));
        $this->get(route('statamic-payments.portal.show'))->assertOk()->assertSee('Notenpaket');
    }
}
