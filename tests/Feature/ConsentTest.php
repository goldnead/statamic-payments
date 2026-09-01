<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\FollowUp;
use Goldnead\StatamicPayments\Support\PaymentDetails;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Die Zustimmung nach § 356 Abs. 5 BGB, festgehalten statt verworfen.
 *
 * Vorher wurde `confirmed => accepted` geprüft und dann vergessen. Der Code
 * nannte das „the record" — es gab keines. Was hier belegt wird: Zeitpunkt und
 * Wortlaut kommen mit dem ersten INSERT in die Zeile, sie lassen sich danach
 * nicht mehr umschreiben, und ein Nachfassangebot erbt sie nicht.
 */
class ConsentTest extends TestCase
{
    protected const WORTLAUT = 'Ich bestelle kostenpflichtig und stimme zu, dass die Lieferung sofort beginnt. Damit erlischt mein Widerrufsrecht.';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'noten-paket' => ['name' => 'Notenpaket', 'amount_cent' => 1900],
            'begleit-cd' => ['name' => 'Begleit-CD', 'amount_cent' => 1200],
        ]);
        $app['config']->set('statamic-payments.follow_up.enabled', true);
    }

    #[Test]
    public function consent_handed_in_with_the_details_lands_in_both_columns_at_the_first_insert(): void
    {
        $moment = now()->subMinute()->startOfSecond();

        $seen = null;
        $this->gateway->whileCalling = function () use (&$seen) {
            // Was der Anbieter sähe, wenn sein Webhook jetzt einträfe: die
            // Zustimmung steht schon in der Zeile.
            $seen = Payment::query()->first()?->only(['consent_at', 'consent_text']);
        };

        $payment = app(Checkout::class)->start('noten-paket', ['email' => 'kaeufer@example.com'], null, null, [
            'consent_at' => $moment,
            'consent_text' => self::WORTLAUT,
        ])->payment;

        $this->assertNotNull($seen);
        $this->assertSame(self::WORTLAUT, $seen['consent_text']);
        $this->assertTrue($moment->equalTo($payment->consent_at));
        $this->assertSame(self::WORTLAUT, $payment->consent_text);
    }

    #[Test]
    public function without_consent_both_columns_stay_null(): void
    {
        $payment = app(Checkout::class)->start('noten-paket', ['email' => 'kaeufer@example.com'])->payment;

        $this->assertNull($payment->consent_at);
        $this->assertNull($payment->consent_text);
    }

    #[Test]
    public function an_iso_string_is_accepted_and_becomes_a_moment(): void
    {
        $details = PaymentDetails::from([
            'consent_at' => '2026-08-30T10:15:00+02:00',
            'consent_text' => self::WORTLAUT,
        ]);

        $columns = $details->onto([]);

        // Derselbe Augenblick, in welcher Zone auch immer er geschrieben steht.
        $this->assertTrue($columns['consent_at']->equalTo('2026-08-30T08:15:00Z'));
        $this->assertSame(self::WORTLAUT, $columns['consent_text']);
    }

    #[Test]
    public function a_consent_in_the_future_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Zukunft');

        PaymentDetails::from([
            'consent_at' => now()->addHour(),
            'consent_text' => self::WORTLAUT,
        ]);
    }

    #[Test]
    public function an_empty_wording_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('leer');

        PaymentDetails::from(['consent_at' => now(), 'consent_text' => '   ']);
    }

    #[Test]
    public function a_wording_longer_than_the_ceiling_is_refused_rather_than_cut(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaymentDetails::from(['consent_at' => now(), 'consent_text' => str_repeat('x', PaymentDetails::CONSENT_TEXT_MAX + 1)]);
    }

    #[Test]
    public function a_moment_without_a_wording_is_refused(): void
    {
        // Ein Haken ohne Text belegt nichts. Still zu einem Beleg gemacht,
        // hätte der Aufrufer geglaubt, er habe einen.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('gehören zusammen');

        PaymentDetails::from(['consent_at' => now()]);
    }

    #[Test]
    public function consent_once_written_cannot_be_rewritten(): void
    {
        $payment = app(Checkout::class)->start('noten-paket', [], null, null, [
            'consent_at' => now()->subMinute(),
            'consent_text' => self::WORTLAUT,
        ])->payment;

        $this->expectException(LogicException::class);

        $payment->forceFill(['consent_text' => 'Ein anderer Satz.'])->save();
    }

    #[Test]
    public function consent_once_written_cannot_be_erased_either(): void
    {
        $payment = app(Checkout::class)->start('noten-paket', [], null, null, [
            'consent_at' => now()->subMinute(),
            'consent_text' => self::WORTLAUT,
        ])->payment;

        try {
            $payment->forceFill(['consent_at' => null])->save();
            $this->fail('Ein gesetzter Zeitpunkt liess sich löschen.');
        } catch (LogicException) {
            // erwartet
        }

        $this->assertNotNull($payment->fresh()->consent_at);
        $this->assertSame(self::WORTLAUT, $payment->fresh()->consent_text);
    }

    #[Test]
    public function saving_the_same_consent_again_is_not_a_change(): void
    {
        $moment = now()->subMinute()->startOfSecond();

        $payment = app(Checkout::class)->start('noten-paket', [], null, null, [
            'consent_at' => $moment,
            'consent_text' => self::WORTLAUT,
        ])->payment;

        // Ein Webhook, der die Zeile mit denselben Werten anfasst, darf nicht
        // an der Unveränderlichkeit scheitern — sonst scheitert die Erfüllung.
        $payment->forceFill(['consent_at' => $moment, 'consent_text' => self::WORTLAUT, 'status' => Payment::STATUS_PAID])->save();

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
    }

    #[Test]
    public function a_row_created_without_consent_may_receive_it_once(): void
    {
        $payment = app(Checkout::class)->start('noten-paket')->payment;

        $payment->forceFill(['consent_at' => now(), 'consent_text' => self::WORTLAUT])->save();

        $this->assertSame(self::WORTLAUT, $payment->fresh()->consent_text);

        $this->expectException(LogicException::class);
        $payment->forceFill(['consent_text' => 'Nachträglich anders.'])->save();
    }

    #[Test]
    public function a_follow_up_does_not_inherit_the_consent_of_the_original(): void
    {
        $original = $this->paidWithConsent();
        $this->gateway->mandates[] = 'cst_maria';

        $follow = app(FollowUp::class)->accept($original, 'begleit-cd');

        $this->assertNotNull($follow);
        // Jeder Kauf hat seine eigene Zustimmung. Ohne eine mitgegebene
        // bleibt die Folgezahlung leer — und sagt damit die Wahrheit.
        $this->assertNull($follow->consent_at);
        $this->assertNull($follow->consent_text);
    }

    #[Test]
    public function a_follow_up_takes_the_consent_it_is_handed(): void
    {
        $original = $this->paidWithConsent();
        $this->gateway->mandates[] = 'cst_maria';

        $follow = app(FollowUp::class)->accept($original, 'begleit-cd', [], [
            'consent_at' => now()->subSecond(),
            'consent_text' => 'Ja, die Begleit-CD sofort, Widerruf ausgeschlossen.',
        ]);

        $this->assertNotNull($follow);
        $this->assertSame('Ja, die Begleit-CD sofort, Widerruf ausgeschlossen.', $follow->consent_text);
        $this->assertNotNull($follow->consent_at);
    }

    #[Test]
    public function the_offer_endpoint_writes_the_consent_onto_the_follow_up_payment(): void
    {
        // Der Host hat diesen Satz auf seiner Seite und hier eingetragen.
        config()->set('statamic-payments.consent.accepted_texts', [
            'Formular-Wortlaut: sofortige Lieferung, Widerrufsrecht erlischt.',
        ]);

        $original = $this->paidWithConsent();
        $this->gateway->mandates[] = 'cst_maria';

        $this->travelTo(now()->startOfSecond());

        $this->post(route('statamic-payments.offer.accept'), [
            'payment' => $original->getKey(),
            'product' => 'begleit-cd',
            'confirmed' => '1',
            'consent_text' => 'Formular-Wortlaut: sofortige Lieferung, Widerrufsrecht erlischt.',
        ])->assertRedirect();

        $follow = Payment::query()->where('parent_payment_id', $original->getKey())->first();

        $this->assertNotNull($follow);
        $this->assertSame('Formular-Wortlaut: sofortige Lieferung, Widerrufsrecht erlischt.', $follow->consent_text);
        $this->assertTrue(now()->equalTo($follow->consent_at));
    }

    #[Test]
    public function a_tampered_consent_text_is_replaced_by_the_servers_wording_and_logged(): void
    {
        // Ein verstecktes Feld kann jeder umschreiben. Ein Beleg, dessen Text
        // der Käufer bestimmt hat, belegt nichts — also steht der Server-
        // Wortlaut in der Zeile, und das Log sagt, dass etwas anderes kam.
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'consent text mismatch' && $context['submitted'] === 'Ich stimme nicht zu.');
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $original = $this->paidWithConsent();
        $this->gateway->mandates[] = 'cst_maria';

        $this->post(route('statamic-payments.offer.accept'), [
            'payment' => $original->getKey(),
            'product' => 'begleit-cd',
            'confirmed' => '1',
            'consent_text' => 'Ich stimme nicht zu.',
        ])->assertRedirect();

        $follow = Payment::query()->where('parent_payment_id', $original->getKey())->first();

        $this->assertSame(__('statamic-payments::messages.order_consent'), $follow->consent_text);
    }

    #[Test]
    public function the_english_wording_is_an_accepted_text_on_a_german_site(): void
    {
        $original = $this->paidWithConsent();
        $this->gateway->mandates[] = 'cst_maria';

        $english = trans('statamic-payments::messages.order_consent', [], 'en');

        $this->post(route('statamic-payments.offer.accept'), [
            'payment' => $original->getKey(),
            'product' => 'begleit-cd',
            'confirmed' => '1',
            'consent_text' => $english,
        ])->assertRedirect();

        $this->assertSame($english, Payment::query()->where('parent_payment_id', $original->getKey())->first()->consent_text);
    }

    #[Test]
    public function the_offer_endpoint_falls_back_to_the_addons_own_wording(): void
    {
        $original = $this->paidWithConsent();
        $this->gateway->mandates[] = 'cst_maria';

        $this->post(route('statamic-payments.offer.accept'), [
            'payment' => $original->getKey(),
            'product' => 'begleit-cd',
            'confirmed' => '1',
        ])->assertRedirect();

        $follow = Payment::query()->where('parent_payment_id', $original->getKey())->first();

        $this->assertSame(__('statamic-payments::messages.order_consent'), $follow->consent_text);
        $this->assertNotNull($follow->consent_at);
    }

    protected function paidWithConsent(): Payment
    {
        $payment = app(Checkout::class)->start('noten-paket', ['email' => 'kaeufer@example.com'], null, null, [
            'consent_at' => now()->subMinutes(5),
            'consent_text' => self::WORTLAUT,
        ])->payment;

        $payment->forceFill([
            'status' => Payment::STATUS_PAID,
            'paid_at' => now(),
            'fulfilled_at' => now(),
            'customer_reference' => 'cst_maria',
        ])->save();

        return $payment->fresh();
    }
}
