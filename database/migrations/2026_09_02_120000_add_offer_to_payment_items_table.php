<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Über welches Angebot eine Position verkauft wurde.
 *
 * `product` sagt, was verkauft wurde; `offer` sagt, auf welcher Seite. Ohne
 * die Spalte kann eine Auswertung den Umsatz eines Nachfassangebots nicht dem
 * Angebot zuordnen, sondern nur dem Produkt — und dasselbe Produkt steht in
 * fünf Angeboten. Geschrieben aus `PaymentDetails::offer_handles`, wenn die
 * aufrufende Strecke es liefert; sonst null, und das ist die ehrliche Lücke.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('payment_items', 'offer')) {
            return;
        }

        Schema::table('payment_items', function (Blueprint $table) {
            $table->string('offer', 191)->nullable()->after('product')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('payment_items', 'offer')) {
            return;
        }

        // Index zuerst, Spalte danach: SQLite baut beim Entfernen einer
        // Spalte die Tabelle neu und stolpert über einen Index, der die
        // Spalte noch nennt.
        Schema::table('payment_items', function (Blueprint $table) {
            $table->dropIndex(['offer']);
        });

        Schema::table('payment_items', function (Blueprint $table) {
            $table->dropColumn('offer');
        });
    }
};
