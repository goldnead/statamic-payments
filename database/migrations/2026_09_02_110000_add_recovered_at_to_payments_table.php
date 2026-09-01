<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wann eine Zahlung nach einer Abbruch-Erinnerung doch noch bezahlt wurde.
 *
 * `abandoned_notified_at` wird beim Bezahlen gelöscht, damit die Zeile nicht
 * mehr als abgesprungen gilt. Damit verschwindet aber auch die Information,
 * dass es eine Erinnerung gab — und mit ihr die Grundlage für „zurückgeholter
 * Umsatz". Diese Spalte hält den Moment fest, in dem aus einer Erinnerung ein
 * Kauf wurde. Gesetzt wird sie auf der erinnerten Zahlung, auch wenn der Kauf
 * über einen neu gestarteten Checkout (`meta.resumed_from`) lief.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('payments', 'recovered_at')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('recovered_at')->nullable()->after('abandoned_notified_at')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('payments', 'recovered_at')) {
            return;
        }

        // Index zuerst, Spalte danach — SQLite, siehe die offer-Migration.
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['recovered_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('recovered_at');
        });
    }
};
