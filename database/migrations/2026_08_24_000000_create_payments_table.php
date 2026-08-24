<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            // The provider's id. Unique, not merely indexed: it is what makes a
            // redelivered webhook idempotent, and the database is the only place
            // that can hold that under concurrency.
            $table->string('provider', 32)->default('mollie');

            // Indexed on its own as well as inside the unique below. The unique
            // leads with `provider`, and a lookup by provider id alone cannot
            // use a composite index it does not lead — every webhook would scan
            // a table that only ever grows.
            $table->string('provider_id', 191)->index();

            $table->string('product', 191)->index();

            // Money as integer minor units and an explicit currency. A float
            // here is the classic way to lose a cent per thousand orders, and a
            // currency-less amount is the classic way to charge 39 of the wrong
            // thing.
            $table->unsignedInteger('amount_cent');
            $table->string('currency', 3)->default('EUR');

            $table->string('status', 32)->default('initiated')->index();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->json('meta')->nullable();

            // When fulfilment ran. Its presence is the guard against granting
            // twice, so it is a timestamp and not a boolean: "when" answers
            // "whether" and one more question besides.
            $table->timestamp('fulfilled_at')->nullable();

            // The same idea for the other direction: a failure is announced
            // once, however many times the provider tells us about it.
            $table->timestamp('failed_notified_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
