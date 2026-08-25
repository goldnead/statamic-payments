<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money that went back.
 *
 * An amount and a time, not a status. A status cannot express a partial refund
 * without lying: an order half repaid is neither "paid" nor "refunded", and
 * every report built on a status would have to pick one and be wrong about the
 * other. So this is a third axis beside status and fulfilment, the same way
 * `fulfilled_at` is one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedInteger('refunded_cent')->default(0)->after('discount_cent');
            $table->timestamp('refunded_at')->nullable()->after('refunded_cent');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['refunded_cent', 'refunded_at']);
        });
    }
};
