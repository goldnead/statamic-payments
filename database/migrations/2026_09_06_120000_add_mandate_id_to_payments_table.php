<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Welches Mandat die Erstzahlung hinterlassen hat.
 *
 * Bisher stand neben `customer_reference` nur, WER bezahlt hat, nicht WOMIT er
 * das Einzugsrecht erteilt hat. Bei einer Folgeabbuchung reichte das Paket
 * darum nur die Kundenkennung weiter, und Mollie suchte sich selbst ein
 * gueltiges Mandat des Kunden aus.
 *
 * Solange jemand genau ein Mandat hat, faellt das nie auf. Es faellt auf,
 * sobald er zwei hat: die Seite eines Nachfassangebots kuendigt Marke und
 * letzte vier Ziffern der ERSTZAHLUNG an (`card_last4`/`card_label`, eine
 * Spalte weiter), abgebucht wuerde aber, was der Anbieter gerade fuer den
 * Kunden vorraetig hat. Angekuendigt und belastet waeren dann zwei
 * verschiedene Karten.
 *
 * Die Spalte gehoert neben `customer_reference` und nicht in `meta`: sie ist
 * kein Beiwerk, sondern die zweite Haelfte derselben Auskunft — wer, und
 * womit.
 *
 * **Bewusst nur die Kennung des Anbieters, keine Zahlungsdaten.** `mdt_…` ist
 * ein Verweis auf ein Mandat, das bei Mollie liegt; Kontonummer oder
 * Kartennummer stehen hier nicht und sollen hier nie stehen.
 *
 * **Abos pinnen weiterhin KEIN Mandat.** Das ist Absicht und in
 * `MollieGateway::startMandateUpdate()` begruendet: ein Abo laedt das jeweils
 * gueltige Mandat, damit ein Kartenwechsel ab dem naechsten Zyklus greift,
 * ohne dass die Vereinbarung umgeschrieben werden muss. Diese Spalte gilt der
 * EINZELNEN Folgeabbuchung, bei der die Seite eine konkrete Karte versprochen
 * hat.
 *
 * Bestandszeilen bleiben null. Das ist der ehrliche Zustand — bei ihnen wurde
 * es nicht mitgeschrieben —, und die Folgeabbuchung muss den Fall aushalten:
 * ohne Mandats-Kennung laeuft sie wie bisher, Mollie waehlt dann selbst.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('mandate_id', 191)->nullable()->after('customer_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('mandate_id');
        });
    }
};
