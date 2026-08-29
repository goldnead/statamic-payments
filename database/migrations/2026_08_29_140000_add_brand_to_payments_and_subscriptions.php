<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
     * On a single-brand install `0` is already right and nothing happens. On a
     * multi-brand install every existing row is stamped with the default brand,
     * which is where they were all sold as long as nothing was scoping them —
     * the same backfill `brand-context` performs for its own dependants.
     *
     * Left at `0` where the brands table is absent or unreadable: a migration
     * that guesses a tenant is worse than one that leaves a visible zero.
     */
    protected function backfill(): void
    {
        if (! Schema::hasTable('brands')) {
            return;
        }

        try {
            $default = DB::table('brands')->orderBy('id')->value('id');
        } catch (Throwable) {
            return;
        }

        if (! $default) {
            return;
        }

        foreach (['payments', 'subscriptions'] as $table) {
            DB::table($table)->where('brand_id', 0)->update(['brand_id' => $default]);
        }
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
