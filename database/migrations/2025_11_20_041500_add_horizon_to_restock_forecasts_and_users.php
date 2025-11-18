<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('provider_restock_forecasts', function (Blueprint $table) {
            if (!Schema::hasColumn('provider_restock_forecasts', 'horizon')) {
                $table->string('horizon', 16)->default('week')->after('forecast_date');
            }
            $table->dropUnique('forecast_provider_product_unique');
            $table->unique(['forecast_date', 'horizon', 'provider_ident', 'producto_ident'], 'forecast_provider_product_unique');
        });

        Schema::table('usuarios', function (Blueprint $table) {
            if (!Schema::hasColumn('usuarios', 'restock_horizon')) {
                $table->string('restock_horizon', 16)->default('2w');
            }
        });
    }

    public function down(): void
    {
        Schema::table('provider_restock_forecasts', function (Blueprint $table) {
            if (Schema::hasColumn('provider_restock_forecasts', 'horizon')) {
                $table->dropUnique('forecast_provider_product_unique');
                $table->dropColumn('horizon');
                $table->unique(['forecast_date', 'provider_ident', 'producto_ident'], 'forecast_provider_product_unique');
            }
        });

        Schema::table('usuarios', function (Blueprint $table) {
            if (Schema::hasColumn('usuarios', 'restock_horizon')) {
                $table->dropColumn('restock_horizon');
            }
        });
    }
};
