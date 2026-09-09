<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\ServiceProvider;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Brands;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

/**
 * `payments:subscription-brand-backfill` traegt den Bestand um.
 *
 * Seit 1.24.2 nimmt eine *entstehende* Vereinbarung die Marke aus dem
 * Katalogeintrag. Der Bestand trug bis dahin die Marke seiner ersten Zahlung,
 * und das ist genau die Antwort, die der Fix ueberstimmt hat. Jeder Test hier
 * misst gegen diesen Altzustand: nicht nur „die Marke stimmt jetzt", sondern
 * „sie ist nicht mehr die geerbte".
 *
 * Aufbau wie in {@see BrandBackfillTest}: `brand-context` legt in seiner
 * Migration die Marke 1 an, also fangen die Laeden bei 2 an.
 */
class AboMarkeBackfillTest extends TestCase
{
    protected Brand $shopA;

    protected Brand $shopB;

    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), array_values(array_filter([
            class_exists(ServiceProvider::class) ? ServiceProvider::class : null,
        ])));
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            // Gehoert Shop B, und die Abos darauf stehen auf Shop A.
            'mitgliedschaft' => [
                'name' => 'Mitgliedschaft',
                'amount_cent' => 1900,
                'interval' => '1 month',
                'brand_id' => 3,
            ],
            // Nennt keine Marke: Altbestand, Config-Produkt, Seeder. Das Erbe
            // bleibt, und das ist die richtige Antwort und keine Luecke.
            'schweigsam' => [
                'name' => 'Schweigsame Mitgliedschaft',
                'amount_cent' => 1900,
                'interval' => '1 month',
            ],
            // Ein Katalog ist offen. Was hier steht, ist keine Marke, und ein
            // blosser `(int)`-Cast machte daraus die 1 — eine echte.
            'unsinn' => [
                'name' => 'Unsinnige Mitgliedschaft',
                'amount_cent' => 1900,
                'interval' => '1 month',
                'brand_id' => ['nicht', 'zu', 'gebrauchen'],
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(BrandContext::class)) {
            $this->markTestSkipped('goldnead/statamic-brand-context has to be installed for this to mean anything');
        }

        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-brand-context/database/migrations');

        config(['brand-context.multi_brand' => true]);

        $this->shopA = Brand::create(['handle' => 'shop-a', 'name' => 'Shop A']);
        $this->shopB = Brand::create(['handle' => 'shop-b', 'name' => 'Shop B']);

        // Der Katalogeintrag oben nennt 3. Stimmte das nicht, prueften die
        // Tests unten etwas anderes als sie behaupten.
        $this->assertSame(3, (int) $this->shopB->getKey());
        $this->assertSame(2, (int) $this->shopA->getKey());
    }

    protected function tearDown(): void
    {
        Catalogue::forgetResolvers();

        parent::tearDown();
    }

    // -------------------------------------------------------- der Trockenlauf

    #[Test]
    public function der_trockenlauf_zeigt_die_abweichung_und_schreibt_nichts(): void
    {
        $abo = $this->abo('mitgliedschaft', $this->shopA);
        $ruhig = $this->abo('schweigsam', $this->shopA);

        $geloggt = $this->mitschnitt();

        $ausgabe = $this->lauf();

        $this->assertSame((int) $this->shopA->getKey(), $this->markeVon($abo), 'der Trockenlauf hat geschrieben');
        $this->assertSame((int) $this->shopA->getKey(), $this->markeVon($ruhig));

        // Ein Trockenlauf, der eine Tat ins Log schreibt, ist schlimmer als
        // einer, der schweigt: das Log ist der Ort, an dem hinterher
        // nachgelesen wird, was geschehen ist.
        foreach ($geloggt as $zeile) {
            $this->assertStringNotContainsString('moved to the brand', $zeile['message'], 'der Trockenlauf hat eine Tat ins Log geschrieben');
        }

        $this->assertOutputSays('mitgliedschaft', $ausgabe);
        $this->assertOutputSays('Marke abgeleitet', $ausgabe);
        $this->assertZahlSagt('/\b1\s+von\s+2\s+Vereinbarungen\s+würden\s+umgetragen/u', $ausgabe);
        $this->assertOutputSays('Geschrieben wurde nichts', $ausgabe);

        // Das schweigsame Abo weicht nicht ab und gehoert nicht in die Tabelle:
        // stuende es da, waere „4 von 10" im Betrieb nicht mehr lesbar.
        $this->assertStringNotContainsString('schweigsam', $ausgabe);
    }

    #[Test]
    public function der_trockenlauf_ist_der_default(): void
    {
        $abo = $this->abo('mitgliedschaft', $this->shopA);

        // Ohne jede Option. Waere Schreiben der Default, faende dieser Test es.
        Artisan::call('payments:subscription-brand-backfill');

        $this->assertSame((int) $this->shopA->getKey(), $this->markeVon($abo));
    }

    // --------------------------------------------------------------- --apply

    #[Test]
    public function apply_traegt_genau_die_abweichenden_um(): void
    {
        $abweichend = $this->abo('mitgliedschaft', $this->shopA);
        $schonRichtig = $this->abo('mitgliedschaft', $this->shopB);
        $ohneMarkeImKatalog = $this->abo('schweigsam', $this->shopA);

        $vorher = $this->markeVon($schonRichtig);

        $ausgabe = $this->lauf(apply: true);

        $this->assertSame((int) $this->shopB->getKey(), $this->markeVon($abweichend), 'das abweichende Abo wurde nicht umgetragen');
        $this->assertNotSame((int) $this->shopA->getKey(), $this->markeVon($abweichend), 'es steht noch auf dem Erbe');
        $this->assertSame($vorher, $this->markeVon($schonRichtig), 'ein passendes Abo wurde angefasst');
        $this->assertSame((int) $this->shopA->getKey(), $this->markeVon($ohneMarkeImKatalog), 'ohne Marke im Katalog bleibt das Erbe');

        $this->assertZahlSagt('/\b1\s+von\s+1\s+vorgesehenen\s+Vereinbarungen\s+tragen\s+jetzt/u', $ausgabe);
    }

    #[Test]
    public function apply_schreibt_je_aenderung_eine_zeile_ins_log(): void
    {
        $abo = $this->abo('mitgliedschaft', $this->shopA);
        $geloggt = $this->mitschnitt();

        $this->lauf(apply: true);

        $treffer = array_values(array_filter(
            $geloggt->getArrayCopy(),
            fn (array $z) => str_contains($z['message'], 'moved to the brand of its catalogue entry'),
        ));

        $this->assertCount(1, $treffer, 'nicht genau eine Log-Zeile je Änderung');
        $this->assertSame((int) $abo->getKey(), $treffer[0]['context']['subscription']);
        $this->assertSame((int) $this->shopA->getKey(), $treffer[0]['context']['brand_before']);
        $this->assertSame((int) $this->shopB->getKey(), $treffer[0]['context']['brand_after']);
        $this->assertSame(Brands::FOR_SUBSCRIPTION_BACKFILL, $treffer[0]['context']['for']);
    }

    #[Test]
    public function ein_zweiter_trockenlauf_nach_apply_findet_nichts_mehr(): void
    {
        $this->abo('mitgliedschaft', $this->shopA);

        $this->lauf(apply: true);
        $ausgabe = $this->lauf();

        $this->assertOutputSays('bewertet, keine weicht von der Marke ihres Katalogeintrags ab', $ausgabe);
    }

    // ------------------------------------------------------- nicht ableitbar

    #[Test]
    public function ein_abo_ohne_katalogeintrag_bleibt_stehen_und_wird_gemeldet(): void
    {
        $verwaist = $this->abo('gibt-es-nicht-mehr', $this->shopA);

        $ausgabe = $this->lauf(apply: true);

        $this->assertSame((int) $this->shopA->getKey(), $this->markeVon($verwaist), 'ein Abo ohne Katalogeintrag wurde angefasst');
        $this->assertOutputSays('keinen brauchbaren Eintrag', $ausgabe);
        $this->assertOutputSays('Bleibt, wie sie ist', $ausgabe);
    }

    #[Test]
    public function ein_katalogeintrag_ohne_brauchbare_marke_bleibt_stehen_und_wird_gemeldet(): void
    {
        $unsinn = $this->abo('unsinn', $this->shopA);

        $ausgabe = $this->lauf(apply: true);

        // Wichtig gegen den blossen Cast: aus dem Array duerfte nie die Marke 1
        // werden. Die gibt es hier naemlich.
        $this->assertSame((int) $this->shopA->getKey(), $this->markeVon($unsinn));
        $this->assertNotSame(1, $this->markeVon($unsinn));
        $this->assertOutputSays('nennt keine brauchbare Marke', $ausgabe);
    }

    #[Test]
    public function nicht_ableitbares_macht_den_exit_code_ungleich_null(): void
    {
        $this->abo('mitgliedschaft', $this->shopA);

        $this->assertSame(0, Artisan::call('payments:subscription-brand-backfill'), 'ein sauberer Lauf meldet einen Fehler');

        $this->abo('gibt-es-nicht-mehr', $this->shopA);

        $this->assertNotSame(0, Artisan::call('payments:subscription-brand-backfill'), 'ein nicht ableitbares Abo blieb stumm');
    }

    // ------------------------------------------------------------- Grenzfaelle

    #[Test]
    public function auf_einem_einzelmarken_betrieb_passiert_nichts(): void
    {
        config(['brand-context.multi_brand' => false]);

        $abo = $this->abo('mitgliedschaft', $this->shopA);

        $ausgabe = $this->lauf(apply: true);

        $this->assertSame((int) $this->shopA->getKey(), $this->markeVon($abo));
        $this->assertOutputSays('nur eine Marke', $ausgabe);
    }

    #[Test]
    public function eine_fremde_hand_zwischen_lesen_und_schreiben_gewinnt(): void
    {
        $abo = $this->abo('mitgliedschaft', $this->shopA);

        // **Wirklich zwischen Lesen und Schreiben.** Der Lauf liest erst alle
        // Zeilen, fragt dann den Katalog und schreibt zuletzt. Ein Resolver ist
        // damit genau das Fenster: er laeuft nach dem Lesen und vor dem
        // Schreiben. Waere die Bedingung `where('brand_id', …)` ersatzlos
        // gestrichen, bliebe dieser Test als einziger rot.
        Catalogue::extend(function (string $handle) use ($abo) {
            if ($handle === 'per-resolver') {
                DB::table('subscriptions')->where('id', $abo->getKey())->update(['brand_id' => 0]);
            }

            return $handle === 'per-resolver'
                ? ['name' => 'Per Resolver', 'amount_cent' => 500, 'interval' => '1 month']
                : null;
        });

        // Die zweite Zeile sorgt nur dafuer, dass der Resolver ueberhaupt
        // gefragt wird — und ihre Id ist groesser, also kommt sie nach dem
        // Lesen der ersten.
        $this->abo('per-resolver', $this->shopA);

        $ausgabe = $this->lauf(apply: true);

        $this->assertSame(0, $this->markeVon($abo), 'die fremde Hand wurde überschrieben');
        $this->assertOutputSays('hat sich während des Laufs verändert', $ausgabe);
        $this->assertZahlSagt('/\b0\s+von\s+1\s+vorgesehenen/u', $ausgabe);
    }

    #[Test]
    public function eine_misslungene_aenderung_macht_den_exit_code_ungleich_null(): void
    {
        $abo = $this->abo('mitgliedschaft', $this->shopA);

        Catalogue::extend(function (string $handle) use ($abo) {
            if ($handle === 'per-resolver') {
                DB::table('subscriptions')->where('id', $abo->getKey())->update(['brand_id' => 0]);
            }

            return $handle === 'per-resolver'
                ? ['name' => 'Per Resolver', 'amount_cent' => 500, 'interval' => '1 month']
                : null;
        });

        $this->abo('per-resolver', $this->shopA);

        // Sonst hakt eine Deploy-Kette einen Lauf ab, in dem keine einzige
        // vorgesehene Änderung stattgefunden hat.
        $this->assertNotSame(0, Artisan::call('payments:subscription-brand-backfill', ['--apply' => true]));
    }

    #[Test]
    public function ein_ausgefallener_resolver_ist_kein_fehlender_eintrag(): void
    {
        $abo = $this->abo('per-resolver', $this->shopA);

        Catalogue::extend(function (string $handle) {
            if ($handle === 'per-resolver') {
                throw new \RuntimeException('die Tabelle des Geschwisters ist weg');
            }

            return null;
        });

        $code = Artisan::call('payments:subscription-brand-backfill', ['--apply' => true]);
        $ausgabe = Artisan::output();

        $this->assertSame((int) $this->shopA->getKey(), $this->markeVon($abo), 'eine Zeile ohne Antwort wurde angefasst');
        $this->assertOutputSays('nicht erreichbar', $ausgabe);
        $this->assertNotSame(0, $code, 'ein Katalog-Ausfall lief als Erfolg durch');
    }

    // ------------------------------------------------------------------ Hilfen

    protected function abo(string $product, Brand $brand): Subscription
    {
        return Subscription::create([
            'brand_id' => (int) $brand->getKey(),
            'provider' => 'mollie',
            'provider_id' => 'sub_'.uniqid(),
            'customer_reference' => 'cst_'.uniqid(),
            'product' => $product,
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'interval' => '1 month',
            'times' => null,
            'times_charged' => 0,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
        ]);
    }

    /**
     * Echtes Log, nur mitgehoert.
     *
     * Nicht `Log::spy()`: der tauscht den ganzen LogManager aus, und auf der
     * prefer-lowest-Zelle der CI laeuft danach ein Aufruf gegen einen Kanal,
     * den es nicht mehr gibt („Call to a member function warning() on null").
     * Lokal war davon nichts zu sehen. Der Zuhoerer laesst das Log stehen.
     *
     * @return \ArrayObject<int, array{message: string, context: array<string, mixed>, level: string}>
     */
    protected function mitschnitt(): \ArrayObject
    {
        $zeilen = new \ArrayObject;

        Log::listen(function ($ereignis) use ($zeilen) {
            $zeilen[] = [
                'message' => (string) $ereignis->message,
                'context' => (array) $ereignis->context,
                'level' => (string) $ereignis->level,
            ];
        });

        return $zeilen;
    }

    protected function markeVon(Subscription $abo): int
    {
        return (int) DB::table('subscriptions')->where('id', $abo->getKey())->value('brand_id');
    }

    protected function lauf(bool $apply = false): string
    {
        Artisan::call('payments:subscription-brand-backfill', $apply ? ['--apply' => true] : []);

        return Artisan::output();
    }

    /**
     * Die Konsole bricht auf Terminalbreite um, ein Satz ist also kein
     * Teilstring seiner selbst. Ohne Leerraum vergleichen.
     */
    protected function assertOutputSays(string $needle, string $output): void
    {
        $strip = fn (string $text) => preg_replace('/\s+/u', '', $text) ?? '';

        $this->assertStringContainsString($strip($needle), $strip($output), 'die Ausgabe sagt nichts von "'.$needle.'"');
    }

    /**
     * Fuer Zahlen reicht der Teilstring nicht.
     *
     * `assertOutputSays` wirft allen Leerraum weg, und `1von2` steckt auch in
     * `11von25`. Genau die Zahl, die den Befund belastbar macht, waere dann
     * ungeprueft. Hier wird der Leerraum nur *normalisiert*, damit der
     * Terminalumbruch nicht stoert, und mit Wortgrenzen verglichen.
     */
    protected function assertZahlSagt(string $muster, string $output): void
    {
        $this->assertMatchesRegularExpression(
            $muster,
            trim(preg_replace('/\s+/u', ' ', $output) ?? ''),
            'die Ausgabe passt nicht auf '.$muster,
        );
    }
}
