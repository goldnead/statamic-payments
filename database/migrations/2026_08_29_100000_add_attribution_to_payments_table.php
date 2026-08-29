<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the sale came from, frozen at the checkout.
 *
 * The same argument as the tax facts one door down: it cannot be reconstructed
 * afterwards. A visitor arrives from a newsletter, browses for three days and
 * then buys — by the time the money lands, the campaign that produced it exists
 * nowhere but in that session. Asked a week later, nobody can answer, and the
 * question "which newsletter sold anything" stays unanswerable forever.
 *
 * The column names are LeadHub's, letter for letter
 * (`2026_06_30_000001_add_attribution_to_leadhub_contacts_table`). Two sides
 * that store the same fact under different names spend the rest of their lives
 * translating, and every translation is a place to be wrong.
 *
 * Not read from the request here. The checkout deliberately reads no request at
 * all — the amount does not come from one and neither does this — so the host
 * hands the values in as caller details, the same seam `country` uses. What the
 * host reads them out of (a session, a cookie, a signed link) is its decision;
 * the addon only refuses to invent them.
 *
 * Existing rows stay null, which is the honest state: they were taken before
 * anybody wrote this down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('utm_source')->nullable()->after('country_source');
            $table->string('utm_medium')->nullable()->after('utm_source');
            $table->string('utm_campaign')->nullable()->after('utm_medium');
            $table->string('utm_term')->nullable()->after('utm_campaign');
            $table->string('utm_content')->nullable()->after('utm_term');

            // Wide, because a referrer is a URL and a truncated URL is worse
            // than none: it looks like an answer.
            $table->string('referrer', 1024)->nullable()->after('utm_content');
            $table->string('landing_page', 1024)->nullable()->after('referrer');

            // The one attribution question anybody actually runs: what did this
            // campaign bring in. Named so the rollback can drop it.
            $table->index(['utm_campaign', 'status'], 'payments_campaign_index');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_campaign_index');

            $table->dropColumn([
                'utm_source',
                'utm_medium',
                'utm_campaign',
                'utm_term',
                'utm_content',
                'referrer',
                'landing_page',
            ]);
        });
    }
};
