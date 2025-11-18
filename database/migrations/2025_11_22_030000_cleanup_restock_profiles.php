<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('product_restock_profiles')) {
            Schema::drop('product_restock_profiles');
        }

        if (Schema::hasColumn('provider_restock_forecasts', 'target_cover_days')) {
            Schema::table('provider_restock_forecasts', function (Blueprint $table) {
                $table->dropColumn('target_cover_days');
            });
        }
    }

    public function down(): void
    {
        // No-op: legacy table/column intentionally removed.
    }
};
