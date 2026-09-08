<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which refunds have already been booked.
 *
 * `payments.refunded_cent` and `payments.meta['refunds']` said this before, and
 * with Mollie that was enough: refunds are entered by hand in a dashboard, one
 * at a time, so no two announcements ever raced. Stripe announces two partial
 * refunds as two events, and its event carries the charge's **whole** refund
 * list — so a booking lost to a race comes back as new on the next delivery and
 * is counted twice.
 *
 * A row lock is not the answer here, and that is worth writing down: Laravel's
 * `lockForUpdate()` compiles to an empty string on SQLite
 * (`Query\Grammars\SQLiteGrammar::compileLock()`), and this addon's own
 * requirements say "a database", not "a database that can lock a row". A
 * unique index is not a hint. Every engine enforces it the same way.
 *
 * So the insert is the claim, like `payment_webhook_events`: whoever gets the
 * row books the money, and the loser is told it was already booked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->index();
            // The provider's own id for this refund. Nullable is not allowed
            // here: a claim without a reference claims nothing, and that case
            // goes down the unclaimed path in `Refunds::record()` instead.
            $table->string('reference', 191);
            $table->unsignedInteger('amount_cent');
            $table->timestamp('created_at')->nullable();

            $table->unique(['payment_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
