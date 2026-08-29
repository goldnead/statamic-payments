<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\ServiceProvider;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\BrandBackfill;
use Goldnead\StatamicPayments\Support\Brands;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * The brand of a row that was sold before the column existed.
 *
 * Every test here is written against the defect it replaces: a backfill that
 * took the lowest brand id and wrote it onto everything. So each one asserts
 * two things — the derived brand *is* what the evidence says, and it is **not**
 * the default brand. Asserting only the first would pass on the broken version
 * in exactly the case where the default happens to be right.
 *
 * `brand-context` creates its default brand in its own migration, so brand 1
 * here is "default" and is precisely the brand the guess produced. That is why
 * the shops start at 2.
 */
class BrandBackfillTest extends TestCase
{
    protected Brand $shopA;

    protected Brand $shopB;

    protected Brand $shopC;

    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), array_values(array_filter([
            class_exists(ServiceProvider::class) ? ServiceProvider::class : null,
        ])));
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
        $this->shopC = Brand::create(['handle' => 'shop-c', 'name' => 'Shop C']);

        // The thing the broken backfill reached for. Every assertion below
        // measures against it, so a test that stopped being a regression test
        // would fail here first.
        $this->assertSame(1, Brands::defaultId());
        $this->assertNotSame(1, (int) $this->shopA->getKey());
    }

    // ---------------------------------------------------------------- routes

    #[Test]
    public function eine_zahlung_mit_rechnung_bekommt_die_marke_der_rechnung(): void
    {
        $this->invoicesTable();

        $payment = $this->oldPayment('cw-kurs');
        $this->invoiceFor($payment, $this->shopB);

        $this->migrate();

        $this->assertSame((int) $this->shopB->getKey(), $this->brandOf($payment));
        $this->assertNotSame(Brands::defaultId(), $this->brandOf($payment));
    }

    #[Test]
    public function ein_abo_bekommt_die_marke_seiner_ersten_zahlung(): void
    {
        $this->invoicesTable();

        $abo = $this->oldSubscription('cw-mitgliedschaft');

        // The first charge carries an invoice, the second does not — which is
        // the ordinary shape: an invoice is written per payment, and the row
        // that has one is not necessarily the one being asked about.
        $erste = $this->oldPayment('cw-mitgliedschaft', ['subscription_id' => $abo->getKey()]);
        $zweite = $this->oldPayment('cw-mitgliedschaft', ['subscription_id' => $abo->getKey()]);

        $this->invoiceFor($erste, $this->shopC);

        $this->migrate();

        $this->assertSame((int) $this->shopC->getKey(), $this->brandOf($abo));
        $this->assertNotSame(Brands::defaultId(), $this->brandOf($abo));

        // And the cycle that had no invoice of its own followed the agreement.
        $this->assertSame((int) $this->shopC->getKey(), $this->brandOf($zweite));
    }

    #[Test]
    public function eine_folgeabbuchung_erbt_die_marke_der_zeile_zu_der_sie_gehoert(): void
    {
        $this->invoicesTable();

        $kauf = $this->oldPayment('hm-vinyl');
        $this->invoiceFor($kauf, $this->shopA);

        $nachbuchung = $this->oldPayment('hm-versand', ['parent_payment_id' => $kauf->getKey()]);

        $this->migrate();

        $this->assertSame((int) $this->shopA->getKey(), $this->brandOf($nachbuchung));
        $this->assertNotSame(Brands::defaultId(), $this->brandOf($nachbuchung));
    }

    #[Test]
    public function die_ableitung_traegt_ueber_mehrere_zeilen_hinweg(): void
    {
        $this->invoicesTable();

        // invoice -> payment -> agreement -> cycle -> follow-up. Nothing but
        // the first link has any evidence of its own, and a single pass would
        // resolve two of the five.
        $abo = $this->oldSubscription('lh-quartal');
        $erste = $this->oldPayment('lh-quartal', ['subscription_id' => $abo->getKey()]);
        $zyklus = $this->oldPayment('lh-quartal', ['subscription_id' => $abo->getKey()]);
        $nachbuchung = $this->oldPayment('lh-nachzahlung', ['parent_payment_id' => $zyklus->getKey()]);

        $this->invoiceFor($erste, $this->shopC);

        $this->migrate();

        foreach ([$erste, $zyklus, $nachbuchung] as $zahlung) {
            $this->assertSame((int) $this->shopC->getKey(), $this->brandOf($zahlung));
        }

        $this->assertSame((int) $this->shopC->getKey(), $this->brandOf($abo));
    }

    // ------------------------------------------------------------- the gaps

    #[Test]
    public function ein_echter_raetselfall_bleibt_auf_null_und_wird_gemeldet(): void
    {
        $this->invoicesTable();

        // Bought, paid, never invoiced, part of nothing. There is no honest
        // answer, and the wrong one would be invisible.
        $waise = $this->oldPayment('cw-stimmcheck');

        $bericht = (new BrandBackfill)->fillGaps();

        $this->assertSame(0, $this->brandOf($waise));
        $this->assertSame([], $bericht->changes);
        $this->assertSame(1, $bericht->stillZeroCount());
        $this->assertSame(1, $bericht->stillZero[BrandBackfill::PAYMENTS]);

        // And the command says so out loud rather than printing a clean zero.
        $ausgabe = $this->runCommand();

        $this->assertOutputSays('stehen auf 0', $ausgabe);
        $this->assertOutputSays('nicht auf die Standardmarke', $ausgabe);
    }

    #[Test]
    public function zwei_rechnungen_mit_verschiedenen_marken_beantworten_nichts(): void
    {
        $this->invoicesTable();

        $zahlung = $this->oldPayment('cw-kurs');

        // An invoice and its credit note in two different series. Somebody has
        // to look at that; picking one of them would bury it.
        $this->invoiceFor($zahlung, $this->shopA);
        $this->invoiceFor($zahlung, $this->shopB, kind: 'credit_note');

        $backfill = new BrandBackfill;
        $bericht = $backfill->fillGaps();

        $this->assertSame(0, $this->brandOf($zahlung));
        $this->assertSame(0, $bericht->changedCount());
        $this->assertSame(
            [['table' => 'payments', 'id' => (int) $zahlung->getKey(), 'reason' => 'zwei Rechnungen mit verschiedenen Marken']],
            $bericht->ambiguous
        );
    }

    #[Test]
    public function eine_rechnung_ohne_marke_beantwortet_nichts(): void
    {
        $this->invoicesTable();

        $zahlung = $this->oldPayment('cw-kurs');

        // brand_id 0 on the invoice is the same missing answer one table
        // further along, not a second opinion.
        DB::table('invoices')->insert($this->invoiceRow($zahlung, 0, 'invoice'));

        (new BrandBackfill)->fillGaps();

        $this->assertSame(0, $this->brandOf($zahlung));
    }

    #[Test]
    public function eine_marke_die_es_nicht_gibt_beantwortet_nichts(): void
    {
        $this->invoicesTable();

        $zahlung = $this->oldPayment('cw-kurs');

        // A database restored from somewhere else. Writing 99 would be worse
        // than writing nothing: the portal would then show the row to a brand
        // that does not exist, and nobody would ever look for it.
        DB::table('invoices')->insert($this->invoiceRow($zahlung, 99, 'invoice'));

        (new BrandBackfill)->fillGaps();

        $this->assertSame(0, $this->brandOf($zahlung));
    }

    // ------------------------------------------------- what must not be touched

    #[Test]
    public function eine_bereits_gesetzte_marke_bleibt_unangetastet(): void
    {
        $this->invoicesTable();

        // Stamped correctly when it was created, because a brand was current.
        $gestempelt = BrandContext::runFor($this->shopB, fn () => Payment::create($this->paymentAttributes('cw-noten')));

        $this->assertSame((int) $this->shopB->getKey(), $this->brandOf($gestempelt));

        $this->migrate();
        $this->runCommand();

        $this->assertSame((int) $this->shopB->getKey(), $this->brandOf($gestempelt));
    }

    #[Test]
    public function die_migration_fasst_nur_zeilen_auf_null_an(): void
    {
        $this->invoicesTable();

        // The row says B, the invoice says C. The migration is not the place to
        // settle that — it fills gaps, and this is not a gap. The repair
        // command is, and the next test proves it does.
        $zahlung = BrandContext::runFor($this->shopB, fn () => Payment::create($this->paymentAttributes('cw-noten')));
        $this->invoiceFor($zahlung, $this->shopC);

        $this->migrate();

        $this->assertSame((int) $this->shopB->getKey(), $this->brandOf($zahlung));
    }

    #[Test]
    public function eine_geratene_marke_ueberschreibt_keine_gestempelte(): void
    {
        $this->invoicesTable();

        // The situation an operator creates by doing the obvious thing: after
        // the broken migration he corrects the agreements he can see by hand,
        // then runs the repair tool. The tool must not undo that.
        $abo = BrandContext::runFor($this->shopC, fn () => Subscription::create($this->subscriptionAttributes('cw-mitgliedschaft')));

        // Its first payment has no invoice and is still wearing the guess.
        $erste = $this->oldPayment('cw-mitgliedschaft', ['subscription_id' => $abo->getKey()]);

        DB::table('payments')->where('id', $erste->getKey())->update(['brand_id' => Brands::defaultId()]);

        $bericht = (new BrandBackfill)->correct(dryRun: false);

        $this->assertSame((int) $this->shopC->getKey(), $this->brandOf($abo), 'die Handkorrektur wurde überschrieben');
        $this->assertSame(0, $bericht->changedCount());

        // And nothing is reported as settled: both rows are still carrying an
        // answer that only the guess supports, and the count has to say so.
        $this->assertSame(1, $bericht->unconfirmed[BrandBackfill::PAYMENTS]);
        $this->assertSame(1, $bericht->unconfirmed[BrandBackfill::SUBSCRIPTIONS]);
    }

    #[Test]
    public function eine_geratene_marke_darf_eine_luecke_trotzdem_fuellen(): void
    {
        $this->invoicesTable();

        // The other half of the same rule. The agreement says brand C — nothing
        // outside these tables backs that, but the cycle says nothing at all,
        // and repeating an answer onto a gap is what route 3 is for.
        $abo = BrandContext::runFor($this->shopC, fn () => Subscription::create($this->subscriptionAttributes('cw-mitgliedschaft')));
        $zyklus = $this->oldPayment('cw-mitgliedschaft', ['subscription_id' => $abo->getKey()]);

        $this->migrate();

        $this->assertSame((int) $this->shopC->getKey(), $this->brandOf($zyklus));
    }

    #[Test]
    public function eine_spaetere_zahlung_mit_rechnung_beantwortet_das_abo_auch(): void
    {
        $this->invoicesTable();

        $abo = $this->oldSubscription('cw-mitgliedschaft');

        // The first charge was never invoiced; the second was. The evidence is
        // no weaker for arriving second, and refusing it would leave the
        // agreement on 0 and every cycle behind it with it.
        $erste = $this->oldPayment('cw-mitgliedschaft', ['subscription_id' => $abo->getKey()]);
        $zweite = $this->oldPayment('cw-mitgliedschaft', ['subscription_id' => $abo->getKey()]);

        $this->invoiceFor($zweite, $this->shopA);

        $this->migrate();

        $this->assertSame((int) $this->shopA->getKey(), $this->brandOf($abo));
        $this->assertSame((int) $this->shopA->getKey(), $this->brandOf($erste));
    }

    #[Test]
    public function ein_abo_mit_zahlungen_unter_zwei_marken_beantwortet_nichts(): void
    {
        $this->invoicesTable();

        $abo = $this->oldSubscription('cw-mitgliedschaft');
        $eine = $this->oldPayment('cw-mitgliedschaft', ['subscription_id' => $abo->getKey()]);
        $andere = $this->oldPayment('cw-mitgliedschaft', ['subscription_id' => $abo->getKey()]);

        $this->invoiceFor($eine, $this->shopA);
        $this->invoiceFor($andere, $this->shopB);

        $bericht = (new BrandBackfill)->fillGaps();

        $this->assertSame(0, $this->brandOf($abo));
        $this->assertSame(
            [['table' => 'subscriptions', 'id' => (int) $abo->getKey(), 'reason' => 'seine Zahlungen sind unter verschiedenen Marken abgerechnet']],
            $bericht->ambiguous
        );

        // The two payments still have their own invoices and keep them.
        $this->assertSame((int) $this->shopA->getKey(), $this->brandOf($eine));
        $this->assertSame((int) $this->shopB->getKey(), $this->brandOf($andere));
    }

    // ---------------------------------------------------------- the repair

    #[Test]
    public function der_befehl_korrigiert_was_die_kaputte_migration_geraten_hat(): void
    {
        $this->invoicesTable();

        $nordlicht = $this->oldPayment('cw-kurs');
        $chorwerkstatt = $this->oldPayment('hm-vinyl');
        $ohneQuelle = $this->oldPayment('cw-stimmcheck');

        $this->invoiceFor($nordlicht, $this->shopB);
        $this->invoiceFor($chorwerkstatt, $this->shopC);

        // What the shipped migration left behind: everything on the default.
        $this->stampEverything(Brands::defaultId());

        $trocken = (new BrandBackfill)->correct(dryRun: true);

        $this->assertSame(2, $trocken->changedCount());
        $this->assertSame(Brands::defaultId(), $this->brandOf($nordlicht), 'ein --dry-run hat geschrieben');

        $nass = (new BrandBackfill)->correct(dryRun: false);

        $this->assertSame(2, $nass->changedCount());
        $this->assertSame([BrandBackfill::FROM_INVOICE => 2], $nass->countsBySource());

        $this->assertSame((int) $this->shopB->getKey(), $this->brandOf($nordlicht));
        $this->assertSame((int) $this->shopC->getKey(), $this->brandOf($chorwerkstatt));

        // No source contradicts this one, so nothing may move it — not even
        // back to zero. Absence of evidence is not evidence.
        $this->assertSame(Brands::defaultId(), $this->brandOf($ohneQuelle));

        // But it is counted and said out loud: it is still wearing the guess,
        // and a repair that reported only its successes would hide that.
        $this->assertSame(1, $nass->unconfirmedCount());
        $this->assertSame(1, $nass->unconfirmed[BrandBackfill::PAYMENTS]);

        $this->assertOutputSays('die sich aus nichts nachprüfen lässt', $this->runCommand());
    }

    #[Test]
    public function der_befehl_zeigt_woher_jede_marke_stammt(): void
    {
        $this->invoicesTable();

        $abo = $this->oldSubscription('cw-mitgliedschaft');
        $erste = $this->oldPayment('cw-mitgliedschaft', ['subscription_id' => $abo->getKey()]);
        $zyklus = $this->oldPayment('cw-mitgliedschaft', ['subscription_id' => $abo->getKey()]);

        $this->invoiceFor($erste, $this->shopC);
        $this->stampEverything(Brands::defaultId());

        $bericht = (new BrandBackfill)->correct(dryRun: true);

        $quellen = $bericht->countsBySource();

        ksort($quellen);

        $this->assertSame([
            BrandBackfill::FROM_FIRST_PAYMENT => 1,
            BrandBackfill::FROM_INVOICE => 1,
            BrandBackfill::FROM_RELATED_ROW => 1,
        ], $quellen);

        // Three rows, three different reasons, and the counts have to add up to
        // the rows or the summary is decoration.
        $this->assertSame(3, $bericht->changedCount());

        $betroffen = array_map(fn (array $change) => $change['table'].':'.$change['id'], $bericht->changes);

        sort($betroffen);

        $erwartet = [
            'payments:'.$erste->getKey(),
            'payments:'.$zyklus->getKey(),
            'subscriptions:'.$abo->getKey(),
        ];

        sort($erwartet);

        $this->assertSame($erwartet, $betroffen);
    }

    // ---------------------------------------------------------- the seams

    #[Test]
    public function ohne_installiertes_invoices_laeuft_nichts_in_einen_sql_fehler(): void
    {
        // No invoices table at all: the overwhelming majority of installs, and
        // the reason the read goes through Schema::hasTable() rather than a
        // model in a package that is only a `suggest`.
        $this->assertFalse(Schema::hasTable('invoices'));

        $abo = $this->oldSubscription('cw-mitgliedschaft');
        $zahlung = $this->oldPayment('cw-mitgliedschaft', ['subscription_id' => $abo->getKey()]);

        $this->migrate();
        $this->runCommand();

        // Nothing derivable without the hint, and nothing broken either.
        $this->assertSame(0, $this->brandOf($zahlung));
        $this->assertSame(0, $this->brandOf($abo));
    }

    #[Test]
    public function ohne_brand_context_passiert_gar_nichts(): void
    {
        $this->invoicesTable();

        $zahlung = $this->oldPayment('cw-kurs');
        $this->invoiceFor($zahlung, $this->shopB);

        // Single-brand: every row is zero and zero is right. Backfilling here
        // would put the old rows out of step with everything created since,
        // which Brands::stampId() also writes as zero.
        config(['brand-context.multi_brand' => false]);

        $this->assertFalse(BrandBackfill::possible());

        $this->migrate();

        $ausgabe = $this->runCommand();

        $this->assertSame(0, $this->brandOf($zahlung));
        $this->assertOutputSays('nur eine Marke', $ausgabe);
    }

    #[Test]
    public function eine_zeile_die_sich_waehrenddessen_veraendert_wird_nicht_ueberschrieben(): void
    {
        $this->invoicesTable();

        $zahlung = $this->oldPayment('cw-kurs');
        $this->invoiceFor($zahlung, $this->shopB);

        $backfill = new BrandBackfill;

        // The picture is read here. Everything after this is what a checkout,
        // an import or a second console run does while this one is thinking.
        $backfill->derive();

        DB::table('payments')->where('id', $zahlung->getKey())->update(['brand_id' => $this->shopA->getKey()]);

        $bericht = $backfill->correct(dryRun: false);

        // The write carried its precondition, so it matched nothing. Somebody
        // answered the row and their answer stands.
        $this->assertSame((int) $this->shopA->getKey(), $this->brandOf($zahlung));

        $this->assertSame([[
            'table' => 'payments',
            'id' => (int) $zahlung->getKey(),
            'expected' => (int) $this->shopB->getKey(),
            'found' => (int) $this->shopA->getKey(),
        ]], $bericht->missed);

        // And it is not counted as a success. A summary that claimed this row
        // would be the quiet wrong answer this whole class exists to avoid.
        $this->assertSame(0, $bericht->changedCount());
    }

    // ------------------------------------------------------------ twice over

    #[Test]
    public function zweimal_laufen_liefert_dasselbe_ergebnis(): void
    {
        $this->invoicesTable();

        $abo = $this->oldSubscription('cw-mitgliedschaft');
        $erste = $this->oldPayment('cw-mitgliedschaft', ['subscription_id' => $abo->getKey()]);
        $ohneQuelle = $this->oldPayment('cw-stimmcheck');

        $this->invoiceFor($erste, $this->shopC);

        $this->migrate();
        $nachErstemLauf = $this->snapshot();

        $this->migrate();
        $this->assertSame($nachErstemLauf, $this->snapshot(), 'die Migration ist beim zweiten Lauf nicht idempotent');

        $this->runCommand();
        $this->assertSame($nachErstemLauf, $this->snapshot(), 'der Befehl hat etwas verschoben, das schon stimmte');

        $zweiterBefehl = (new BrandBackfill)->correct(dryRun: false);

        $this->assertSame(0, $zweiterBefehl->changedCount());
        $this->assertSame($nachErstemLauf, $this->snapshot());

        $this->assertSame((int) $this->shopC->getKey(), $this->brandOf($erste));
        $this->assertSame(0, $this->brandOf($ohneQuelle));
    }

    // ----------------------------------------------------------- scaffolding

    /**
     * A row from before the column existed: no brand, and nobody stamped one.
     *
     * `brand_id` is passed explicitly, because the model's creating hook only
     * fills it where the caller said nothing — and a test that let the hook run
     * would be testing the hook.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function oldPayment(string $product, array $attributes = []): Payment
    {
        return Payment::create(array_merge($this->paymentAttributes($product), ['brand_id' => 0], $attributes));
    }

    /** @return array<string, mixed> */
    protected function paymentAttributes(string $product): array
    {
        return [
            'provider' => 'fake',
            'provider_id' => 'tr_'.uniqid('', true),
            'product' => $product,
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'anna@example.de',
            'paid_at' => now(),
        ];
    }

    protected function oldSubscription(string $product): Subscription
    {
        return Subscription::create(array_merge($this->subscriptionAttributes($product), ['brand_id' => 0]));
    }

    /** @return array<string, mixed> */
    protected function subscriptionAttributes(string $product): array
    {
        return [
            'provider' => 'fake',
            'provider_id' => 'sub_'.uniqid('', true),
            'customer_reference' => 'cst_1',
            'product' => $product,
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'interval' => '1 month',
            'times_charged' => 0,
            'status' => Subscription::STATUS_ACTIVE,
            'email' => 'anna@example.de',
        ];
    }

    /**
     * The `invoices` table as `goldnead/statamic-invoices` creates it.
     *
     * Only the columns this package can see, but with the original's
     * constraints intact: the not-null money columns, and above all
     * `unique(payment_id, kind)` — a laxer stand-in would have let the conflict
     * test insert two invoices of the same kind, which the real schema refuses,
     * and the test would then have proved a case that cannot occur.
     */
    protected function invoicesTable(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->default(0)->index();
            $table->string('number')->unique();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('kind', 16)->default('invoice');
            $table->foreignId('reverses_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->string('currency', 3);
            $table->unsignedInteger('net_cent');
            $table->unsignedInteger('tax_cent');
            $table->unsignedInteger('gross_cent');
            $table->timestamps();

            $table->index(['brand_id', 'issued_at']);
            $table->unique(['payment_id', 'kind']);
        });
    }

    protected function invoiceFor(Payment $payment, Brand $brand, string $kind = 'invoice'): void
    {
        DB::table('invoices')->insert($this->invoiceRow($payment, (int) $brand->getKey(), $kind));
    }

    /** @return array<string, mixed> */
    protected function invoiceRow(Payment $payment, int $brandId, string $kind): array
    {
        return [
            'brand_id' => $brandId,
            'number' => strtoupper(substr($kind, 0, 2)).'-'.uniqid('', true),
            'payment_id' => $payment->getKey(),
            'kind' => $kind,
            'issued_at' => now(),
            'currency' => 'EUR',
            'net_cent' => 1900,
            'tax_cent' => 0,
            'gross_cent' => 1900,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** What the shipped migration did: one brand onto everything. */
    protected function stampEverything(int $brandId): void
    {
        DB::table('payments')->update(['brand_id' => $brandId]);
        DB::table('subscriptions')->update(['brand_id' => $brandId]);
    }

    /** Run the real migration file, backfill and all. */
    protected function migrate(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_08_29_140000_add_brand_to_payments_and_subscriptions.php';

        $migration->up();
    }

    protected function runCommand(bool $dryRun = false): string
    {
        Artisan::call('payments:brand-backfill', $dryRun ? ['--dry-run' => true] : []);

        return Artisan::output();
    }

    /** @return array<string, array<int, int>> */
    protected function snapshot(): array
    {
        return [
            'payments' => DB::table('payments')->orderBy('id')->pluck('brand_id', 'id')->map(fn ($id) => (int) $id)->all(),
            'subscriptions' => DB::table('subscriptions')->orderBy('id')->pluck('brand_id', 'id')->map(fn ($id) => (int) $id)->all(),
        ];
    }

    protected function brandOf(Payment|Subscription $row): int
    {
        return (int) $row->fresh()->brand_id;
    }

    /**
     * Console output wraps at the terminal width, so a sentence is not a
     * substring of itself. Compare without whitespace instead.
     */
    protected function assertOutputSays(string $needle, string $output): void
    {
        $strip = fn (string $text) => preg_replace('/\s+/u', '', $text) ?? '';

        $this->assertStringContainsString($strip($needle), $strip($output), 'die Ausgabe sagt nichts von "'.$needle.'"');
    }
}
