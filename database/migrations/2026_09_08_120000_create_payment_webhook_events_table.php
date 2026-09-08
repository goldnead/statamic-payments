<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which webhook deliveries have already been acted on.
 *
 * Mollie needs none of this: its webhook posts an id and nothing else, and the
 * work it triggers is claimed with a conditional UPDATE on the payment itself.
 * A Stripe webhook carries a whole event — a refund, a cycle, a cancellation —
 * and Stripe redelivers every one of them until it gets a 2xx. Acting twice on
 * `charge.refunded` books the refund twice, and "the customer was refunded
 * three times" is a number that ends up in an annual return.
 *
 * The claim is the unique index, not a lookup. `SELECT then INSERT` loses to a
 * second delivery that reads before the first writes, which is exactly what two
 * redeliveries arriving milliseconds apart look like — the failure mode named in
 * `feedback-pruefen-statt-beanspruchen`. The row goes in first; the database
 * says whether this delivery is the one that won.
 *
 * Keyed by provider as well as event id. Two providers' id spaces have no
 * agreement not to collide, and a collision here would silently swallow a real
 * delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('event_id', 191);
            $table->string('event_type', 191)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
