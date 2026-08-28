<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use ArrayObject;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\FollowUp;
use Goldnead\StatamicPayments\Support\PaymentDetails;
use Goldnead\StatamicPayments\Support\Subscriptions;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Angaben, die die aufrufende Strecke an eine Zahlung heftet.
 *
 * Jeder Test hier prüft dieselbe eine Sache aus einer anderen Richtung: **die
 * Angaben stehen in der Datenbank, bevor der Anbieter gerufen wird.** Nicht
 * kurz danach, nicht meistens davor.
 *
 * Der Unterschied ist keine Feinheit. Ein Aufrufer, der die Anschrift nachträgt,
 * verliert gegen einen Webhook, der schneller ist als er, und heraus kommt eine
 * Rechnung ohne Anschrift — eine fehlende Pflichtangabe auf einem Beleg, den
 * niemand mehr ändern darf.
 *
 * Belegt wird das nicht durch Lesen des Codes, sondern durch einen Anbieter, der
 * im Augenblick seines Aufrufs selbst in die Datenbank sieht: `whileCalling`.
 */
class CallerDetailsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'noten-paket' => ['name' => 'Notenpaket', 'amount_cent' => 1900],
            'begleit-cd' => ['name' => 'Begleit-CD', 'amount_cent' => 39900],
            'monatsabo' => ['name' => 'Monatsabo', 'amount_cent' => 1900, 'interval' => '1 month'],
            'gratis-test' => [
                'name' => 'Gratis-Test',
                'amount_cent' => 1900,
                'interval' => '1 month',
                'trial_days' => 14,
                'trial_amount_cent' => 0,
            ],
        ]);
        $app['config']->set('statamic-payments.follow_up.enabled', true);
    }

    protected function paidPayment(): Payment
    {
        $payment = app(Checkout::class)->start('noten-paket', ['email' => 'kaeuferin@example.com'])->payment;

        $payment->forceFill([
            'status' => Payment::STATUS_PAID,
            'paid_at' => now(),
            'fulfilled_at' => now(),
            'customer_reference' => 'cst_maria',
        ])->save();

        $this->gateway->mandates[] = 'cst_maria';

        return $payment->fresh();
    }

    /**
     * Was ein Webhook sähe, der in diesem Augenblick einträfe.
     *
     * Zwei Dinge machen das zu einem Beleg und nicht zu einer Behauptung:
     *
     * 1. Gelesen wird frisch aus der Datenbank, nicht aus dem Model, das der
     *    Aufrufer in der Hand hält. Ein Model kann Werte tragen, die noch
     *    nirgends stehen.
     * 2. Zugesichert wird, dass keine Transaktion mehr offen ist. Ohne das
     *    hiesse „geschrieben" nur „geschrieben", und ein Webhook kommt auf
     *    einer anderen Verbindung an, die Ungeschriebenes nicht sieht.
     *
     * @return ArrayObject<string, mixed>
     */
    protected function beimAnbieteraufruf(): ArrayObject
    {
        /** @var ArrayObject<string, mixed> $gesehen */
        $gesehen = new ArrayObject;

        $this->gateway->whileCalling = function (array $payload) use ($gesehen) {
            $id = $payload['metadata']['payment_id'] ?? null;
            $zeile = $id ? Payment::find($id) : null;

            $gesehen['meta'] = $zeile?->meta;
            $gesehen['country'] = $zeile?->country;
            $gesehen['country_source'] = $zeile?->country_source;
            $gesehen['exists'] = $zeile !== null;
            $gesehen['committed'] = DB::transactionLevel() === 0;
        };

        return $gesehen;
    }

    #[Test]
    public function a_follow_up_carries_the_callers_details_before_the_provider_is_called(): void
    {
        $original = $this->paidPayment();
        $gesehen = $this->beimAnbieteraufruf();

        $follow = app(FollowUp::class)->accept($original, 'begleit-cd', [], [
            'meta' => ['thanks_ref' => 'dnk_9f3', 'address' => ['street' => 'Hauptstr. 1', 'city' => 'Köln']],
            'country' => 'DE',
        ]);

        $this->assertNotNull($follow);

        // Der Kern. Nicht „am Ende steht es da", sondern: es stand schon da,
        // als das Geld bewegt wurde.
        $this->assertTrue($gesehen['exists']);
        $this->assertTrue($gesehen['committed'], 'Die Zeile lag noch in einer offenen Transaktion.');
        $this->assertSame('dnk_9f3', $gesehen['meta']['thanks_ref']);
        $this->assertSame('Hauptstr. 1', $gesehen['meta']['address']['street']);
        $this->assertSame('DE', $gesehen['country']);
        $this->assertSame('caller', $gesehen['country_source']);
    }

    #[Test]
    public function a_country_the_caller_gives_is_normalised_like_any_other(): void
    {
        $original = $this->paidPayment();

        $follow = app(FollowUp::class)->accept($original, 'begleit-cd', [], ['country' => ' at ']);

        $this->assertSame('AT', $follow->country);
    }

    #[Test]
    public function a_country_that_is_not_a_country_code_is_refused_rather_than_dropped(): void
    {
        $original = $this->paidPayment();

        $this->expectException(InvalidArgumentException::class);

        try {
            app(FollowUp::class)->accept($original, 'begleit-cd', [], ['country' => 'Deutschland']);
        } finally {
            // Nichts angelegt, nichts belastet. Ein Aufrufer-Fehler kostet kein
            // Geld, weil er auffliegt, bevor irgendetwas passiert.
            $this->assertSame(0, Payment::where('parent_payment_id', $original->getKey())->count());
            $this->assertSame(1, $this->gateway->created);
        }
    }

    /**
     * Die Felder, die dem Paket gehören.
     *
     * Ein still verworfener Betrag sähe für den Aufrufer aus wie ein gesetzter,
     * und der Unterschied zwischen 399 EUR und 0 EUR fällt erst beim Kontoauszug
     * auf. Also eine Ausnahme, und zwar bevor eine Zeile entsteht.
     */
    #[Test]
    public function the_packages_own_fields_cannot_be_given_along(): void
    {
        $original = $this->paidPayment();

        foreach (['amount_cent' => 1, 'status' => 'paid', 'parent_payment_id' => 99, 'provider_id' => 'tr_egal', 'product' => 'noten-paket'] as $feld => $wert) {
            try {
                app(FollowUp::class)->accept($original, 'begleit-cd', [], [$feld => $wert]);
                $this->fail('`'.$feld.'` wurde angenommen.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($feld, $e->getMessage());
            }
        }

        $this->assertSame(0, Payment::where('parent_payment_id', $original->getKey())->count());
        $this->assertSame(1, $this->gateway->created);
    }

    /**
     * Über die Liste selbst, nicht über einen Vertreter.
     *
     * `refunds` ist der mit der unangenehmsten Folge: wer ihn setzen könnte,
     * könnte eine erneut zugestellte Erstattung zweimal zählen lassen.
     */
    #[Test]
    public function meta_keys_the_package_runs_itself_cannot_be_given_along(): void
    {
        $original = $this->paidPayment();

        foreach (PaymentDetails::RESERVED_META as $key) {
            try {
                app(FollowUp::class)->accept($original, 'begleit-cd', [], ['meta' => [$key => 'egal']]);
                $this->fail('`meta.'.$key.'` wurde angenommen.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($key, $e->getMessage());
            }
        }

        $this->assertSame(0, Payment::where('parent_payment_id', $original->getKey())->count());
        $this->assertSame(1, $this->gateway->created);
    }

    /**
     * Die Herkunft des Landes darf der Aufrufer benennen.
     *
     * Wer ein Land aus einer früheren Zahlung übernimmt, übernimmt auch deren
     * Nachweiskraft: „der Kartenherausgeber sagt es" ist ein anderer Beleg als
     * „jemand hat es getippt", und die EU zählt beide einzeln.
     */
    #[Test]
    public function the_caller_may_name_where_the_country_came_from(): void
    {
        $original = $this->paidPayment();

        $follow = app(FollowUp::class)->accept($original, 'begleit-cd', [], [
            'country' => 'DE',
            'country_source' => 'mollie',
        ]);

        $this->assertSame('mollie', $follow->country_source);
    }

    #[Test]
    public function a_country_source_without_a_country_describes_nothing(): void
    {
        $original = $this->paidPayment();

        $this->expectException(InvalidArgumentException::class);

        app(FollowUp::class)->accept($original, 'begleit-cd', [], ['country_source' => 'mollie']);
    }

    #[Test]
    public function a_caller_that_gives_nothing_behaves_exactly_as_before(): void
    {
        $original = $this->paidPayment();

        $follow = app(FollowUp::class)->accept($original, 'begleit-cd');

        $this->assertNotNull($follow);
        $this->assertNull($follow->meta);
        $this->assertNull($follow->country);
        $this->assertNull($follow->country_source);
        $this->assertSame(39900, $follow->amount_cent);
        $this->assertSame($original->getKey(), $follow->parent_payment_id);
    }

    #[Test]
    public function a_checkout_carries_the_callers_details_before_the_provider_is_called(): void
    {
        $gesehen = $this->beimAnbieteraufruf();

        $result = app(Checkout::class)->start('noten-paket', ['email' => 'k@example.com'], null, null, [
            'meta' => ['thanks_ref' => 'dnk_1'],
            'country' => 'CH',
        ]);

        $this->assertNotNull($result);
        $this->assertTrue($gesehen['committed'], 'Die Zeile lag noch in einer offenen Transaktion.');
        $this->assertSame('dnk_1', $gesehen['meta']['thanks_ref']);
        $this->assertSame('CH', $gesehen['country']);
        $this->assertSame('caller', $gesehen['country_source']);
    }

    /**
     * Das Land des Käufers hat weiter eine Quelle: das Formular.
     *
     * `$buyer['country']` ist der Weg, den es am Checkout immer gab. Die neue
     * Naht macht keinen zweiten daneben auf, sondern füllt die Spalte nur, wenn
     * sie sonst leer bliebe.
     */
    #[Test]
    public function what_the_buyer_stated_is_not_overwritten_by_the_caller(): void
    {
        $result = app(Checkout::class)->start('noten-paket', ['email' => 'k@example.com', 'country' => 'AT'], null, null, [
            'country' => 'DE',
        ]);

        $this->assertSame('AT', $result->payment->country);
        $this->assertSame('checkout', $result->payment->country_source);
    }

    #[Test]
    public function a_subscription_records_its_intention_before_the_provider_is_called(): void
    {
        config(['statamic-payments.follow_up.collect_mandate' => true]);

        $gesehen = $this->beimAnbieteraufruf();

        $result = app(Subscriptions::class)->start('monatsabo', ['email' => 'k@example.com'], null, [
            'meta' => ['thanks_ref' => 'dnk_abo'],
        ]);

        $this->assertNotNull($result);

        // Beides in derselben Zeile, beides vor dem Anbieter: die Absicht des
        // Pakets und die Angabe des Aufrufers.
        $this->assertTrue($gesehen['committed'], 'Die Zeile lag noch in einer offenen Transaktion.');
        $this->assertSame('monatsabo', $gesehen['meta']['subscription_intent']['product']);
        $this->assertSame('dnk_abo', $gesehen['meta']['thanks_ref']);
    }

    /**
     * Ein Testzeitraum ohne Betrag.
     *
     * Hier wird der Anbieter nie gerufen: der Katalog preist die Bestellung mit
     * null, und `Checkout::start()` erfüllt sie selbst, noch bevor es zurückkehrt.
     * Solange die Absicht danach nachgetragen wurde, sah `startFromPayment()`
     * nichts und tat nichts — ein bezahltes Abo, das keines wurde, ohne eine
     * einzige Logzeile.
     */
    #[Test]
    public function a_trial_without_a_charge_records_its_intention_before_it_is_fulfilled(): void
    {
        config(['statamic-payments.follow_up.collect_mandate' => true]);

        /** @var ArrayObject<string, mixed> $beiErfuellung */
        $beiErfuellung = new ArrayObject;

        // Der Augenblick, in dem `startFromPayment()` nach der Absicht sieht.
        Event::listen(PaymentPaid::class, function (PaymentPaid $event) use ($beiErfuellung) {
            $beiErfuellung['meta'] = Payment::find($event->payment->getKey())?->meta;
        });

        Log::spy();

        $result = app(Subscriptions::class)->start('gratis-test', ['email' => 'k@example.com']);

        $this->assertNotNull($result);
        $this->assertTrue($result->payment->isFulfilled());
        $this->assertSame('gratis-test', $beiErfuellung['meta']['subscription_intent']['product']);

        // Und wie es ausgeht: ohne Belastung merkt der Anbieter niemanden, ohne
        // Mandat entsteht kein Abo. Das war vorher auch so, nur ohne Ton. Der
        // Ton ist der Fortschritt, und diese Zusicherung hält ihn fest.
        $this->assertSame(0, Subscription::count());

        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message) => str_contains($message, 'left no mandate behind')
        )->once();
    }

    /**
     * Ein Zyklus erbt, was seine erste Zahlung mitbekam.
     *
     * Hier gibt es keinen Aufrufer: der Anbieter bucht von sich aus ab, und die
     * Zeile entsteht im Webhook. Ohne das Erben hätte eine Abo-Rechnung ab dem
     * zweiten Monat keine Anschrift, und ein Abo ist die Umsatzart, die die
     * 250-EUR-Grenze am ehesten reißt.
     */
    #[Test]
    public function a_cycle_inherits_what_the_first_payment_was_given(): void
    {
        config(['statamic-payments.follow_up.collect_mandate' => true]);

        $result = app(Subscriptions::class)->start('monatsabo', ['email' => 'k@example.com'], null, [
            'meta' => ['address' => 'Hauptstr. 1, 50667 Köln'],
        ]);

        $this->gateway->markPaid($result->payment->provider_id);
        $this->postJson(route('statamic-payments.webhook'), ['id' => $result->payment->provider_id]);

        $subscription = Subscription::first();
        $this->assertNotNull($subscription);

        $zyklus = $this->gateway->arrive('monatsabo', 1900, $subscription->provider_id);
        $this->postJson(route('statamic-payments.webhook'), ['id' => $zyklus]);

        $zweite = Payment::where('provider_id', $zyklus)->first();

        $this->assertNotNull($zweite);
        $this->assertSame('Hauptstr. 1, 50667 Köln', $zweite->meta['address']);

        // Der Zeiger, den ein Listener auf `PaymentPaid` braucht: die Spalte
        // `subscription_id` steht zu diesem Zeitpunkt noch nicht, weil sie der
        // Anspruch ist, an dem eine zweite Zustellung scheitert.
        $this->assertSame($subscription->getKey(), $zweite->meta['cycle_of']['subscription_id']);
        $this->assertSame($result->payment->getKey(), $zweite->meta['cycle_of']['first_payment_id']);

        // Was das Paket auf der ersten Zahlung führt, gehört ihr allein.
        $this->assertArrayNotHasKey('subscription_intent', $zweite->meta);
    }
}
