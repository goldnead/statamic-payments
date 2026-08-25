<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per thing bought.
     *
     * A payment used to carry exactly one product handle and one amount, which
     * is true right up to the first order bump — a checkbox at checkout adding
     * a second item to the same payment. Modelling that as a second payment
     * would be a lie about what the buyer did and would charge them twice.
     *
     * Added now rather than when it is first needed: the addon has no installs
     * yet, so this costs nothing. Later it would be a schema migration running
     * on other people's servers, plus a transitional release that understands
     * both shapes.
     */
    public function up(): void
    {
        Schema::create('payment_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();

            $table->string('product', 191)->index();

            // The name as it was at the time of sale. Not a lookup: a product
            // renamed or removed from the config next year must not change what
            // an old order says was bought.
            $table->string('name', 191);

            // Per unit, in minor units, same rule as the payment total: integers
            // only. `line_total` is not stored — it is quantity × amount, and a
            // stored copy is a second truth that can disagree with the first.
            $table->unsignedInteger('amount_cent');
            $table->unsignedSmallInteger('quantity')->default(1);

            // What this line is, in the buyer's journey. `primary` is what they
            // came for; `bump` is what they ticked on the way; `upsell` is what
            // they accepted after paying. The payment addon does not run those
            // flows — it only has to be able to say which is which when
            // something else does.
            $table->string('kind', 16)->default('primary')->index();

            $table->json('meta')->nullable();
            $table->timestamps();

            // One line per product per payment. A bump ticked twice is a
            // quantity, not a second row.
            $table->unique(['payment_id', 'product']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_items');
    }
};
