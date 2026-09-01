<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Was zu einer Zahlung hinausging.
 *
 * Eine Mail, die verschickt wurde, hinterlässt sonst nichts: der Mailer
 * meldet Erfolg, das Log rotiert, und drei Wochen später fragt jemand, ob die
 * Rechnung je rausging. Diese Tabelle ist die Antwort. Jede Zeile ist ein
 * Ereignis — eine Mail, eine Webhook-Zustellung, ein Export, eine Notiz — und
 * wird nie geändert, nur angehängt.
 *
 * Geschrieben wird sie von diesem Paket (Portal-Link, Eingangsbestätigungen,
 * Abbruch-Mail) und von jedem, der eine Zahlung anfasst: die Rechnungs-Mail aus
 * statamic-invoices, die Willkommensmail der Seite. Die Fassade dafür ist
 * `PaymentLog`; ein Fehler beim Schreiben bricht keinen Kaufpfad.
 *
 * Idempotent: ein Host, der die Migration schon einmal veröffentlicht hat,
 * bekommt beim zweiten Lauf keinen Fehler.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_communications')) {
            return;
        }

        Schema::create('payment_communications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id')->index();
            // Die Marke der Zahlung, kopiert, damit eine Auswertung je Marke
            // ohne Join auskommt. Null auf Einzelmarken-Installationen.
            $table->unsignedBigInteger('brand_id')->default(0);
            // mail | webhook | export | note
            $table->string('channel', 16)->index();
            // Wofür: invoice, purchase_confirmation, access, receipt, portal_link, …
            $table->string('kind', 64);
            $table->string('recipient', 191)->nullable();
            $table->string('subject', 255)->nullable();
            // sent | failed | queued
            $table->string('status', 16)->default('sent');
            // Was der Kanal als Kennung hergibt: Message-ID, Delivery-UUID.
            $table->string('reference', 191)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('happened_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_communications');
    }
};
