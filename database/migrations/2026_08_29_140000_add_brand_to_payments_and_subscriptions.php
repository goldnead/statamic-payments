<?php

use Goldnead\StatamicPayments\Support\BrandBackfill;
use Goldnead\StatamicPayments\Support\Brands;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Which brand sold this.
 *
 * Until now nothing here carried one, and the gap was already written down in
 * a sibling: `goldnead/statamic-invoices` refuses to write an invoice when no
 * brand is current, with the comment "a brand is not recoverable from the
 * payment either; statamic-payments does not scope by one".
 *
 * The customer portal is what makes that gap unaffordable. A magic link opens a
 * list of somebody's purchases without a login, and on a multi-brand host the
 * only thing that can keep brand A's link away from brand B's order is a brand
 * on the order. Without this column the portal would either show everything to
 * everybody or show nothing to anybody.
 *
 * `default(0)` rather than nullable, and no foreign key: the column has to work
 * on every install, including the great majority that have never heard of
 * `brand-context`. Zero means "single-brand install", which is what the sibling
 * does with the same column in `invoices`.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['payments', 'subscriptions'] as $table) {
            if (Schema::hasColumn($table, 'brand_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('brand_id')->default(0)->index();
            });
        }

        $this->backfill();
    }

    /**
     * Rows that existed before the column did.
     *
     * **Derived, one row at a time, and left at zero where nothing says.** The
     * first version of this method took the lowest brand id and wrote it onto
     * every existing payment and subscription. On the demo playground that made
     * eleven payments "nordlicht" and put seven invoices into a different
     * brand's number series than the payment they belong to — and since
     * `goldnead/statamic-invoices` `0d66f59` the invoice writer reads this
     * column, so the guess would have been handed forward into new documents.
     *
     * The derivation lives in {@see BrandBackfill} because the repair command
     * `payments:brand-backfill` has to run exactly the same one: this migration
     * is committed and has already run on installs where the rows now stand at
     * the wrong brand rather than at zero. Two copies of that logic would drift
     * on the first correction.
     *
     * Nothing happens at all where `brand-context` is absent or multi-brand is
     * off. That is the single-brand case, every row is zero, and zero is right.
     */
    protected function backfill(): void
    {
        if (! BrandBackfill::possible()) {
            return;
        }

        try {
            $report = (new BrandBackfill)->fillGaps();
        } catch (Throwable $e) {
            // A backfill is a convenience; the column is the migration. Failing
            // here would leave a half-migrated schema behind on an install
            // whose data simply could not be read.
            Log::warning('statamic-payments: die Marken des Altbestands liessen sich nicht ableiten; alle Zeilen bleiben auf 0.', [
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        if ($report->stillZeroCount() === 0) {
            return;
        }

        // The reported gap. A migration has no console output worth the name,
        // so this is the only place it can be said — and it has to be said,
        // because a row on 0 is a row the customer portal shows to nobody.
        Log::warning(
            'statamic-payments: '.$report->stillZeroCount().' Zeilen liessen sich keiner Marke zuordnen und '
            .'stehen auf 0. Sie wurden NICHT auf die Standardmarke geschrieben. '
            .'`php artisan payments:brand-backfill --dry-run` zeigt sie.',
            [
                'still_zero' => $report->stillZero,
                'sources' => $report->countsBySource(),
                'default_brand_id' => Brands::defaultId(),
            ]
        );
    }

    public function down(): void
    {
        foreach (['payments', 'subscriptions'] as $table) {
            if (! Schema::hasColumn($table, 'brand_id')) {
                continue;
            }

            // The index first, and only where the column is there at all.
            // SQLite refuses to drop a column an index still points at.
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropIndex($table.'_brand_id_index');
            });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('brand_id');
            });
        }
    }
};
