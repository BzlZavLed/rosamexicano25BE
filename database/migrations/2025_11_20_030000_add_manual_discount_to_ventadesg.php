<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ventadesg', function (Blueprint $table) {
            if (!Schema::hasColumn('ventadesg', 'manual_discount_amount')) {
                $table->decimal('manual_discount_amount', 14, 2)->default(0)->after('promotion_discount_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ventadesg', function (Blueprint $table) {
            if (Schema::hasColumn('ventadesg', 'manual_discount_amount')) {
                $table->dropColumn('manual_discount_amount');
            }
        });
    }
};
