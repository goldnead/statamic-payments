<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The stamp that makes "this checkout was abandoned" a once-only fact.
 *
 * Same shape as `fulfilled_at` and `failed_notified_at`, and for the same
 * reason: the sweep runs on a schedule and may overlap itself. Without a claim
 * in the table, two runs both read `open` and both announce — and the visible
 * result is a customer getting the same reminder twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('abandoned_notified_at')->nullable()->after('failed_notified_at');

            // The sweep asks for open payments older than a cut-off that have
            // not been announced yet. Without this the query is a full scan on
            // every run, forever, on the one table that only grows.
            $table->index(['status', 'abandoned_notified_at'], 'payments_abandoned_sweep_index');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_abandoned_sweep_index');
            $table->dropColumn('abandoned_notified_at');
        });
    }
};
