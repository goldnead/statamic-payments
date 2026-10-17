<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ein Klick, eine Zahlung.
 *
 * Ein Doppelklick auf "Kaufen" schickt zwei Anfragen ab. Jede legte bisher ihre
 * eigene Zeile und ihre eigene Zahlung beim Anbieter an: zwei offene Zahlungen,
 * und ein Kaeufer, der beide zu Ende bringt.
 *
 * Der Schluessel ist ein Fingerabdruck aus Anbieter, Adresse, Warenkorb,
 * Rabatt und Ruecksprung. Eindeutig, damit die Datenbank entscheidet, wer von
 * zwei gleichzeitigen Anfragen die Zeile anlegt: ein eindeutiger Index wirkt
 * auch unter SQLite, anders als ein `lockForUpdate()`, das dort nichts tut.
 *
 * **Nullable, und null ist der Normalfall nach dem Ende.** Der Schluessel gilt
 * nur, solange die Zahlung offen und frisch ist; danach wird er geleert (oder
 * die Zeile hat ihn nie bekommen), damit derselbe Warenkorb spaeter erneut
 * gekauft werden kann. Mehrere null sind in jeder Datenbank erlaubt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('checkout_key', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['checkout_key']);
            $table->dropColumn('checkout_key');
        });
    }
};
