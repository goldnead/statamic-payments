<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two facts an invoice needs and a payment did not keep.
 *
 * Both are here rather than in an invoicing addon on purpose: they cannot be
 * reconstructed afterwards. The VAT rate on a digital sale to a consumer in the
 * EU depends on the buyer's country, and a country not recorded at the time of
 * the payment is gone — the buyer's address may change, the record may be
 * deleted, and the tax office does not accept "we looked it up later". A
 * discount split across lines is the same: once the total carries a single
 * number, which part of it belonged to the 7% line and which to the 19% one is
 * unrecoverable.
 *
 * So the point of this migration is timing, not schema. Every real sale that
 * happens before it lands is a row that can never be invoiced correctly.
 *
 * Existing rows stay null. That is the honest state — they were taken without
 * this being recorded — and everything downstream has to tolerate it rather
 * than guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // ISO 3166-1 alpha-2, frozen at checkout. Deliberately not a
            // reference to a customer record: Cargo freezes its orders for the
            // same reason, and an order that changes when a customer edits
            // their profile is not an order, it is a view.
            $table->string('country', 2)->nullable()->after('name');

            // Where it came from, because the EU wants two non-contradictory
            // pieces of evidence for a consumer's location and "somebody typed
            // it" and "the card issuer said so" are worth different things.
            $table->string('country_source', 32)->nullable()->after('country');
        });

        Schema::table('payment_items', function (Blueprint $table) {
            // The share of the payment's discount that fell on this line.
            // Without it a single discount over lines at different VAT rates
            // makes the invoice indeterminate — and not visibly wrong, which is
            // worse.
            $table->unsignedInteger('discount_cent')->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['country', 'country_source']);
        });

        Schema::table('payment_items', function (Blueprint $table) {
            $table->dropColumn('discount_cent');
        });
    }
};
