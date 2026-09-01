<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widerrufe nach § 356a BGB — eine Zeile je Erklärung.
 *
 * Die Zeile entsteht mit Schritt 1 (`declared_at`) und wird mit Schritt 2
 * (`confirmed_at`, der eigentliche Widerruf) verbindlich. Beides getrennt, weil
 * das Gesetz zwei Schaltflächen verlangt und ein abgebrochener Schritt 1 kein
 * Widerruf ist.
 *
 * `payment_id` ist nullable und das mit Absicht: die Zuordnung zur Zahlung
 * passiert **nach** der Erklärung, serverseitig, nur bei eindeutigem Treffer.
 * Eine Erklärung ohne Treffer ist trotzdem zugegangen und wird dem Händler
 * gemeldet. Das Formular bestätigt dem Absender nie, ob eine Bestellung
 * existiert — sonst wäre es ein Orakel dafür, wer hier gekauft hat.
 *
 * `ip_hash` statt Adresse: ein Nachweis, dass zwei Erklärungen vom selben
 * Anschluss kamen, braucht die Adresse nicht im Klartext, und eine Tabelle mit
 * Namen, Adressen und IPs ist eine Tabelle, die man nicht führen möchte.
 *
 * Rechtliche Entscheidungen 01.09.2026, von Adrian zu prüfen. Keine
 * Rechtsberatung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_withdrawals', function (Blueprint $table) {
            $table->id();

            // Die Kennung, die der Verbraucher in der Eingangsbestätigung
            // liest: `W-` und acht Zeichen ohne 0/O/1/I. Kurz genug zum
            // Abtippen, lang genug, dass sie nicht erraten wird.
            $table->string('public_id', 20)->unique();

            $table->unsignedBigInteger('payment_id')->nullable()->index();
            $table->unsignedBigInteger('brand_id')->default(0)->index();

            $table->string('name', 191);
            $table->string('email', 191)->index();
            // Was der Kunde als Bestellkennung eingegeben hat, unverändert.
            $table->string('order_reference', 191);
            // Wie der Kunde erreicht werden will. Vorbelegt mit der Adresse.
            $table->string('contact', 191);
            $table->text('message')->nullable();

            $table->timestamp('declared_at');
            $table->timestamp('confirmed_at')->nullable()->index();
            $table->timestamp('receipt_sent_at')->nullable();
            $table->timestamp('merchant_notified_at')->nullable();

            // Am Treffer stand `consent_at`: das Widerrufsrecht ist nach § 356
            // Abs. 5 BGB erloschen. Ein Hinweis an den Händler, keine Ablehnung.
            $table->boolean('right_expired_hint')->default(false);

            $table->timestamp('handled_at')->nullable()->index();
            $table->text('handled_note')->nullable();

            $table->string('ip_hash', 64)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_withdrawals');
    }
};
