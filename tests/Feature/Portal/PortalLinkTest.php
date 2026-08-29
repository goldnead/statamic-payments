<?php

namespace Goldnead\StatamicPayments\Tests\Feature\Portal;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Portal\Mail\PortalLinkMail;
use Goldnead\StatamicPayments\Portal\PortalSession;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;

/**
 * The way in, and the three ways it must not open.
 *
 * The portal has no password. A magic link is the entire authentication story,
 * so every property of it is load-bearing: it expires, it cannot be edited, and
 * the endpoint that issues it will not tell a stranger who has bought anything.
 */
class PortalLinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    protected function aPaidOrder(string $email = 'anna@example.de'): Payment
    {
        return Payment::create([
            'provider' => 'fake',
            'provider_id' => 'tr_'.uniqid(),
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => $email,
            'paid_at' => now(),
        ]);
    }

    #[Test]
    public function a_buyer_gets_a_link(): void
    {
        $this->aPaidOrder();

        $this->post(route('statamic-payments.portal.request.send'), ['email' => 'anna@example.de'])
            ->assertRedirect(route('statamic-payments.portal.request'));

        Mail::assertSent(PortalLinkMail::class, 1);
    }

    #[Test]
    public function somebody_who_never_bought_anything_gets_nothing_and_the_same_sentence(): void
    {
        $this->aPaidOrder();

        $known = $this->post(route('statamic-payments.portal.request.send'), ['email' => 'anna@example.de']);
        $stranger = $this->post(route('statamic-payments.portal.request.send'), ['email' => 'niemand@example.de']);

        // The same destination and the same flashed sentence. A page that
        // answered differently would be a list of who has spent money, readable
        // by anybody with a browser.
        $this->assertSame($known->headers->get('Location'), $stranger->headers->get('Location'));
        $this->assertSame(
            $known->getSession()->get('statamic-payments.portal.status'),
            $stranger->getSession()->get('statamic-payments.portal.status'),
        );

        Mail::assertSent(PortalLinkMail::class, 1);
    }

    #[Test]
    public function the_address_is_matched_whatever_case_it_was_typed_in(): void
    {
        $this->aPaidOrder('Anna@Example.de');

        $this->post(route('statamic-payments.portal.request.send'), ['email' => '  ANNA@example.DE ']);

        Mail::assertSent(PortalLinkMail::class, 1);
    }

    #[Test]
    public function a_mailbox_cannot_be_flooded(): void
    {
        $this->aPaidOrder();

        config(['statamic-payments.portal.throttle.per_address.max' => 2]);

        foreach (range(1, 4) as $ignored) {
            $this->post(route('statamic-payments.portal.request.send'), ['email' => 'anna@example.de']);
        }

        Mail::assertSent(PortalLinkMail::class, 2);
    }

    #[Test]
    public function a_followed_link_opens_the_orders(): void
    {
        $payment = $this->aPaidOrder();

        $this->get($this->linkFor('anna@example.de'))
            ->assertRedirect(route('statamic-payments.portal.show'));

        $this->get(route('statamic-payments.portal.show'))
            ->assertOk()
            ->assertSee('Notenpaket')
            ->assertSee('anna@example.de');

        $this->get(route('statamic-payments.portal.order', ['payOrder' => $payment->getKey()]))
            ->assertOk();
    }

    #[Test]
    public function an_expired_link_is_refused(): void
    {
        $this->aPaidOrder();

        $url = $this->linkFor('anna@example.de');

        // Past the thirty minutes the link was issued for.
        $this->travel(31)->minutes();

        $this->get($url)->assertForbidden();

        $this->get(route('statamic-payments.portal.show'))
            ->assertRedirect(route('statamic-payments.portal.request'));
    }

    #[Test]
    public function a_tampered_link_is_refused(): void
    {
        $this->aPaidOrder();
        $this->aPaidOrder('boris@example.de');

        $url = $this->linkFor('anna@example.de');

        // One character of the sealed blob changed. The blob is encrypted, so
        // this cannot become somebody else's address — but it must not even get
        // as far as being decrypted, because the signature covers the path.
        $tampered = preg_replace('#/link/(.)#', '/link/'.(str_contains($url, '/link/a') ? 'b' : 'a'), $url, 1);

        $this->get((string) $tampered)->assertForbidden();

        $this->get(route('statamic-payments.portal.show'))
            ->assertRedirect(route('statamic-payments.portal.request'));
    }

    #[Test]
    public function a_correctly_signed_link_around_a_forged_payload_is_refused(): void
    {
        $this->aPaidOrder();

        // The one case a signature cannot catch: somebody who can sign URLs —
        // another package on the same host, a leaked APP_KEY used carelessly —
        // pointing this route at a blob it did not write. The tokenizer refuses
        // it because the blob will not decrypt into the shape it seals.
        $url = URL::temporarySignedRoute(
            'statamic-payments.portal.link',
            now()->addMinutes(10),
            ['payLink' => 'anna-at-example-de'],
        );

        $this->get($url)->assertNotFound();
    }

    #[Test]
    public function following_a_link_gives_the_session_a_new_name(): void
    {
        $this->aPaidOrder();

        $this->get(route('statamic-payments.portal.request'));
        $before = session()->getId();

        $this->get($this->linkFor('anna@example.de'));

        // Session fixation: whoever handed over the id before the click must not
        // find a stranger's orders behind it afterwards.
        $this->assertNotSame($before, session()->getId());
        $this->assertSame('anna@example.de', session()->get(PortalSession::EMAIL));
    }

    #[Test]
    public function the_portal_can_be_switched_off(): void
    {
        config(['statamic-payments.portal.enabled' => false]);

        $this->get(route('statamic-payments.portal.request'))->assertNotFound();
        $this->get(route('statamic-payments.portal.cancel.entry'))->assertNotFound();
    }

    /** Ask for a link the way a buyer does, and read the URL out of the mail. */
    protected function linkFor(string $email): string
    {
        Mail::fake();

        $this->post(route('statamic-payments.portal.request.send'), ['email' => $email]);

        $url = null;

        Mail::assertSent(PortalLinkMail::class, function (PortalLinkMail $mail) use (&$url) {
            $url ??= $mail->url;

            return true;
        });

        $this->assertNotNull($url, 'no link was mailed');

        return (string) $url;
    }
}
