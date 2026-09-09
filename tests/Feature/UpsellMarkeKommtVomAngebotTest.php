<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Brands;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\FollowUp;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

/**
 * Ein Nachfassangebot gehoert der Marke, die es verkauft.
 *
 * `FollowUp::accept()` erbte die Marke der Vorgaengerzahlung. Als Erbe ist das
 * richtig gedacht — ein Nachfassangebot wird auch aus einem Hintergrundlauf
 * angenommen, wo keine Marke gilt, und `Brands::stampId()` gaebe dort 0. Es
 * beantwortet aber die falsche Frage: gefragt ist nicht „wessen Zahlung war die
 * vorige", sondern „wessen Angebot wird hier verkauft".
 *
 * Zwei Faelle, in denen die beiden Antworten auseinandergehen:
 *
 * 1. Eine Vorgaengerzahlung von vor `statamic-funnels` 1.15.2. Sie trug die
 *    Standardmarke, weil ein Funnel unter `/f/<handle>` laeuft und
 *    `SetBrandForSite` dort nichts findet. Der Besuchs-Cookie haelt einen
 *    Monat, also erbt jedes Upsell darauf die falsche Marke weiter.
 * 2. Ein Funnel, dessen Upsell einem anderen Angebot und damit einer anderen
 *    Marke gehoert als sein Erstangebot.
 *
 * Die Entscheidung (Koordination 09.09.2026): **die Marke des verkauften
 * Angebots gewinnt.** Weicht sie ab, wird trotzdem verkauft — der Kaeufer hat
 * geklickt und soll nicht fuer einen Konfigurationsfehler bezahlen —, aber es
 * steht als `warning` im Log, mit beiden Marken und dem Handle. Nennt der
 * Katalogeintrag keine Marke, bleibt es beim Erbe, und das ist eine `info`.
 *
 * Der Aufbau ist der von `statamic-funnels/tests/Feature/MarkeKommtVomAngebotTest.php`:
 * die *fremde* Marke ist die aktive, und vor der Tat wird geprueft, dass sie es
 * wirklich ist. Sonst ginge ein Fehler im Aufbau als bestandener Test durch.
 */
class UpsellMarkeKommtVomAngebotTest extends TestCase
{
    /** Die Marke der Vorgaengerzahlung. */
    protected const MARKE_A = 1;

    /** Die Marke, der das Upsell-Angebot gehoert. */
    protected const MARKE_B = 2;

    /** Die Marke, unter der die Anfrage laeuft. Keine der beiden anderen. */
    protected const FREMDE = 7;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'noten-paket' => ['name' => 'Notenpaket', 'amount_cent' => 1900],
            // Das Upsell gehoert Marke B. `Catalogue::find()` reicht durch, was
            // der Katalog sonst noch deklariert — derselbe Weg, auf dem
            // `interval` und `times` zum Abo kommen.
            'begleit-cd' => ['name' => 'Begleit-CD', 'amount_cent' => 1200, 'brand_id' => self::MARKE_B],
            // Und eins ohne Marke: Altbestand, Seeder, Import.
            'zugabe' => ['name' => 'Zugabe', 'amount_cent' => 500],
        ]);

        $app['config']->set('statamic-payments.follow_up.enabled', true);
    }

    /**
     * Ein Resolver, der den Test ueberlebt, ist ein Preis im naechsten.
     *
     * `Catalogue::$resolvers` ist statisch. Ohne diese Zeile taucht ein hier
     * beigesteuertes Angebot in einer voellig anderen Datei wieder auf, und der
     * Fehlschlag steht dann drei Dateien weiter.
     */
    protected function tearDown(): void
    {
        Catalogue::forgetResolvers();

        parent::tearDown();
    }

    /**
     * Ein Stellvertreter fuer die beiden anderen Lagen aus {@see Brands}.
     *
     * `$multi = false` ist die Einzelmarken-Installation, `$wirft = true` das
     * Geschwister, das nicht antworten will — bei `Brands` der Zustand
     * {@see Brands::UNKNOWN}, und der ist ausdruecklich nicht derselbe.
     */
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

    /**
     * Ein Stellvertreter fuer den Manager von statamic-brand-context.
     *
     * Nicht freizuegiger als das Original: er beantwortet genau die drei
     * Fragen, die {@see Brands} stellt.
     */
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

    /** Eine bezahlte Erstbestellung auf Marke A, mit Mandat beim Anbieter. */
    protected function bezahltAufMarkeA(): Payment
    {
        $payment = app(Checkout::class)->start('noten-paket', ['email' => 'kaeuferin@example.com'])->payment;

        $payment->forceFill([
            'status' => Payment::STATUS_PAID,
            'paid_at' => now(),
            'fulfilled_at' => now(),
            'customer_reference' => 'cst_maria',
            'brand_id' => self::MARKE_A,
        ])->save();

        $mandat = trim((string) ($payment->fresh()->mandate_id ?? ''));

        if ($mandat !== '' && ! in_array($mandat, $this->gateway->knownMandates, true)) {
            $this->gateway->knownMandates[] = $mandat;
        }

        $this->gateway->mandates[] = 'cst_maria';

        return $payment->fresh();
    }

    /**
     * Dass wirklich die fremde Marke gilt.
     *
     * Vor der Tat gefragt: haette der Aufbau versehentlich schon Marke B
     * aktiv, waere der Test gruen, ohne irgendetwas zu belegen.
     */
    protected function fremdeMarkeGiltJetzt(): void
    {
        $this->assertSame(
            self::FREMDE,
            (int) app('brand-context')->currentId(),
            'Der Aufbau taugt nur, solange vor dem Upsell eine fremde Marke gilt.',
        );
    }

    #[Test]
    public function das_upsell_traegt_die_marke_seines_angebots_und_nicht_die_der_vorgaengerzahlung(): void
    {
        $this->marke();

        $original = $this->bezahltAufMarkeA();

        $this->assertSame(self::MARKE_A, (int) $original->brand_id, 'die Erstbestellung sollte auf Marke A stehen');

        $this->fremdeMarkeGiltJetzt();

        Log::spy();

        $folge = app(FollowUp::class)->accept($original, 'begleit-cd');

        $this->assertNotNull($folge, 'das Upsell wurde gar nicht verkauft');

        // Der gemessene Fehler: die Folgezahlung trug Marke A, also die der
        // Vorgaengerzahlung, mit Rechnungsserie und Absender daran.
        $this->assertSame(
            self::MARKE_B,
            (int) $folge->brand_id,
            'die Folgezahlung traegt die Marke der Vorgaengerzahlung statt die des verkauften Angebots',
        );

        // Verkauft wird trotzdem — aber der Betreiber erfaehrt davon. Ein
        // Funnel mit fremdem Upsell ist entweder Absicht oder ein
        // Konfigurationsfehler, und keins von beidem darf man raten.
        Log::shouldHaveReceived('warning')->withArgs(
            function (string $message, array $context = []) {
                // Auf das **unterscheidende** Stueck geprueft, nicht auf
                // „brand": alle vier Meldungen dieser Regel enthalten das Wort,
                // und eine Fassung, die die Bedeutung umdreht, kaeme damit
                // durch.
                return str_contains($message, 'different brand')
                    && ($context['product'] ?? null) === 'begleit-cd'
                    && (int) ($context['offer_brand'] ?? 0) === self::MARKE_B
                    && (int) ($context['original_brand'] ?? 0) === self::MARKE_A
                    // Wobei der Betreiber gerade steht: hier zieht er einen
                    // Funnel gerade, bei einer Vereinbarung muesste er
                    // zusaetzlich eine laufende Zeile umtragen.
                    && ($context['for'] ?? null) === Brands::FOR_FOLLOW_UP;
            }
        )->once();
    }

    #[Test]
    public function ein_angebot_ohne_eigene_marke_erbt_die_der_vorgaengerzahlung(): void
    {
        $this->marke();

        $original = $this->bezahltAufMarkeA();

        $this->fremdeMarkeGiltJetzt();

        Log::spy();

        $folge = app(FollowUp::class)->accept($original, 'zugabe');

        $this->assertNotNull($folge, 'das Upsell wurde gar nicht verkauft');

        // Kein Rueckfall auf die fremde Marke der Anfrage und kein Stempeln:
        // sagt der Katalog nichts, bleibt das Erbe die einzige Antwort, die
        // keine Erfindung ist.
        $this->assertSame(
            self::MARKE_A,
            (int) $folge->brand_id,
            'ein Angebot ohne Marke soll die der Vorgaengerzahlung erben',
        );

        // Leise, aber nicht stumm: das Erbe ist hier richtig und trotzdem eine
        // Aussage darueber, dass am Angebot etwas fehlt.
        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context = []) => str_contains($message, 'names no brand')
                && ($context['product'] ?? null) === 'zugabe'
                && (int) ($context['original_brand'] ?? 0) === self::MARKE_A
                && ($context['for'] ?? null) === Brands::FOR_FOLLOW_UP
        )->once();

        // Pauschal, und das ist hier die schaerfere Fassung.
        //
        // Auf die Markenmeldung eingeengt sieht besser aus und misst nichts:
        // `shouldNotHaveReceived($methode, $argumente)` liess in der Messung
        // eine eingebaute Warnung anstandslos durch (probiert am 09.09.2026 mit
        // einem `Log::warning` im `info`-Zweig — der Test blieb gruen). Eine
        // Zusicherung, die den Fehler nicht faengt, gegen den sie geschrieben
        // ist, ist keine. Der Nachfass-Pfad warnt hier sonst ueber gar nichts,
        // also kostet die breite Fassung auch nichts.
        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function auch_ein_beigesteuertes_angebot_bringt_seine_marke_mit(): void
    {
        // Der Produktionsweg, und der einzige, der wirklich zaehlt: ein echtes
        // Upsell heisst `offer:<handle>` und steht in keiner Config-Datei,
        // sondern kommt aus dem Resolver von `statamic-offers`. Die beiden
        // Tests darueber pruefen dieselbe Regel ueber einen konfigurierten
        // Eintrag; erst hier laeuft sie ueber die Naht, an der sie im Betrieb
        // haengt. Ein Resolver, der `brand_id` nicht mitschickt, faellt genau
        // deshalb in den Erbe-Zweig — und dass ihn heute keiner mitschickt, ist
        // ein eigenes Ticket, kein Fehler dieser Datei.
        Catalogue::extend(fn (string $handle): ?array => $handle === 'offer:zweitstimme' ? [
            'name' => 'Zweitstimme',
            'amount_cent' => 700,
            'brand_id' => self::MARKE_B,
            'offer' => 'zweitstimme',
        ] : null);

        $this->marke();

        $original = $this->bezahltAufMarkeA();

        $this->fremdeMarkeGiltJetzt();

        Log::spy();

        $folge = app(FollowUp::class)->accept($original, 'offer:zweitstimme');

        $this->assertNotNull($folge, 'das beigesteuerte Upsell wurde gar nicht verkauft');
        $this->assertSame(self::MARKE_B, (int) $folge->brand_id);

        // Und der Handle des Angebots steht in der Meldung, nicht nur der des
        // Katalogeintrags: danach sucht, wer den Funnel geradeziehen soll.
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => ($context['offer'] ?? null) === 'zweitstimme'
                && ($context['product'] ?? null) === 'offer:zweitstimme'
        )->once();
    }

    #[Test]
    public function eine_marke_als_ziffernfolge_im_text_zaehlt_auch(): void
    {
        // Eine Eloquent-Spalte ohne Cast liefert `'2'` und nicht `2`. Das als
        // „nennt keine Marke" zu lesen hiesse, die Marke des Angebots genau
        // dort wegzuwerfen, wo sie steht.
        Catalogue::extend(fn (string $handle): ?array => $handle === 'offer:aus-der-tabelle' ? [
            'name' => 'Aus der Tabelle',
            'amount_cent' => 700,
            'brand_id' => (string) self::MARKE_B,
        ] : null);

        $this->marke();

        $folge = app(FollowUp::class)->accept($this->bezahltAufMarkeA(), 'offer:aus-der-tabelle');

        $this->assertNotNull($folge);
        $this->assertSame(self::MARKE_B, (int) $folge->brand_id);
    }

    #[Test]
    public function eine_unbrauchbare_marke_am_angebot_erbt_und_sagt_warum(): void
    {
        // `(int) ['x']` waere `1` — eine echte Marke, die es hier zufaellig
        // gibt. Geerbt wird trotzdem, denn raten waere schlimmer; aber der
        // Grund darf nicht als „nennt keine Marke" verkleidet werden, sonst
        // sucht der Betreiber an der falschen Stelle.
        Catalogue::extend(fn (string $handle): ?array => $handle === 'offer:kaputt' ? [
            'name' => 'Kaputt',
            'amount_cent' => 700,
            'brand_id' => ['marke' => 2],
        ] : null);

        $this->marke();

        Log::spy();

        $folge = app(FollowUp::class)->accept($this->bezahltAufMarkeA(), 'offer:kaputt');

        $this->assertNotNull($folge);
        $this->assertSame(self::MARKE_A, (int) $folge->brand_id, 'eine unbrauchbare Angabe soll erben, nicht raten');

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => str_contains($message, 'not a usable brand id')
                && ($context['brand_id_type'] ?? null) === 'array'
                // Der Wert selbst gehoert nicht ins Log, wenn er kein Skalar ist.
                && ($context['brand_id'] ?? null) === null
        )->once();

        Log::shouldNotHaveReceived('info');
    }

    #[Test]
    public function eine_vorgaengerzahlung_ganz_ohne_marke_bekommt_ihre_eigene_meldung(): void
    {
        // Nicht dieselbe Lage wie „fremde Marke", auch wenn beide hier
        // vorbeikommen. Auf 0 stand die Zahlung, weil sie entstand, wo keine
        // Marke galt — Webhook, Kommando, Warteschlange. Dass das Upsell jetzt
        // eine bekommt, ist die Reparatur, und die Meldung sagt genau das,
        // damit niemand einen Funnel sucht, der nichts falsch macht.
        $this->marke();

        $original = $this->bezahltAufMarkeA();
        $original->forceFill(['brand_id' => 0])->save();

        Log::spy();

        $folge = app(FollowUp::class)->accept($original->fresh(), 'begleit-cd');

        $this->assertNotNull($folge);
        $this->assertSame(self::MARKE_B, (int) $folge->brand_id);

        // Auf den Wortlaut und nicht auf `brand`: beide Meldungen enthalten das
        // Wort, also misst eine Zusicherung darauf den Unterschied nicht, um
        // den es hier geht.
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => str_contains($message, 'carries no brand')
                && (int) ($context['original_brand'] ?? -1) === 0
                && (int) ($context['offer_brand'] ?? 0) === self::MARKE_B
        )->once();
    }

    #[Test]
    public function auf_einer_einzelmarken_installation_wird_gar_nichts_gefragt(): void
    {
        // Dort ist jede Marke null, das Angebot verkauft niemand anders, und
        // eine Zeile Log je Bestellung waere reiner Laerm.
        $this->markenlage(multi: false);

        $original = $this->bezahltAufMarkeA();

        Log::spy();

        $folge = app(FollowUp::class)->accept($original, 'begleit-cd');

        $this->assertNotNull($folge);
        $this->assertSame(self::MARKE_A, (int) $folge->brand_id, 'ohne Mehrmarkigkeit bleibt es beim Erbe');

        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('info');
    }

    #[Test]
    public function ein_geschwister_das_nicht_antwortet_haelt_die_pruefung_nicht_auf(): void
    {
        // Der Unterschied, wegen dem hier `Brands::mode()` gefragt wird und
        // nicht `multiBrand()`: letzteres sagt auch bei einer Stoerung `false`.
        // Dann liefe der Verkauf unter fremder Marke stumm durch — im
        // Augenblick, in dem am ehesten etwas schiefgeht und am wenigsten zu
        // rekonstruieren ist.
        $this->markenlage(multi: true, wirft: true);

        $this->assertSame(Brands::UNKNOWN, Brands::mode(), 'der Aufbau taugt nur, solange das Geschwister wirklich nicht antwortet');

        $original = $this->bezahltAufMarkeA();

        Log::spy();

        $folge = app(FollowUp::class)->accept($original, 'begleit-cd');

        $this->assertNotNull($folge);
        $this->assertSame(self::MARKE_B, (int) $folge->brand_id);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => str_contains($message, 'different brand')
                && (int) ($context['offer_brand'] ?? 0) === self::MARKE_B
        )->once();
    }
}
