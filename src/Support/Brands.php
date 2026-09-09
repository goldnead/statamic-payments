<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The soft seam to `goldnead/statamic-brand-context`.
 *
 * Never a hard dependency. Most installs of this addon are one shop with one
 * sender and no tenancy at all, and for them every method here answers "zero"
 * and nothing changes. `class_exists` on a string rather than an import, the
 * same shape the insights registration in the service provider uses, so that
 * nothing in this file can trigger an autoload of a package that is not there.
 *
 * **Three states, not two, and that is the whole point of this class.** "No
 * tenants here" and "there are tenants and I could not find out which" look the
 * same to a boolean and must not be treated the same by a query. `multiBrandEnabled()`
 * on the sibling runs a host-configured `license_check` — a closure, or a class
 * the container resolves — which can throw. A `catch (Throwable) { return false; }`
 * around that turns one failing callback into "this install has no tenants",
 * and the next portal query runs unfiltered across every brand on the host.
 * Fail-open, from a defensive catch. So the failure gets its own answer.
 *
 * **`stampId()` and `only()` are not the same question**, either, and mixing
 * those up is the other way a tenant leak gets written. `stampId()` answers
 * "whose row is this about to be" while a row is being created, and it is
 * allowed to land on zero. `only()` answers "whose rows may this reader see",
 * and it never guesses: an unanswerable filter closes.
 */
final class Brands
{
    /** The value on every row of a single-brand install. */
    public const NONE = 0;

    /** No tenancy on this install. Every row is `NONE` and no filter is needed. */
    public const SINGLE = 'single';

    /** Tenants, and the current one is knowable. */
    public const MULTI = 'multi';

    /** The sibling is installed and would not answer. Nothing may be read. */
    public const UNKNOWN = 'unknown';

    /** {@see self::forCatalogueEntry()}: eine Folgezahlung entsteht. */
    public const FOR_FOLLOW_UP = 'follow-up';

    /** {@see self::forCatalogueEntry()}: eine Vereinbarung entsteht. */
    public const FOR_SUBSCRIPTION = 'subscription';

    /**
     * Whether the sibling is installed and usable at all.
     *
     * The facade is probed by name; the manager is asked of the container,
     * because a half-booted install can have the class and not the binding.
     */
    public static function available(): bool
    {
        if (! class_exists('\Goldnead\BrandContext\Facades\BrandContext')) {
            return false;
        }

        try {
            return app()->bound('brand-context');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Which of the three states this install is in.
     *
     * `UNKNOWN` is reached only where the sibling is present and refused to
     * answer, which is rare and loud — it is logged, because a host whose
     * licence check throws is a host whose customer portal has just stopped
     * showing anybody anything, and that should be findable in a log rather
     * than reported as an empty page.
     */
    public static function mode(): string
    {
        if (! self::available()) {
            return self::SINGLE;
        }

        try {
            return app('brand-context')->multiBrandEnabled() ? self::MULTI : self::SINGLE;
        } catch (Throwable $e) {
            Log::error('statamic-payments: brand-context would not say whether this install is multi-brand; every scoped read is closed until it does.', [
                'exception' => $e->getMessage(),
            ]);

            return self::UNKNOWN;
        }
    }

    /**
     * Whether this install actually separates tenants.
     *
     * A boolean for the callers that only need to know whether to bother —
     * stamping a row, deciding which brands to search. `UNKNOWN` answers false
     * here on purpose: stamping lands on zero, and a zero row is one that
     * {@see self::only()} shows to nobody. The closing happens at the read.
     */
    public static function multiBrand(): bool
    {
        return self::mode() === self::MULTI;
    }

    /**
     * The brand to write onto a row being created now.
     *
     * `currentId()` is not usable here on its own: it falls back to the default
     * brand when nothing is set, and nothing is set in a provider's webhook or a
     * console command. `hasCurrent()` is the difference between "this brand" and
     * "nobody said", and only the first may be stamped.
     *
     * Landing on zero in multi-brand mode is a real outcome, not a bug to hide:
     * it means the row was created where no brand was current. The portal then
     * shows it to nobody, which is the fail-closed half of the same decision.
     * Rows created *for* another row — a subscription cycle, a follow-up charge
     * — inherit their parent's brand explicitly instead of asking this.
     */
    public static function stampId(): int
    {
        if (! self::multiBrand()) {
            return self::NONE;
        }

        try {
            $manager = app('brand-context');

            return $manager->hasCurrent() ? (int) $manager->currentId() : self::NONE;
        } catch (Throwable) {
            return self::NONE;
        }
    }

    /**
     * Wessen Marke auf einer Zeile steht, die aus einer Zahlung entsteht.
     *
     * Geerbt wurde sie bisher von dieser Zahlung, und als Erbe ist das richtig
     * gedacht: hier laeuft keine Anfrage mit einer Marke — ein Nachfassangebot
     * wird auch aus einem Hintergrundlauf angenommen, eine Vereinbarung
     * entsteht im Webhook — und {@see self::stampId()} gaebe dort null. Nur
     * beantwortet das Erbe die falsche Frage. Gefragt ist nicht „wessen Zahlung
     * war das", sondern „wessen Angebot wird hier verkauft".
     *
     * Die beiden Antworten gehen auseinander, sobald die Zahlung falsch
     * gestempelt war — jede Funnel-Zahlung von vor `statamic-funnels` 1.15.2
     * trug die Standardmarke, und der Besuchs-Cookie haelt einen Monat — oder
     * sobald ein Funnel etwas fuehrt, das einer anderen Marke gehoert als sein
     * Erstangebot.
     *
     * **Das Angebot gewinnt, und der Verkauf findet trotzdem statt.** Der
     * Kaeufer hat auf den Bestellknopf geklickt; ein Konfigurationsfehler des
     * Betreibers ist nichts, wofuer eine Bestellung abgelehnt werden darf. Er
     * gehoert aber ins Log, mit beiden Marken und dem Handle, denn ein fremdes
     * Angebot ist entweder Absicht oder ein Fehler, und keins von beidem darf
     * man raten.
     *
     * Nennt der Katalogeintrag keine Marke — Altbestand, ein Produkt aus der
     * Config, ein Seeder —, bleibt es beim Erbe. Das ist die einzige Antwort,
     * die keine Erfindung ist, und `info` statt `warning`, weil sie richtig ist
     * und nur festhaelt, dass am Angebot etwas fehlt.
     *
     * Auf einem Einzelmarken-System ueberhaupt keine Frage: dort ist jede Marke
     * null, und ein Hinweis je Bestellung waere reiner Laerm. Gefragt wird nach
     * {@see self::mode()} und nicht nach {@see self::multiBrand()}, weil der
     * Unterschied genau hier haengt — `multiBrand()` sagt auch dann `false`,
     * wenn das Geschwister nicht antworten wollte ({@see self::UNKNOWN}), und
     * das ist der Augenblick, in dem am ehesten etwas schiefgeht. Ein stiller
     * Verkauf unter der geerbten Marke waere dann nicht mehr zu rekonstruieren,
     * also laeuft die Pruefung auch da.
     *
     * **Eine Entscheidung ueber Geld, an einer Stelle.** Gerufen von
     * {@see FollowUp::accept()} fuer eine Folgezahlung und von
     * {@see Subscriptions::startFromPayment()} fuer eine Vereinbarung. Zwei
     * Kopien waeren die eine, die spaeter etwas dazulernt, und die andere — und
     * beim Abo waere der Preis dafuer besonders hoch, weil dessen Marke fuer
     * die ganze Laufzeit feststeht: jeder Zyklus, jede Rechnung, die
     * Sichtbarkeit im Portal.
     *
     * @param  array<string, mixed>  $eintrag  Der Katalogeintrag. `brand_id`
     *                                         reicht dieselbe Durchreiche her wie `interval` und `times`:
     *                                         {@see Catalogue::find()} behaelt, was der Katalog sonst noch deklariert.
     * @param  self::FOR_*  $anlass  Was hier entsteht, und der Schluessel steht
     *                               im Log. **Er ist keine Zierde.** Die Meldung
     *                               ist derselbe Satz fuer beide Faelle, die
     *                               Arbeit dahinter aber nicht: bei einer
     *                               Folgezahlung zieht der Betreiber einen
     *                               Funnel gerade, bei einer Vereinbarung muss
     *                               er zusaetzlich eine laufende Zeile umtragen,
     *                               an der jeder Zyklus und jede Rechnung
     *                               haengt. Ohne diesen Schluessel steht das
     *                               nicht in der Meldung und ist nur ueber
     *                               `original_payment` nachzuschlagen.
     */
    public static function forCatalogueEntry(array $eintrag, Payment $geerbtVon, string $anlass): int
    {
        $geerbt = (int) $geerbtVon->brand_id;

        if (self::mode() === self::SINGLE) {
            return $geerbt;
        }

        // **Gar kein Eintrag ist etwas anderes als ein Eintrag ohne Marke**,
        // und ohne diesen Zweig saehen die beiden im Log gleich aus.
        //
        // Die Aufrufer holen den Eintrag mit `find($handle) ?? []`, und `null`
        // heisst hier nicht „nichts deklariert", sondern „zur Laufzeit nicht
        // mehr auffindbar": ein Angebot geloescht, deaktiviert oder aus seinem
        // Zeitfenster gelaufen, waehrend der Webhook lief. Dann faellt nicht
        // nur die Marke aufs Erbe zurueck, sondern auch Betrag und Waehrung —
        // und wer nach so etwas sucht, filtert nach `warning`, nicht nach einer
        // `info`-Zeile mit leerem `product`.
        if ($eintrag === []) {
            Log::warning('statamic-payments: the catalogue no longer knows the thing being sold here, so the new row inherits everything from the payment it grew out of, brand included.', [
                'original_brand' => $geerbt,
                'original_payment' => $geerbtVon->getKey(),
                'for' => $anlass,
            ]);

            return $geerbt;
        }

        // Nicht der blosse Cast: der Katalog ist offen, und der Eintrag kommt
        // womoeglich aus dem Resolver eines fremden Pakets. `(int)` machte aus
        // einem versehentlichen Array eine `1` — also eine echte Marke, die es
        // hier zufaellig gibt. Eine Ziffernfolge im Text zaehlt dagegen: eine
        // Eloquent-Spalte ohne Cast liefert genau die.
        $roh = $eintrag['brand_id'] ?? null;
        $desAngebots = match (true) {
            is_int($roh) => $roh,
            is_string($roh) && ctype_digit($roh) => (int) $roh,
            default => 0,
        };
        $handle = (string) ($eintrag['handle'] ?? '');

        if ($desAngebots < 1) {
            // „Nichts gesagt" und „etwas gesagt, das keine Marke ist" sind
            // nicht dasselbe, und nur das erste ist harmlos. Beim zweiten wird
            // ebenfalls geerbt — raten waere schlimmer —, aber der Grund steht
            // dann laut da, statt als „nennt keine Marke" verkleidet zu werden.
            if ($roh === null || $roh === 0 || $roh === '0') {
                Log::info('statamic-payments: this catalogue entry names no brand, so the new row inherits the brand of the payment it grew out of.', [
                    'product' => $handle,
                    'original_brand' => $geerbt,
                    'original_payment' => $geerbtVon->getKey(),
                    'for' => $anlass,
                ]);
            } else {
                Log::warning('statamic-payments: this catalogue entry names something that is not a usable brand id, so the new row inherits the brand of the payment it grew out of.', [
                    'product' => $handle,
                    // Der Typ und nur bei einem Skalar der Wert: was hier steht,
                    // kommt aus fremdem Code und gehoert nicht ungeprueft in
                    // eine Logzeile.
                    'brand_id_type' => get_debug_type($roh),
                    'brand_id' => is_scalar($roh) ? $roh : null,
                    'original_brand' => $geerbt,
                    'original_payment' => $geerbtVon->getKey(),
                    'for' => $anlass,
                ]);
            }

            return $geerbt;
        }

        if ($desAngebots !== $geerbt) {
            // Zwei sehr verschiedene Lagen, und die Beschriftung entscheidet,
            // ob jemand etwas tut. Eine Zahlung auf 0 gehoerte nie einer Marke:
            // entstanden, wo keine galt — Webhook, Kommando, Warteschlange —,
            // also stempelte {@see self::stampId()} null. Dass die neue Zeile
            // jetzt eine Marke bekommt, ist die Reparatur und nicht der Fehler.
            //
            // **Der Altbestand von vor `statamic-funnels` 1.15.2 steht
            // ausdruecklich nicht hier.** Der trug die *Standardmarke*, und die
            // ist groesser als null. Er landet also in der zweiten Meldung, und
            // das ist richtig: von aussen ist eine falsch gestempelte alte
            // Zahlung von einem Funnel mit fremdem Angebot nicht zu
            // unterscheiden, und beide will der Betreiber sehen.
            Log::warning($geerbt < 1
                ? 'statamic-payments: the payment this grew out of carries no brand, so the new row takes the brand of the offer instead of inheriting none.'
                : 'statamic-payments: this offer belongs to a different brand than the payment it grew out of; the new row is made under the brand of the offer.', [
                    'product' => $handle,
                    'offer' => is_string($eintrag['offer'] ?? null) ? $eintrag['offer'] : null,
                    'offer_brand' => $desAngebots,
                    'original_brand' => $geerbt,
                    'original_payment' => $geerbtVon->getKey(),
                    'for' => $anlass,
                ]);
        }

        return $desAngebots;
    }

    /**
     * The brand whose rows the caller may read, or null when nobody said.
     *
     * The missing half of {@see self::only()}, and it is missing on purpose no
     * longer: `only()` takes a nullable id and does the right thing with each,
     * but every caller had to work out that id for itself, and the obvious
     * wrong answer was sitting right there. `stampId()` returns **zero** where
     * no brand is current, and zero passed to `only()` does not mean "show
     * nothing" — it means "show the rows nobody claimed". A listing written
     * that way looks correct on a single-brand install, looks correct on a
     * multi-brand install with a brand selected, and quietly shows the
     * unassigned rows to whoever opens it without one.
     *
     * Null here, zero there. The two questions are "whose row is this about to
     * be" and "whose rows may this reader see", and the class docblock already
     * says they are not the same one.
     */
    public static function readerId(): ?int
    {
        if (! self::multiBrand()) {
            // `only()` does not filter a single-brand install at all, so the
            // value is unused. Null rather than zero anyway, so that a caller
            // who reaches for it outside `only()` gets the honest answer.
            return null;
        }

        try {
            $manager = app('brand-context');

            return $manager->hasCurrent() ? (int) $manager->currentId() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The brand that `brand-context` itself calls default.
     *
     * Asked, never assumed. `DB::table('brands')->orderBy('id')->value('id')`
     * answers a different question — "which brand was created first" — and the
     * two only coincide by accident. The sibling decides its default by handle
     * and by an `is_default` flag, and a host may move it.
     *
     * Nothing in this package ever *writes* this id onto a row. It exists so a
     * report can name the brand that a guessing backfill would have written,
     * which is the difference between "seven rows could not be resolved" and
     * "seven rows could not be resolved and were **not** silently made
     * nordlicht's". Zero where the sibling is absent or has no default.
     */
    public static function defaultId(): int
    {
        if (! self::available()) {
            return self::NONE;
        }

        try {
            return (int) app('brand-context')->defaultId();
        } catch (Throwable) {
            return self::NONE;
        }
    }

    /**
     * Narrow a query to one brand's rows, fail-closed.
     *
     * Four cases and only four:
     *
     * 1. Single-brand install — no filter. There is one tenant; filtering on a
     *    column that is zero everywhere would be theatre.
     * 2. Multi-brand, a brand named — that brand's rows.
     * 3. Multi-brand, no brand named — **no rows at all**. Not "the default
     *    brand's", not "all of them". A reader who cannot say which tenant they
     *    belong to is not a reader of any tenant.
     * 4. The sibling would not answer — **no rows at all**, for the same reason.
     *    This is the case a boolean would have collapsed into case 1, and case 1
     *    returns everything.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function only(Builder $query, ?int $brandId): Builder
    {
        $mode = self::mode();

        if ($mode === self::SINGLE) {
            return $query;
        }

        if ($mode === self::UNKNOWN || $brandId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('brand_id', $brandId);
    }
}
