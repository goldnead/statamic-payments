<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\PaymentDetails;
use Goldnead\StatamicPayments\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Where the sale came from, recorded while it still exists.
 *
 * Same argument as the tax facts: a visitor arrives from a newsletter, browses
 * for three days and buys. By the time the money lands, the campaign lives
 * nowhere but in that session. Not written down at the checkout, the question
 * "which newsletter sold anything" is unanswerable forever.
 */
class AttributionSurvivesTheCheckoutTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'noten' => ['name' => 'Notenpaket', 'amount_cent' => 1000],
        ]);
    }

    #[Test]
    public function the_campaign_the_host_hands_in_is_frozen_on_the_payment(): void
    {
        $ergebnis = app(Checkout::class)->start(['noten'], ['email' => 'wer@example.com'], null, null, [
            'utm_source' => 'newsletter',
            'utm_medium' => 'email',
            'utm_campaign' => 'sommer-2026',
            'utm_term' => 'stimmbildung',
            'utm_content' => 'button-oben',
            'referrer' => 'https://example.com/newsletter/12',
            'landing_page' => 'https://adriangoldner.com/kurse?utm_campaign=sommer-2026',
        ]);

        $zahlung = $ergebnis->payment;

        $this->assertSame('newsletter', $zahlung->utm_source);
        $this->assertSame('email', $zahlung->utm_medium);
        $this->assertSame('sommer-2026', $zahlung->utm_campaign);
        $this->assertSame('stimmbildung', $zahlung->utm_term);
        $this->assertSame('button-oben', $zahlung->utm_content);
        $this->assertSame('https://example.com/newsletter/12', $zahlung->referrer);
        $this->assertStringContainsString('sommer-2026', (string) $zahlung->landing_page);
    }

    #[Test]
    public function a_checkout_without_a_campaign_leaves_the_columns_empty(): void
    {
        $ergebnis = app(Checkout::class)->start(['noten'], ['email' => 'wer@example.com']);

        $this->assertNull($ergebnis->payment->utm_campaign);
        $this->assertNull($ergebnis->payment->referrer);
    }

    /**
     * A visitor's URL is not a caller's mistake.
     *
     * Some link builder appends four thousand characters of tracking noise, and
     * no purchase may fail over it. Cut, not refused — and cut here rather than
     * by the database, which would either truncate silently or reject the whole
     * insert depending on its mood.
     */
    #[Test]
    public function an_over_long_value_is_cut_rather_than_refusing_the_sale(): void
    {
        $lang = str_repeat('a', 4000);

        $ergebnis = app(Checkout::class)->start(['noten'], ['email' => 'wer@example.com'], null, null, [
            'utm_campaign' => $lang,
            'referrer' => 'https://example.com/'.$lang,
        ]);

        $this->assertSame(255, mb_strlen((string) $ergebnis->payment->utm_campaign));
        $this->assertSame(1024, mb_strlen((string) $ergebnis->payment->referrer));
    }

    /** An empty string says the same as nothing, and nothing is the honest column. */
    #[Test]
    public function blank_values_are_dropped_instead_of_stored(): void
    {
        $ergebnis = app(Checkout::class)->start(['noten'], ['email' => 'wer@example.com'], null, null, [
            'utm_campaign' => '   ',
        ]);

        $this->assertNull($ergebnis->payment->utm_campaign);
    }

    /**
     * A wrong type is a wrong program, and it says so.
     *
     * The split from the length rule above is the whole design: what a stranger
     * typed may be cut, what the host coded may not be guessed at.
     */
    #[Test]
    public function a_non_string_campaign_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaymentDetails::from(['utm_campaign' => ['sommer']]);
    }

    /** The whitelist still holds; a column the package owns cannot be handed in. */
    #[Test]
    public function an_unknown_key_is_still_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaymentDetails::from(['amount_cent' => 1]);
    }

    /**
     * Rows taken before any of this existed stay readable.
     *
     * Every migration in this package leaves old rows null rather than guessing,
     * and everything downstream has to tolerate that instead of assuming.
     */
    #[Test]
    public function a_payment_written_before_the_columns_existed_still_reads(): void
    {
        $zahlung = Payment::create([
            'provider' => 'mollie',
            'provider_id' => 'tr_alt',
            'product' => 'noten',
            'amount_cent' => 1000,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'alt@example.com',
        ]);

        $this->assertNull($zahlung->fresh()->utm_campaign);
    }
}
