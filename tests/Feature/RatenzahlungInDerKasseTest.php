<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Subscriptions;
use Goldnead\StatamicPayments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Eine Ratenzahlung, wie sie in einer Kasse mit Korb entsteht.
 *
 * Drei Eigenschaften, und jede hat hier einen Test, der sie zu brechen
 * versucht:
 *
 * 1. **Eine Vereinbarung darf aus einem Korb beginnen.** Bis 07.09.2026 nahm
 *    `Subscriptions::start()` genau einen Handle. Eine Strecke mit Bumps musste
 *    deshalb an `Checkout::start()` vorbei — und dort gibt es keinen Plan. Das
 *    Ergebnis war eine einzige Abbuchung ohne Fehlermeldung: „3 x 520 Euro"
 *    verkauft, 520 Euro kassiert, voller Zugang erteilt.
 * 2. **Wer eine Rate wählt, sieht keine Zahlungsart, die keine Raten kann.**
 *    Klarna oder Überweisung neben der Karte in der Konfiguration hieß: der
 *    Käufer wählt sie, und der Anbieter lehnt die `sequenceType: first` ab —
 *    ein Abbruch, nachdem alles ausgefüllt war.
 * 3. **Jede Rechnung sagt, welche Rate sie ist.** Sonst stehen auf drei
 *    Rechnungen dreimal derselbe Satz und derselbe Betrag, und niemand sieht,
 *    dass es dieselbe Leistung war.
 */
class RatenzahlungInDerKasseTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.follow_up.collect_mandate', true);
        $app['config']->set('statamic-payments.products', [
            'kurs-in-raten' => [
                'name' => 'Chorleitungskurs',
                'amount_cent' => 52000,
                'interval' => '1 month',
                'times' => 3,
            ],
            'abo' => [
                'name' => 'Mitgliedschaft',
                'amount_cent' => 1900,
                'interval' => '1 month',
            ],
            'uebungsblaetter' => ['name' => 'Übungsblätter', 'amount_cent' => 900],
            'einmalig' => ['name' => 'Einzelkauf', 'amount_cent' => 4900],
        ]);
    }

    protected function subs(): Subscriptions
    {
        return app(Subscriptions::class);
    }

    #[Test]
    public function ein_betrieb_ohne_mandate_kann_keine_vereinbarung_beginnen(): void
    {
        $this->assertTrue($this->subs()->canStart());

        config(['statamic-payments.follow_up.collect_mandate' => false]);
        $this->assertFalse($this->subs()->canStart());

        config(['statamic-payments.follow_up.collect_mandate' => true]);
        $this->gateway->refuseSubscriptions = true;
        $this->assertFalse($this->subs()->canStart());
    }

    #[Test]
    public function eine_vereinbarung_beginnt_auch_aus_einem_korb(): void
    {
        $result = $this->subs()->start(
            ['kurs-in-raten', 'uebungsblaetter'],
            ['email' => 'k@example.com', 'name' => 'Kim'],
        );

        $this->assertNotNull($result, 'die Kasse hat den Korb abgelehnt');

        $payment = $result->payment->fresh();

        // Die erste Zahlung trägt den ganzen Korb.
        $this->assertSame(52900, $payment->amount_cent);
        $this->assertSame(2, $payment->items()->count());

        // Und die Absicht, aus der der Webhook später die Vereinbarung baut.
        // Ohne sie wäre das hier eine gewöhnliche Einmalzahlung, die niemandem
        // auffällt.
        $intent = $payment->meta['subscription_intent'] ?? null;

        $this->assertIsArray($intent);
        $this->assertSame('kurs-in-raten', $intent['product']);
        $this->assertSame('1 month', $intent['interval']);
        $this->assertSame(3, $intent['times']);
    }

    #[Test]
    public function der_rhythmus_haengt_am_ersten_handle_nicht_am_korb(): void
    {
        // Umgekehrt herum: vorne etwas Einmaliges, hinten die Raten. Dann ist
        // es kein Ratenkauf, und nichts wird still zu einem.
        $this->assertNull($this->subs()->start(['einmalig', 'kurs-in-raten']));
        $this->assertSame(0, Subscription::count());
    }

    #[Test]
    public function die_folgeeinzuege_belasten_nur_die_rate_nicht_den_bump(): void
    {
        $result = $this->subs()->start(
            ['kurs-in-raten', 'uebungsblaetter'],
            ['email' => 'k@example.com'],
        );

        $this->gateway->markPaid($result->payment->provider_id);
        $this->postJson(route('statamic-payments.webhook'), ['id' => $result->payment->provider_id]);

        $abo = Subscription::first();

        $this->assertNotNull($abo, 'aus der bezahlten Erstzahlung wurde keine Vereinbarung');

        // Der Bump ist einmal gekauft. Stünde er im Betrag der Vereinbarung,
        // zahlte der Käufer die Übungsblätter dreimal.
        $this->assertSame(52000, $abo->amount_cent);
        $this->assertSame('kurs-in-raten', $abo->product);

        // Zwei verbleibende Einzüge, nicht drei: die erste Rate ist geflossen.
        $this->assertSame(2, $abo->times);
    }

    #[Test]
    public function die_erste_zeile_sagt_welche_rate_sie_ist(): void
    {
        $result = $this->subs()->start(
            ['kurs-in-raten', 'uebungsblaetter'],
            ['email' => 'k@example.com'],
        );

        $zeilen = $result->payment->items()->orderBy('id')->get();

        $this->assertSame(
            'Chorleitungskurs — instalment 1 of 3 (total 1.560,00 €)',
            $zeilen[0]->name,
        );

        // Der Bump nicht. Er gehört zu keiner Rate.
        $this->assertSame('Übungsblätter', $zeilen[1]->name);
    }

    #[Test]
    public function die_zeile_steht_in_der_sprache_der_seite(): void
    {
        // Die Suite läuft auf Englisch, Adrians Seite auf Deutsch. Der Zusatz
        // landet unveränderlich auf einer Rechnung — steht dort ein
        // Übersetzungsschlüssel statt eines Satzes, ist das Dokument fehlerhaft
        // und lässt sich nicht mehr korrigieren. Genau so stand
        // `trial_discount` bis 07.09.2026 in keiner der beiden Sprachdateien.
        $this->app->setLocale('de');

        $result = $this->subs()->start('kurs-in-raten', ['email' => 'k@example.com']);

        $this->assertSame(
            'Chorleitungskurs — Rate 1 von 3 (Gesamt 1.560,00 €)',
            $result->payment->items()->first()->name,
        );
    }

    #[Test]
    public function ein_abo_ohne_ende_nennt_den_takt_statt_einer_gesamtsumme(): void
    {
        $result = $this->subs()->start('abo', ['email' => 'k@example.com']);

        // Es gibt keine Gesamtsumme. Sie steht erst fest, wenn gekündigt wird,
        // und eine erfundene wäre eine Preisangabe, die nicht stimmt.
        $this->assertSame(
            'Mitgliedschaft — monthly',
            $result->payment->items()->first()->name,
        );
    }

    #[Test]
    public function eine_gewoehnliche_zahlung_traegt_keinen_zusatz(): void
    {
        $payment = app(Checkout::class)->start(['einmalig', 'uebungsblaetter'])->payment;

        $zeilen = $payment->items()->orderBy('id')->get();

        $this->assertSame('Einzelkauf', $zeilen[0]->name);
        $this->assertSame('Übungsblätter', $zeilen[1]->name);
    }

    #[Test]
    public function der_zweite_einzug_traegt_den_namen_aus_dem_katalog_und_seine_nummer(): void
    {
        $result = $this->subs()->start('kurs-in-raten', ['email' => 'k@example.com']);

        $this->gateway->markPaid($result->payment->provider_id);
        $this->postJson(route('statamic-payments.webhook'), ['id' => $result->payment->provider_id]);

        $abo = Subscription::first();

        // Der Anbieter bucht ab und meldet es. Genau so entsteht ein Zyklus:
        // eine Zahlung, die niemand hier angelegt hat.
        $zyklus = $this->gateway->arrive('kurs-in-raten', 52000, $abo->provider_id);

        $this->postJson(route('statamic-payments.webhook'), ['id' => $zyklus]);

        $zahlung = Payment::query()->where('provider_id', $zyklus)->first();

        $this->assertNotNull($zahlung, 'der Zyklus hat keine Zahlung hinterlassen');

        $zeile = $zahlung->items()->first();

        $this->assertNotNull($zeile, 'der Zyklus hat keine Position — jeder Bericht über Zeilen ließe ihn aus');

        // Bis 07.09.2026 stand hier der rohe Handle, und die Rechnung des
        // zweiten Monats las sich anders als die des ersten.
        $this->assertSame(
            'Chorleitungskurs — instalment 2 of 3 (total 1.560,00 €)',
            $zeile->name,
        );
        $this->assertSame(PaymentItem::KIND_PRIMARY, $zeile->kind);
    }

    #[Test]
    public function eine_zahlart_die_kein_mandat_hinterlaesst_wird_gar_nicht_erst_gezeigt(): void
    {
        config(['statamic-payments.methods' => 'creditcard,klarna,banktransfer']);

        $this->subs()->start('kurs-in-raten', ['email' => 'k@example.com']);

        // Klarna und Überweisung können nicht automatisch abbuchen. Sie neben
        // der Karte anzubieten heißt: der Käufer wählt eine davon und der
        // Anbieter lehnt ab — mitten in der Kasse, nach dem Ausfüllen.
        $this->assertSame('creditcard', $this->gateway->lastPayload['method']);
        $this->assertSame('first', $this->gateway->lastPayload['sequenceType']);
    }

    #[Test]
    public function eine_leere_zahlart_liste_bleibt_leer(): void
    {
        config(['statamic-payments.methods' => null]);

        $this->subs()->start('kurs-in-raten', ['email' => 'k@example.com']);

        // Leer heißt „der Anbieter entscheidet", und der zeigt bei einer
        // ersten Zahlung von selbst nur, was ein Mandat kann. Hier eine Liste
        // zu erfinden schaltete eine Zahlungsart ab, die er morgen freischaltet.
        $this->assertArrayNotHasKey('method', $this->gateway->lastPayload);
        $this->assertSame('first', $this->gateway->lastPayload['sequenceType']);
    }
}
