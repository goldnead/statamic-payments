<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a follow-up offer needs to exist.
     *
     * `customer_reference` is what the provider left behind when the buyer
     * agreed to be charged again — for Mollie the customer id. It is null on
     * every payment where they did not agree, which is the default, and a null
     * here is what refuses a later charge.
     *
     * `parent_payment_id` says which order a follow-up grew out of. Without it
     * an upsell looks like an unrelated second purchase and nobody can answer
     * "did the offer work".
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('customer_reference', 191)->nullable()->after('provider_id');
            $table->foreignId('parent_payment_id')->nullable()->after('id')
                ->constrained('payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_payment_id');
            $table->dropColumn('customer_reference');
        });
    }
};
