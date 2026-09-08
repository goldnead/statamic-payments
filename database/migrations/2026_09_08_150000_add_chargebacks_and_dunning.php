<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money taken back by the bank, and the attempt to keep a customer before it is.
 *
 * **A chargeback is not a refund**, and it does not go in `refunded_cent`. A
 * refund is a decision somebody made; a chargeback is one made against them. It
 * carries a fee, it carries a dispute path, and a report that counted the two
 * together would be wrong about both. So it gets its own state.
 *
 * The claim is a unique index, like every other place in this package where a
 * provider may say the same thing twice: `payment_chargebacks` is what makes a
 * redelivered dispute a no-op, rather than a second revocation and a second
 * event.
 *
 * **Dunning lives on the agreement, not on the failed payment.** The point of
 * the sequence is the agreement — one failed cycle opens it, and either a
 * working card closes it or the agreement ends. `dunning_stage` is what the
 * conditional UPDATE claims, so two workers can never send the same letter
 * twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // The state, and only the state. The amount and the provider's own
            // id live on the claim rows, because a payment can in principle be
            // disputed more than once.
            $table->timestamp('charged_back_at')->nullable()->after('refunded_at');
        });

        Schema::create('payment_chargebacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->index();
            // The provider's own id for this dispute. Stripe gives one per
            // dispute (`dp_…`); Mollie announces a chargeback on the payment
            // itself, so there the payment's id stands in — which means a
            // second, separate Mollie chargeback on the same payment is seen as
            // the same one. That is the honest trade for not making an extra
            // API call on every ordinary webhook, and it is written down here
            // rather than discovered later.
            $table->string('reference', 191);
            $table->unsignedInteger('amount_cent')->default(0);
            $table->string('reason', 191)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['payment_id', 'reference']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            // Null means no sequence is running. Set when a cycle charge fails,
            // cleared the moment the provider says the money arrived after all.
            $table->timestamp('dunning_started_at')->nullable();
            // Which letters have gone out. 0 is "the sequence has begun and
            // nothing has been sent"; the conditional UPDATE moves it one step
            // at a time, and that move IS the claim.
            $table->unsignedTinyInteger('dunning_stage')->default(0);
            $table->timestamp('dunning_last_at')->nullable();
            // The cycle payment that opened it. Asked of the provider before
            // every letter — if it went through, nobody gets another one.
            $table->unsignedBigInteger('dunning_payment_id')->nullable();

            $table->index(['dunning_started_at', 'dunning_stage']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('charged_back_at');
        });

        Schema::dropIfExists('payment_chargebacks');

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['dunning_started_at', 'dunning_stage']);
            $table->dropColumn(['dunning_started_at', 'dunning_stage', 'dunning_last_at', 'dunning_payment_id']);
        });
    }
};
