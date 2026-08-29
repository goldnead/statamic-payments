<?php

namespace Goldnead\StatamicPayments\Tests\Feature\Portal;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Portal\Mail\PortalLinkMail;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;

/**
 * The link mail is actually rendered, by somebody, at least once.
 *
 * `Mail::fake()` does not render. Every other test in this directory asks for a
 * link and reads `$mail->url` off the mailable — which means both templates
 * behind it could have carried a typo, a missing variable or a bad view name
 * and the whole suite would still have been green, right up until the first
 * buyer asked for a link and got a 500 instead.
 *
 * That is the shape this family has paid for before: a test that stops at the
 * boundary it was supposed to cross. So this one renders.
 *
 * **Both parts, and the URL differently in each.** The plain-text body prints
 * the link unescaped and has to — Blade's default would turn the `&` between
 * `expires` and `signature` into `&amp;`, the URL would still look right to a
 * reader, and Laravel would then reject it as unsigned. The HTML body escapes
 * the same URL and has to, because an attribute value is an HTML context and
 * the client turns `&amp;` back before the request is ever made. Getting either
 * one backwards is a 403 on the one link the person asked for.
 */
class PortalMailRendersTest extends TestCase
{
    protected function aLinkMail(): PortalLinkMail
    {
        Payment::create([
            'provider' => 'fake',
            'provider_id' => 'tr_1',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'anna@example.de',
            'paid_at' => now(),
        ]);

        Mail::fake();

        $this->post(route('statamic-payments.portal.request.send'), ['email' => 'anna@example.de']);

        $mail = null;

        Mail::assertSent(PortalLinkMail::class, function (PortalLinkMail $sent) use (&$mail) {
            $mail ??= $sent;

            return true;
        });

        $this->assertNotNull($mail);

        return $mail;
    }

    #[Test]
    public function the_html_part_renders_and_carries_a_usable_link(): void
    {
        $mail = $this->aLinkMail();
        $html = $mail->render();

        $this->assertStringContainsString(__('statamic-payments::portal.mail_link_button'), $html);
        $this->assertStringContainsString('30', $html, 'the lifetime is not on the mail');

        // Escaped in the attribute, which is what makes it survive the trip.
        $this->assertStringContainsString('href="'.e($mail->url).'"', $html);
        $this->assertStringContainsString('&amp;', $html);
    }

    #[Test]
    public function the_text_part_renders_and_carries_the_raw_url(): void
    {
        $mail = $this->aLinkMail();

        // Two assertions, because one of them alone proves nothing. That the
        // mailable *declares* a text part, and that the view it names actually
        // renders — a correct declaration pointing at a broken template is the
        // failure this test exists for.
        $content = $mail->content();

        $this->assertSame('statamic-payments::portal.mail.link', $content->text);

        $text = (string) view($content->text, [
            'url' => $mail->url,
            'minutes' => 30,
        ])->render();

        // Unescaped here, and the assertion is the point: `&amp;` in a plain-text
        // body is a link Laravel answers 403 to.
        $this->assertStringContainsString($mail->url, $text);
        $this->assertStringNotContainsString('&amp;', $text);
        $this->assertStringContainsString(__('statamic-payments::portal.mail_link_ignore'), $text);
    }

    #[Test]
    public function the_subject_and_the_sender_come_from_the_site(): void
    {
        config([
            'statamic-payments.portal.from.address' => 'shop@example.de',
            'statamic-payments.portal.from.name' => 'Der Laden',
        ]);

        $envelope = $this->aLinkMail()->envelope();

        $this->assertSame((string) __('statamic-payments::portal.mail_link_subject'), $envelope->subject);
        $this->assertSame('shop@example.de', $envelope->from?->address);
        $this->assertSame('Der Laden', $envelope->from?->name);
    }

    #[Test]
    public function without_a_configured_sender_the_applications_own_from_stands(): void
    {
        config(['statamic-payments.portal.from' => ['address' => null, 'name' => null]]);

        // Null, not an invented address. A host that wraps this mailable to put
        // a brand's own sender on it would have that undone by an assignment
        // here, and the address is the half of the pair a relay checks against
        // the account the transport belongs to.
        $this->assertNull($this->aLinkMail()->envelope()->from);
    }
}
