<?php

namespace Goldnead\StatamicPayments\Console\Commands;

use Goldnead\StatamicPayments\Support\BrandBackfill;
use Goldnead\StatamicPayments\Support\Brands;
use Goldnead\StatamicPayments\Support\Catalogue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Trage Vereinbarungen auf die Marke um, die sie verkauft hat.
 *
 * Seit 1.24.2 nimmt eine entstehende Vereinbarung die Marke aus dem
 * Katalogeintrag ihres Produkts ({@see Brands::forCatalogueEntry()}). Zeilen,
 * die vorher entstanden sind, tragen die Marke ihrer ersten Zahlung — und das
 * ist genau die Antwort, die der Fix ueberstimmt hat. Ein Fix, der nur fuer
 * neue Zeilen gilt, laesst den Bestand falsch stehen, und beim Abo ist das
 * teuer: die Marke haengt an jedem Zyklus, jeder Rechnung und der Sichtbarkeit
 * im Kundenbereich, fuer die ganze Laufzeit.
 *
 * **Auch beendete Vereinbarungen.** Gekuendigt oder ausgelaufen heisst nicht
 * folgenlos: an der Zeile haengen weiterhin Rechnungen, Zahlungen und was der
 * Kunde in seinem Bereich zu sehen bekommt. Eine falsche Marke dort ist
 * dieselbe falsche Marke.
 *
 * **Warum ein eigenes Kommando und keine Option an `payments:brand-backfill`.**
 * Das Geschwister leitet die Marke einer Vereinbarung aus ihrer *ersten
 * Zahlung* ab ({@see BrandBackfill::FROM_FIRST_PAYMENT}) — das ist die Regel,
 * die hier ueberstimmt wird. Beides in einen Fixpunkt zu legen hiesse, zwei
 * einander widersprechende Regeln um dieselbe Spalte laufen zu lassen, und
 * welche gewaenne, haenge an der Reihenfolge der Durchlaeufe. Dazu kommt die
 * gegensaetzliche Voreinstellung: dort schreibt der Lauf und `--dry-run` haelt
 * ihn an, hier ist der Trockenlauf der Default und `--apply` die einzige
 * Schreibweise. Zwei entgegengesetzte Sicherheitsvoreinstellungen in einer
 * Signatur waeren ein Bedienfehler mit Ansage.
 */
class BackfillSubscriptionBrands extends Command
{
    protected $signature = 'payments:subscription-brand-backfill {--apply : Schreiben. Ohne diese Option wird nur gezeigt.}';

    protected $description = 'Die Marke von Vereinbarungen aus dem Katalogeintrag ihres Produkts ableiten.';

    /** Hoechstens so viele Zeilen in der Tabelle; die Zahlen darunter sagen die ganze Wahrheit. */
    private const SHOW = 40;

    public function handle(): int
    {
        if (! BrandBackfill::possible()) {
            return $this->nothingToDo();
        }

        $catalogue = app(Catalogue::class);

        // `DB::table` und nicht das Modell: ein Kommando, das den Bestand
        // pruefen soll, darf nicht durch dieselbe Markenbrille schauen, die den
        // Kundenbereich fail-closed macht. Mit einer aktiven Marke saehe es nur
        // deren Zeilen und meldete den Rest als „nichts gefunden".
        $rows = DB::table(BrandBackfill::SUBSCRIPTIONS)->orderBy('id')->get(['id', 'product', 'brand_id']);

        $abweichend = [];
        $unableitbar = [];
        $bewertet = 0;
        $beimErbe = 0;

        /** @var array<string, array<string, mixed>|null> $katalog memoisiert je Handle */
        $katalog = [];

        foreach ($rows as $row) {
            $heute = (int) $row->brand_id;
            $produkt = (string) $row->product;

            if (! array_key_exists($produkt, $katalog)) {
                try {
                    $katalog[$produkt] = $catalogue->find($produkt) ?? [];
                } catch (Throwable $e) {
                    // Ein beitragender Resolver laeuft live gegen die Tabellen
                    // eines fremden Pakets. Faellt er aus, ist das kein „kein
                    // Eintrag": das waere ein Fehlschlag, der wie ein Ergebnis
                    // aussieht. Gemerkt wird der Ausfall je Handle, sonst
                    // brennt ein kaputter Resolver einmal je Zeile.
                    $katalog[$produkt] = null;
                }
            }

            if ($katalog[$produkt] === null) {
                $unableitbar[] = [$row->id, $produkt, $heute, 'der Katalog ist für dieses Produkt nicht erreichbar'];

                continue;
            }

            // Dieselbe Regel wie beim Verkauf, und derselbe Code. Das Erbe ist
            // hier, was die Zeile heute sagt — genau der Wert, den die alte
            // Regel aus der ersten Zahlung geschrieben hat.
            $entscheidung = Brands::decideForCatalogueEntry($katalog[$produkt], $heute);

            if ($entscheidung['reason'] === Brands::REASON_NO_ENTRY) {
                // `Catalogue::find()` sagt auch dann nichts, wenn der Eintrag da
                // ist und sein Preis unbrauchbar — die Meldung laesst beides
                // offen, statt den Betreiber ein geloeschtes Angebot suchen zu
                // lassen, das er nie geloescht hat.
                $unableitbar[] = [$row->id, $produkt, $heute, 'der Katalog liefert für dieses Produkt keinen brauchbaren Eintrag (gelöscht, deaktiviert, oder der Preis fehlt)'];

                continue;
            }

            if ($entscheidung['reason'] === Brands::REASON_UNUSABLE) {
                // Ein Eintrag, der etwas nennt, das keine Marke ist, ist ein
                // Konfigurationsfehler und keine Aussage. Er bleibt stehen wie
                // ein fehlender Eintrag, und aus demselben Grund: raten waere
                // schlimmer als melden.
                $unableitbar[] = [$row->id, $produkt, $heute, 'der Katalogeintrag nennt keine brauchbare Marke ('.get_debug_type($entscheidung['raw']).')'];

                continue;
            }

            $bewertet++;

            if ($entscheidung['reason'] === Brands::REASON_NO_BRAND) {
                $beimErbe++;
            }

            if ($entscheidung['brand'] === $heute) {
                continue;
            }

            $abweichend[] = [
                'id' => (int) $row->id,
                'product' => $produkt,
                'from' => $heute,
                'to' => $entscheidung['brand'],
                'reason' => $entscheidung['reason'],
                'offer' => $entscheidung['offer'],
            ];
        }

        $this->showChanges($abweichend, $bewertet, $beimErbe);

        $misslungen = [];

        if ($this->option('apply') && $abweichend !== []) {
            // **Nach dem Lesen noch einmal fragen.** `possible()` hat es am
            // Anfang getan, aber der Lizenz-Check des Geschwisters kann
            // waehrenddessen umfallen, und dann darf nichts mehr abgeleitet
            // *und schon gar nichts geschrieben* werden. Ohne diese Zeile
            // schreibt der Lauf unter einer Annahme weiter, die gerade
            // widerrufen wurde.
            if (Brands::mode() !== Brands::MULTI) {
                $this->components->error(
                    'brand-context hat während des Laufs aufgehört zu sagen, ob dieser Betrieb mehrere Marken führt. '
                    .'Es wurde nichts geschrieben.'
                );

                return self::FAILURE;
            }

            $misslungen = $this->write($abweichend);
        }

        return $this->showLeftovers($unableitbar, $misslungen);
    }

    /**
     * Warum es hier nichts zu tun gibt, in den Worten des Grundes.
     *
     * „0 Zeilen" auf einem Betrieb ohne `brand-context` laese sich wie eine
     * Entwarnung auf eine Frage, die nie gestellt wurde. Und „ich konnte
     * nicht" ist nicht „es gab nichts zu tun": die beiden unteren Faelle geben
     * `FAILURE`, damit eine Deploy-Kette sie nicht abhakt.
     */
    private function nothingToDo(): int
    {
        if (! Brands::available()) {
            $this->components->info('Ohne goldnead/statamic-brand-context gibt es nur eine Marke. Jede Zeile steht auf 0, und das ist richtig.');

            return self::SUCCESS;
        }

        if (Brands::mode() === Brands::SINGLE) {
            $this->components->info('Dieser Betrieb führt nur eine Marke (brand-context.multi_brand ist aus). Jede Zeile steht auf 0, und das ist richtig.');

            return self::SUCCESS;
        }

        $this->components->error(Brands::mode() === Brands::UNKNOWN
            ? 'brand-context sagt nicht, ob dieser Betrieb mehrere Marken führt. Solange das so ist, wird hier nichts abgeleitet.'
            : 'Die Tabellen payments/subscriptions oder die Spalte brand_id fehlen noch. Erst die Migrationen laufen lassen.');

        return self::FAILURE;
    }

    /** @param  list<array{id: int, product: string, from: int, to: int, reason: string, offer: string|null}>  $abweichend */
    private function showChanges(array $abweichend, int $bewertet, int $beimErbe): void
    {
        if ($abweichend === []) {
            // Der Nenner sind die *bewerteten* Zeilen und nicht alle. Sonst
            // laese sich ein Lauf, in dem der Katalog ausgefallen ist, als
            // „alles geprüft, alles sauber".
            $this->components->info($bewertet.' Vereinbarungen bewertet, keine weicht von der Marke ihres Katalogeintrags ab.');
        } else {
            $marken = $this->brandNames();

            $this->newLine();
            $this->table(
                ['Abo', 'Produkt', 'Marke heute', 'Marke abgeleitet', 'Grund'],
                array_map(fn (array $z) => [
                    (string) $z['id'],
                    $z['product'],
                    $this->brand($z['from'], $marken),
                    $this->brand($z['to'], $marken),
                    $z['reason'],
                ], array_slice($abweichend, 0, self::SHOW))
            );

            if (count($abweichend) > self::SHOW) {
                $this->line('  … und '.(count($abweichend) - self::SHOW).' weitere.');
            }

            $this->newLine();

            if (! $this->option('apply')) {
                $this->components->warn(
                    count($abweichend).' von '.$bewertet.' Vereinbarungen würden umgetragen. Geschrieben wurde nichts — '
                    .'mit --apply noch einmal laufen lassen.'
                );
            }
        }

        if ($beimErbe > 0) {
            // Ohne diese Zahl liest sich ein Betrieb, dessen Katalog gar keine
            // Marken deklariert, wie ein sauberer Bestand.
            $this->components->info(
                $beimErbe.' Vereinbarungen: der Katalogeintrag nennt keine Marke, es bleibt beim Erbe. '
                .'Das ist die einzige Antwort, die keine Erfindung ist.'
            );
        }
    }

    /**
     * Schreiben, Zeile fuer Zeile, jede mit ihrer eigenen Bedingung.
     *
     * Die Bedingung ist nicht Zierde: zwischen Lesen und Schreiben kann jemand
     * die Marke von Hand gesetzt haben, und dessen Antwort ist juenger als die
     * Tabelle oben. Trifft der `UPDATE` nichts, wird das gemeldet, geloggt und
     * **in den Exit-Code eingerechnet** — sonst haekt eine Deploy-Kette einen
     * Lauf ab, in dem keine einzige geplante Aenderung stattgefunden hat,
     * waehrend im Log nur die gute Nachricht steht.
     *
     * @param  list<array{id: int, product: string, from: int, to: int, reason: string, offer: string|null}>  $abweichend
     * @return list<array{0: int, 1: string, 2: int, 3: string}> die misslungenen
     */
    private function write(array $abweichend): array
    {
        $geschrieben = 0;
        $misslungen = [];

        foreach ($abweichend as $z) {
            $treffer = DB::table(BrandBackfill::SUBSCRIPTIONS)
                ->where('id', $z['id'])
                ->where('brand_id', $z['from'])
                ->update(['brand_id' => $z['to']]);

            if ($treffer === 0) {
                Log::warning('statamic-payments: an agreement changed while the backfill was running and was left alone.', [
                    'subscription' => $z['id'],
                    'product' => $z['product'],
                    'brand_expected' => $z['from'],
                    'brand_target' => $z['to'],
                    'for' => Brands::FOR_SUBSCRIPTION_BACKFILL,
                ]);

                $misslungen[] = [$z['id'], $z['product'], $z['from'], 'hat sich während des Laufs verändert (erwartet '.$z['from'].', Ziel war '.$z['to'].')'];

                continue;
            }

            $geschrieben++;

            // Eine Zeile je Änderung, damit hinterher nachvollziehbar ist, was
            // dieser Lauf getan hat — und zwar ohne die Tabelle im Terminal,
            // die niemand aufhebt.
            Log::info('statamic-payments: an existing agreement was moved to the brand of its catalogue entry.', [
                'subscription' => $z['id'],
                'product' => $z['product'],
                'offer' => $z['offer'],
                'brand_before' => $z['from'],
                'brand_after' => $z['to'],
                'for' => Brands::FOR_SUBSCRIPTION_BACKFILL,
            ]);
        }

        // Die Gegenzahl gehoert dazu: „0 tragen jetzt die Marke" liest sich
        // sonst wie „es gab nichts zu tun".
        $satz = $geschrieben.' von '.count($abweichend).' vorgesehenen Vereinbarungen tragen jetzt die Marke, die sie verkauft hat.';

        $geschrieben === count($abweichend)
            ? $this->components->info($satz)
            : $this->components->warn($satz);

        return $misslungen;
    }

    /**
     * Die Zeilen, ueber die nichts zu sagen war, und die, bei denen es nicht klappte.
     *
     * Sie bleiben stehen — „ich habe keinen Beleg" ist kein Beleg —, aber sie
     * bleiben nicht still: der Rueckgabewert ist ungleich 0, damit ein Lauf in
     * einem Skript nicht als erledigt durchgeht, waehrend Vereinbarungen
     * ungeprueft weiterlaufen.
     *
     * @param  list<array{0: int, 1: string, 2: int, 3: string}>  $unableitbar
     * @param  list<array{0: int, 1: string, 2: int, 3: string}>  $misslungen
     */
    private function showLeftovers(array $unableitbar, array $misslungen): int
    {
        $offen = array_merge($unableitbar, $misslungen);

        if ($offen === []) {
            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($offen as [$id, $produkt, $heute, $grund]) {
            $this->components->warn(
                'subscriptions '.$id.' ('.$produkt.', Marke '.$this->brand($heute, []).'): '.$grund
                .'. Bleibt, wie sie ist.'
            );
        }

        return self::FAILURE;
    }

    /**
     * Markennummern in etwas, das ein Mensch wiedererkennt.
     *
     * Direkt aus der Tabelle gelesen und nicht ueber das Modell des
     * Geschwisters: die Beschriftung ist keinen Import wert, der dieses
     * Kommando an eine Paketfassung bindet. Ohne die Tabelle bleibt es eine
     * Zahl.
     *
     * @return array<int, string>
     */
    private function brandNames(): array
    {
        try {
            if (! Schema::hasTable('brands')) {
                return [];
            }

            return DB::table('brands')->pluck('handle', 'id')
                ->map(fn ($handle) => (string) $handle)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @param  array<int, string>  $marken */
    private function brand(int $id, array $marken): string
    {
        if ($id === 0) {
            return '0 (keine Marke)';
        }

        return isset($marken[$id]) ? $id.' ('.$marken[$id].')' : (string) $id;
    }
}
