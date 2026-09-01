<?php

use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wer wann welchem Wortlaut zugestimmt hat.
 *
 * Bei digitalen Inhalten erlischt das Widerrufsrecht nur, wenn der Verbraucher
 * ausdrücklich zugestimmt hat, dass die Lieferung sofort beginnt, und seine
 * Kenntnis vom Erlöschen bestätigt hat (§ 356 Abs. 5 BGB). Bis hierher wurde
 * dieser Haken geprüft und dann verworfen: keine Spalte, kein Zeitpunkt, keine
 * Fassung des Textes. Im Streitfall war nicht belegbar, ob überhaupt jemand
 * zugestimmt hat.
 *
 * Zwei Spalten, nicht eine. Der Wortlaut kann sich ändern, und dann ist „hat
 * zugestimmt" ohne die Fassung, der zugestimmt wurde, wertlos. Deshalb steht der
 * Text selbst hier und nicht eine Versionsnummer, die auf eine Sprachdatei
 * zeigt, die es in fünf Jahren so nicht mehr gibt.
 *
 * Beide Spalten sind **unveränderlich**, sobald sie gesetzt sind. Das erzwingt
 * das Modell ({@see Payment::booted()}), nicht
 * die Datenbank — eine Zeile, deren Zustimmung nachträglich umgeschrieben werden
 * kann, ist kein Beleg.
 *
 * Bestandszeilen bleiben null. Das ist der ehrliche Zustand: bei ihnen wurde
 * die Zustimmung nicht festgehalten, und eine Migration, die rückwirkend eine
 * erfindet, wäre genau die Fälschung, gegen die die Spalten da sind.
 *
 * Rechtliche Einordnung (Entscheidung 01.09.2026, von Adrian zu prüfen): Der
 * Beleg gehört an die Zahlung, weil jeder Kauf seine eigene Zustimmung braucht.
 * Ein Nachfassangebot erbt die Zustimmung der ersten Bestellung deshalb nicht.
 * Dies ist keine Rechtsberatung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('consent_at')->nullable()->after('name');
            $table->text('consent_text')->nullable()->after('consent_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['consent_at', 'consent_text']);
        });
    }
};
