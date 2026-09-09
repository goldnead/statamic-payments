<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Brands;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Support\Subscriptions;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

/**
 * Eine Vereinbarung gehoert der Marke, die sie verkauft hat.
 *
 * `startFromPayment()` schrieb `'brand_id' => $payment->brand_id`, obwohl der
 * Katalogeintrag zwei Zeilen darueber schon geladen war. Als Erbe ist das
 * richtig gedacht — die Vereinbarung entsteht im Webhook, wo keine Marke gilt —
 * und beantwortet trotzdem die falsche Frage: gefragt ist nicht „wessen Zahlung
 * war die erste", sondern „wessen Angebot laeuft hier weiter".
 *
 * **Der Unterschied zum Upsell ist die Dauer.** Ein falsch gestempeltes Upsell
 * ist eine Zeile; ein falsch gestempeltes Abo ist jede Rechnung, jeder Zyklus
 * und die Sichtbarkeit im Portal, bis jemand es von Hand umtraegt.
 *
 * Dieselbe Regel wie in {@see FollowUp::accept()}, und ausdruecklich derselbe
 * Code: {@see Brands::forCatalogueEntry()}. Das Angebot gewinnt, bei fehlender
 * Marke bleibt das Erbe, beides steht im Log. Zwei Kopien einer Entscheidung
 * ueber Geld waeren die eine, die spaeter etwas dazulernt, und die andere.
 *
 * Aufbau wie in {@see UpsellMarkeKommtVomAngebotTest}: die *fremde* Marke ist
 * die aktive, und vor der Tat wird geprueft, dass sie es wirklich ist.
 */
class AboMarkeKommtVomAngebotTest extends TestCase
{
    /** Die Marke der ersten Zahlung. */
    protected const MARKE_A = 1;

    /** Die Marke, der das Abo-Angebot gehoert. */
    protected const MARKE_B = 2;

    /** Die Marke, unter der die Anfrage laeuft. Keine der beiden anderen. */
    protected const FREMDE = 7;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.follow_up.collect_mandate', true);
        $app['config']->set('statamic-payments.products', [
            // Gehoert Marke B. `Catalogue::find()` reicht durch, was der
            // Katalog deklariert — derselbe Weg, auf dem `interval` herkommt.
            'mitgliedschaft' => [
                'name' => 'Mitgliedschaft',
                'amount_cent' => 1900,
                'interval' => '1 month',
                'brand_id' => self::MARKE_B,
            ],
            // Und eins ohne Marke: Altbestand, Seeder, Import.
            'schweigsam' => [
                'name' => 'Schweigsame Mitgliedschaft',
                'amount_cent' => 1900,
                'interval' => '1 month',
            ],
            // Ein Katalog ist offen. Was ein fremder Resolver hier hineinlegt,
            // ist keine Marke, und ein blosser `(int)`-Cast machte daraus die 1.
            'unsinn' => [
                'name' => 'Unsinnige Mitgliedschaft',
                'amount_cent' => 1900,
                'interval' => '1 month',
                'brand_id' => ['nicht', 'zu', 'gebrauchen'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Catalogue::forgetResolvers();

        parent::tearDown();
    }

    protected function subs(): Subscriptions
    {
        return app(Subscriptions::class);
    }

    /** Einzelmarken-Installation, beziehungsweise ein Geschwister, das schweigt. */
    protected function markenlage(bool $multi, bool $wirft = false): void
    {
        $this->app->instance('brand-context', new class($multi, $wirft)
        {
            public function __construct(protected bool $multi, protected bool $wirft) {}

            public function multiBrandEnabled(): bool
            {
                if ($this->wirft) {
                    throw new \RuntimeException('die Lizenzpruefung antwortet nicht');
                }

                return $this->multi;
            }

            public function hasCurrent(): bool
            {
                return false;
            }

            public function currentId(): int
            {
                return 0;
            }
        });
    }

    /** Mehrmarken-Betrieb, und `$current` ist die aktive Marke. */
    protected function marke(int $current = self::FREMDE): void
    {
        $this->app->instance('brand-context', new class($current)
        {
            public function __construct(protected int $current) {}

            public function multiBrandEnabled(): bool
            {
                return true;
            }

            public function hasCurrent(): bool
            {
                return true;
            }

            public function currentId(): int
            {
                return $this->current;
            }
        });
    }

    /**
     * Eine bezahlte erste Zahlung mit Mandat, gestempelt auf `$marke`.
     *
     * Der Weg der Vereinbarung ist danach der echte: `startFromPayment()`,
     * also das, was der Webhook ruft.
     */
    protected function ersteZahlung(string $product = 'mitgliedschaft', int $marke = self::MARKE_A): Payment
    {
        $result = $this->subs()->start($product, ['email' => 'k@example.com', 'name' => 'Kim']);

        $this->assertNotNull($result, 'die Kasse fuer die erste Zahlung wurde abgelehnt');

        $payment = $result->payment;

        $this->gateway->markPaid($payment->provider_id);

        $payment->forceFill([
            'status' => Payment::STATUS_PAID,
            'paid_at' => now(),
            'customer_reference' => 'cst_kim',
            'brand_id' => $marke,
        ])->save();

        $this->gateway->mandates[] = 'cst_kim';

        return $payment->fresh();
    }

    /**
     * Dass wirklich die fremde Marke gilt.
     *
     * Haette der Aufbau versehentlich schon Marke B aktiv, waere der Test
     * gruen, ohne irgendetwas zu belegen.
     */
    protected function fremdeMarkeGiltJetzt(): void
    {
        $this->assertSame(self::FREMDE, Brands::stampId(), 'der Aufbau setzt nicht die fremde Marke');
        $this->assertNotSame(self::MARKE_B, Brands::stampId());
    }

    #[Test]
    public function auf_einer_einzelmarken_installation_bleibt_es_beim_erbe(): void
    {
        $this->markenlage(multi: false);

        $zahlung = $this->ersteZahlung();

        // Erst hier spioniert, nicht vor der Kasse: sonst deckte das pauschale
        // `shouldNotHaveReceived` unten auch `Checkout::start()` mit ab, und ein
        // spaeterer Fehlschlag von dort saehe aus wie einer von hier.
        Log::spy();

        $abo = $this->subs()->startFromPayment($zahlung);

        // Wo es nur eine Marke gibt, gibt es nichts zu entscheiden — und
        // nichts zu melden. Eine Meldung je Abo waere Rauschen auf jedem
        // Betrieb, den das gar nicht betrifft.
        $this->assertInstanceOf(Subscription::class, $abo);
        $this->assertSame(self::MARKE_A, $abo->brand_id);

        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('info');
    }

    #[Test]
    public function auch_ein_geschwister_das_nicht_antwortet_fuehrt_nicht_zum_stillen_stempel(): void
    {
        $this->markenlage(multi: false, wirft: true);
        $zahlung = $this->ersteZahlung();

        // `Brands::UNKNOWN` ist ausdruecklich nicht `SINGLE`. Gefragt wird
        // `mode() === SINGLE`, nicht `multiBrand()`: letzteres sagt auch dann
        // `false`, wenn die Frage unbeantwortbar war — genau der Augenblick, in
        // dem ein stiller Stempel unter falscher Marke am wenigsten zu
        // rekonstruieren waere.
        $this->assertSame(Brands::UNKNOWN, Brands::mode());

        Log::spy();

        $abo = $this->subs()->startFromPayment($zahlung);

        $this->assertInstanceOf(Subscription::class, $abo);
        $this->assertSame(self::MARKE_B, $abo->brand_id);

        // „Nicht still" steht im Namen dieses Tests und muss also gemessen
        // werden, nicht nur behauptet: gerade hier, wo niemand mehr sagen kann,
        // welche Marke gilt, ist die Zeile im Log das Einzige, woraus sich der
        // Vorgang spaeter rekonstruieren laesst.
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => str_contains($message, 'different brand')
                && ($context['for'] ?? null) === Brands::FOR_SUBSCRIPTION,
        )->once();
    }

    #[Test]
    public function das_abo_gehoert_der_marke_des_angebots_und_nicht_der_der_ersten_zahlung(): void
    {
        $this->marke();
        $this->fremdeMarkeGiltJetzt();

        $zahlung = $this->ersteZahlung();

        Log::spy();

        $abo = $this->subs()->startFromPayment($zahlung);

        $this->assertInstanceOf(Subscription::class, $abo);
        // Nicht Marke A (das Erbe) und nicht die fremde Marke (die Anfrage).
        $this->assertSame(self::MARKE_B, $abo->brand_id);
        $this->assertSame(self::MARKE_A, $zahlung->brand_id);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => str_contains($message, 'different brand')
                && ($context['offer_brand'] ?? null) === self::MARKE_B
                && ($context['original_brand'] ?? null) === self::MARKE_A
                // Wobei der Betreiber gerade steht. Bei einer Vereinbarung ist
                // das mehr Arbeit als bei einer Folgezahlung, und wer die
                // Meldung liest, soll das nicht erst nachschlagen muessen.
                && ($context['for'] ?? null) === Brands::FOR_SUBSCRIPTION,
        )->once();
    }

    #[Test]
    public function nennt_der_katalog_keine_marke_bleibt_es_beim_erbe_und_sagt_es(): void
    {
        $this->marke();
        $zahlung = $this->ersteZahlung('schweigsam');

        Log::spy();

        $abo = $this->subs()->startFromPayment($zahlung);

        $this->assertInstanceOf(Subscription::class, $abo);
        $this->assertSame(self::MARKE_A, $abo->brand_id);

        Log::shouldHaveReceived('info')->withArgs(
            // Auf das unterscheidende Stueck, nicht auf „brand": jede der vier
            // Meldungen enthaelt das Wort.
            fn (string $message, array $context = []) => str_contains($message, 'names no brand')
                && ($context['product'] ?? null) === 'schweigsam'
                && ($context['for'] ?? null) === Brands::FOR_SUBSCRIPTION,
        )->once();

        // Breit gefasst und nicht auf die Markenmeldung eingeengt: eine auf den
        // Text verengte Erwartung fing in der Nachbardatei eine eingebaute
        // Warnung nicht.
        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function nennt_der_katalog_etwas_das_keine_marke_ist_wird_geerbt_und_gewarnt(): void
    {
        $this->marke();
        $zahlung = $this->ersteZahlung('unsinn');

        Log::spy();

        $abo = $this->subs()->startFromPayment($zahlung);

        $this->assertInstanceOf(Subscription::class, $abo);
        // Geerbt und nicht geraten. Ein `(int)`-Cast ueber allem haette aus
        // dem Array die Marke 1 gemacht, also eine echte, die es zufaellig gibt.
        $this->assertSame(self::MARKE_A, $abo->brand_id);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => str_contains($message, 'not a usable brand id')
                && ($context['brand_id_type'] ?? null) === 'array'
                // Der Wert selbst nur bei einem Skalar: was hier steht, kommt
                // aus fremdem Code und gehoert nicht ungeprueft ins Log.
                //
                // `array_key_exists` und nicht `??`: der Schluessel steht da
                // und traegt `null`, und `null ?? 'da'` waere `'da'` — die
                // Pruefung schlaege fehl, obwohl der Code richtig ist.
                && array_key_exists('brand_id', $context)
                && $context['brand_id'] === null
                && ($context['for'] ?? null) === Brands::FOR_SUBSCRIPTION,
        )->once();
        Log::shouldNotHaveReceived('info');
    }

    #[Test]
    public function eine_erste_zahlung_ohne_marke_hindert_das_abo_nicht_daran_eine_zu_bekommen(): void
    {
        $this->marke();

        // Entstanden, wo keine Marke galt — Webhook, Kommando, Warteschlange.
        // Dass das Abo jetzt eine bekommt, ist die Reparatur und nicht der
        // Fehler; die Meldung ist deshalb eine andere.
        $zahlung = $this->ersteZahlung(marke: 0);

        Log::spy();

        $abo = $this->subs()->startFromPayment($zahlung);

        $this->assertInstanceOf(Subscription::class, $abo);
        $this->assertSame(self::MARKE_B, $abo->brand_id);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => str_contains($message, 'carries no brand')
                && ($context['offer_brand'] ?? null) === self::MARKE_B
                && ($context['for'] ?? null) === Brands::FOR_SUBSCRIPTION,
        )->once();
    }

    #[Test]
    public function ein_katalog_der_die_sache_gar_nicht_mehr_kennt_ist_kein_katalog_ohne_marke(): void
    {
        Log::spy();
        $this->marke();

        $zahlung = $this->ersteZahlung();

        // Was die Aufrufer als `find($handle) ?? []` weitergeben, wenn das
        // Angebot zwischen zwei Abfragen verschwindet: geloescht, deaktiviert,
        // aus seinem Zeitfenster gelaufen, waehrend der Webhook lief. Dann
        // faellt nicht nur die Marke aufs Erbe zurueck, sondern auch Betrag und
        // Waehrung — und wer danach sucht, filtert nach `warning`.
        $this->assertSame(
            self::MARKE_A,
            Brands::forCatalogueEntry([], $zahlung, Brands::FOR_SUBSCRIPTION),
        );

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => str_contains($message, 'no longer knows')
                && ($context['for'] ?? null) === Brands::FOR_SUBSCRIPTION,
        )->once();

        // Und ausdruecklich **keine** `info`: „nennt keine Marke" waere die
        // harmlose Meldung fuer einen ganz anderen Vorgang.
        Log::shouldNotHaveReceived('info');
    }
}
