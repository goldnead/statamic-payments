<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kündigungen nach § 312k BGB, erklärt ohne Login — eine Zeile je Erklärung.
 *
 * Gebaut wie `payment_withdrawals` und bewusst nicht in derselben Tabelle: ein
 * Widerruf löst einen Vertrag rückwirkend, eine Kündigung beendet ihn für die
 * Zukunft, und ein Händler, der beides in einer Liste sieht, verwechselt es.
 *
 * `subscription_id` ist nullable: die Zuordnung zum Abo passiert nach der
 * Erklärung, nur bei eindeutigem Treffer, und ein eindeutig getroffenes
 * **laufendes** Abo wird beim Anbieter gekündigt (`provider_cancelled_at`).
 * Alles andere bleibt Erklärung und wird dem Händler gemeldet. Die
 * Eingangsbestätigung geht in jedem Fall an den Verbraucher — die Erklärung ist
 * zugegangen, ob wir sie zuordnen konnten oder nicht.
 *
 * `kind` und `reason`: § 312k Abs. 2 Nr. 1 nennt die Art der Kündigung und bei
 * der außerordentlichen den Grund. `effective_at` ist der vom Verbraucher
 * genannte Zeitpunkt, null heißt „frühestmöglich".
 *
 * Rechtliche Entscheidungen 01.09.2026, von Adrian zu prüfen. Keine
 * Rechtsberatung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_cancellations', function (Blueprint $table) {
            $table->id();

            $table->string('public_id', 20)->unique();

            $table->unsignedBigInteger('subscription_id')->nullable()->index();
            $table->unsignedBigInteger('brand_id')->default(0)->index();

            $table->string('name', 191);
            $table->string('email', 191)->index();
            // Vertrags- oder Kundenkennung, wie der Kunde sie eingegeben hat.
            $table->string('identification', 191);

            $table->string('kind', 16); // ordinary | extraordinary
            $table->text('reason')->nullable();
            $table->date('effective_at')->nullable();

            $table->timestamp('declared_at');
            $table->timestamp('confirmed_at')->nullable()->index();
            $table->timestamp('receipt_sent_at')->nullable();
            $table->timestamp('merchant_notified_at')->nullable();

            $table->timestamp('provider_cancelled_at')->nullable();

            $table->timestamp('handled_at')->nullable()->index();
            $table->text('handled_note')->nullable();

            $table->string('ip_hash', 64)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_cancellations');
    }
};
