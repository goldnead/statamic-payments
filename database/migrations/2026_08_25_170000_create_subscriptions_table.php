<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();

            $table->string('provider', 32)->index();
            $table->string('provider_id', 191)->index();

            // Whose stored agreement this runs on. Without one there is no
            // subscription: a provider cannot charge a card nobody put on file.
            $table->string('customer_reference', 191)->index();

            $table->string('product', 191)->index();

            // What each cycle costs. Looked up once from the catalogue when the
            // subscription started and kept here, because a price change next
            // year must not silently re-price somebody's running agreement.
            $table->unsignedInteger('amount_cent');
            $table->string('currency', 3);

            // The provider's own vocabulary: "1 month", "12 weeks". Kept as a
            // string rather than parsed into a number and a unit, because the
            // set of units is the provider's to define and a parser here would
            // be a second, worse copy of it.
            $table->string('interval', 32);

            // Null means "until somebody cancels" — a subscription. A number
            // means "this many and then done" — a payment plan. Same mechanism,
            // and the difference between the two is one column.
            $table->unsignedSmallInteger('times')->nullable();
            $table->unsignedSmallInteger('times_charged')->default(0);

            $table->string('status', 32)->index();

            // When the first cycle is due. In the future for a trial.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('next_payment_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            $table->string('email')->nullable()->index();
            $table->string('name')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            // One row per provider subscription. A redelivered webhook must not
            // be able to create a second.
            $table->unique(['provider', 'provider_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            // Which subscription this payment is a cycle of, if it is one.
            $table->unsignedBigInteger('subscription_id')->nullable()->after('parent_payment_id')->index();
        });
    }

    public function down(): void
    {
        // The index first, and only if the column is there at all. SQLite
        // refuses to drop a column an index still points at, and a rollback of
        // a migration that never fully ran must not be the thing that breaks.
        if (Schema::hasColumn('payments', 'subscription_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropIndex('payments_subscription_id_index');
            });

            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn('subscription_id');
            });
        }

        Schema::dropIfExists('subscriptions');
    }
};
