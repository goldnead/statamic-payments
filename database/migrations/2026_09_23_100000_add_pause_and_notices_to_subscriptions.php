<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pausing an agreement, and remembering what it has already been told.
 *
 * **The pause lives on the agreement.** `paused_at` says since when nothing is
 * charged, `resumes_at` when it starts again by itself (null: until somebody
 * resumes it). What the provider needs to take it up again — the old agreement
 * id on Mollie, where a pause is an ended agreement plus a new one later — sits
 * in `meta.pause`, because it is the provider's detail, not a fact a report
 * reads.
 *
 * **The card's expiry is copied here** because the reminder run asks about
 * hundreds of agreements at once, and asking the provider for every one of them
 * every day is an API budget spent on an answer that changes once in years.
 * `card_checked_at` is when it was last asked.
 *
 * **`payment_subscription_notices` is the claim table.** One row per agreement,
 * kind and reference — "upcoming charge on 2026-10-01", "card expiring
 * 2026-12-31", "failed attempt on payment 812". The unique index is the whole
 * idempotency story: a second scheduler run, a second worker, a redelivered
 * webhook all hit the index and send nothing twice. The same rule this package
 * applies to chargebacks and webhook events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('resumes_at')->nullable()->index();
            $table->date('card_expires_at')->nullable();
            $table->timestamp('card_checked_at')->nullable();
        });

        Schema::create('payment_subscription_notices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscription_id')->index();
            $table->string('kind', 32);
            $table->string('reference', 64);
            $table->timestamp('created_at')->nullable();

            $table->unique(['subscription_id', 'kind', 'reference'], 'payment_subscription_notices_claim');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_subscription_notices');

        if (Schema::hasColumn('subscriptions', 'resumes_at')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropIndex('subscriptions_resumes_at_index');
            });

            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropColumn(['paused_at', 'resumes_at', 'card_expires_at', 'card_checked_at']);
            });
        }
    }
};
