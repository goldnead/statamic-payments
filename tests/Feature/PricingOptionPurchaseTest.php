<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Subscriptions;
use Goldnead\StatamicPayments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Die drei Zahltypen, jeder ueber den echten Kassenweg gekauft.
 *
 * Gelesen wird nicht, was der Aufrufer uebergeben hat, sondern was danach in
 * der Datenbank steht: die Zahlung, die Vereinbarung und die Rechnungszeile.
 * Ein Test, der nur den Rueckgabewert prueft, belegt, dass eine Methode
 * geantwortet hat, und nicht, dass jemand etwas gekauft hat.
 */
class PricingOptionPurchaseTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.follow_up.collect_mandate', true);

        // Auf Deutsch, weil der Wortlaut der Rechnungszeile hier der Gegenstand
        // ist und nicht die Mechanik daneben. „Rate 1 von 3 (Gesamt 1.560,00 €)"
        // ist das, was auf einem Beleg steht, den jemand aufhebt.
        $app['config']->set('app.locale', 'de');

        // Genau die drei Zahlweisen, die ein Angebot mit `pricing_options`
        // dem Katalog vorlegt. Dass sie hier als Produkte stehen und dort
        // aufgeloest werden, ist derselbe Katalog-Eintrag: `offer:x:raten3`
        // ist ein Handle wie jedes andere.
        $app['config']->set('statamic-payments.products', [
            'kurs-einmalig' => [
                'name' => 'ChoirAccelerator',
                'amount_cent' => 150000,
            ],
            'kurs-raten3' => [
                'name' => 'ChoirAccelerator',
                'amount_cent' => 52000,
                'interval' => '1 month',
                'times' => 3,
            ],
            'kurs-abo' => [
                'name' => 'ChoirAccelerator',
                'amount_cent' => 9000,
                'interval' => '1 month',
            ],
        ]);
    }

    /** Bis „bezahlt", so wie der Anbieter es meldet. */
    protected function kaufen(string $handle)
    {
        $plan = app(Subscriptions::class)->planFor($handle);

        $result = $plan
            ? app(Subscriptions::class)->start($handle, ['email' => 'k@example.com', 'name' => 'Kim'])
            : app(Checkout::class)->start($handle, ['email' => 'k@example.com', 'name' => 'Kim']);

        $this->assertNotNull($result, 'die Kasse hat den Kauf abgelehnt');

        $this->gateway->markPaid($result->payment->provider_id);
        $this->postJson(route('statamic-payments.webhook'), ['id' => $result->payment->provider_id]);

        return $result->payment->fresh();
    }

    #[Test]
    public function a_one_off_option_is_charged_once_and_leaves_no_agreement(): void
    {
        $payment = $this->kaufen('kurs-einmalig');

        $this->assertSame(150000, $payment->amount_cent);
        $this->assertSame(0, Subscription::count());

        // Kein Zusatz auf der Zeile: es gibt keine zweite Rate, auf die er
        // sich beziehen koennte.
        $this->assertSame('ChoirAccelerator', $payment->items()->first()->name);
    }

    #[Test]
    public function an_instalment_option_charges_the_instalment_and_names_the_whole(): void
    {
        $payment = $this->kaufen('kurs-raten3');

        // Abgebucht wird die **Rate**, nicht die Summe. § 14 UStG will den
        // Betrag der abgerechneten Leistung, und das ist die Rate.
        $this->assertSame(52000, $payment->amount_cent);

        $agreement = Subscription::query()->firstOrFail();
        $this->assertSame('1 month', $agreement->interval);

        // Zwei, nicht drei: die erste Rate ist der Kauf selbst, und der
        // Anbieter wird nur um die uebrigen gebeten.
        $this->assertSame(2, $agreement->times);

        // Und die Zuordnung steht daneben, sonst stehen auf drei Rechnungen
        // dreimal derselbe Satz und derselbe Betrag.
        $this->assertSame(
            'ChoirAccelerator — Rate 1 von 3 (Gesamt 1.560,00 €)',
            $payment->items()->first()->name,
        );
    }

    #[Test]
    public function a_subscription_option_names_the_rhythm_and_no_total(): void
    {
        $payment = $this->kaufen('kurs-abo');

        $this->assertSame(9000, $payment->amount_cent);

        $agreement = Subscription::query()->firstOrFail();
        $this->assertSame('1 month', $agreement->interval);
        $this->assertNull($agreement->times);

        $zeile = $payment->items()->first()->name;

        // Eine Gesamtsumme steht erst fest, wenn gekuendigt wird. Eine
        // erfundene waere eine Preisangabe, die nicht stimmt.
        $this->assertStringContainsString('ChoirAccelerator', $zeile);
        $this->assertStringNotContainsString('Gesamt', $zeile);
    }

    #[Test]
    public function an_instalment_option_without_a_mandate_capable_method_starts_nothing(): void
    {
        // Ein Betrieb, der nur Klarna und Ueberweisung freigeschaltet hat.
        // Keine der beiden hinterlaesst ein Mandat, also kaeme nach der ersten
        // Rate nie wieder eine — und bis hierher lief der Kauf trotzdem durch:
        // 520 Euro abgebucht, zwei Raten, die nirgends standen.
        config(['statamic-payments.methods' => ['klarna', 'banktransfer']]);

        $this->assertFalse(app(Subscriptions::class)->canStart());
        $this->assertNull(app(Subscriptions::class)->start('kurs-raten3', ['email' => 'k@example.com']));

        // Nichts angelegt: keine Zahlung, keine Vereinbarung. Ein halber Kauf
        // waere schlimmer als keiner.
        $this->assertSame(0, Subscription::count());
        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function a_one_off_option_stays_buyable_without_a_mandate_capable_method(): void
    {
        config(['statamic-payments.methods' => ['klarna', 'banktransfer']]);

        // Die Verengung gilt nur, wo ein Plan im Spiel ist. Eine einmalige
        // Zahlung braucht kein Mandat, und Klarna abzuschalten, weil daneben
        // eine Ratenoption steht, waere ein verlorener Verkauf.
        $payment = $this->kaufen('kurs-einmalig');

        $this->assertSame(150000, $payment->amount_cent);
    }

    #[Test]
    public function a_plan_narrows_the_offered_methods_to_the_ones_that_can_hold_a_mandate(): void
    {
        config(['statamic-payments.methods' => ['creditcard', 'klarna', 'banktransfer']]);

        app(Subscriptions::class)->start('kurs-raten3', ['email' => 'k@example.com', 'name' => 'Kim']);

        // Klarna neben der Karte hiesse: der Kaeufer waehlt Klarna, der
        // Anbieter lehnt die erste Zahlung mit Mandat ab, und der Abbruch
        // passiert an einer Stelle, an der schon alles ausgefuellt war.
        $this->assertSame(['creditcard'], (array) $this->gateway->lastPayload['method']);
        $this->assertSame('first', $this->gateway->lastPayload['sequenceType']);
    }
}
