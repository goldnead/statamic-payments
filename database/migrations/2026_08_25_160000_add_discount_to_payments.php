<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Why this payment is smaller than its lines add up to. Kept on the
            // payment rather than worked out again later: a coupon that expires
            // or is edited next month must not change what an old receipt says.
            $table->string('discount_code', 64)->nullable()->after('amount_cent');
            $table->unsignedInteger('discount_cent')->nullable()->after('discount_code');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['discount_code', 'discount_cent']);
        });
    }
};
