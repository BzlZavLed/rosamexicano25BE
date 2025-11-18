<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('provider_restock_forecasts', function (Blueprint $table) {
            $table->id();
            $table->date('forecast_date');
            $table->string('provider_ident');
            $table->string('provider_name')->nullable();
            $table->string('producto_ident');
            $table->string('producto_nombre')->nullable();
            $table->decimal('avg_daily_sales', 12, 4)->default(0);
            $table->unsignedInteger('lookback_days')->default(30);
            $table->unsignedInteger('lead_time_days')->default(7);
            $table->decimal('projected_demand', 12, 4)->default(0);
            $table->integer('inventory_on_hand')->default(0);
            $table->integer('suggested_order_qty')->default(0);
            $table->decimal('days_of_cover', 8, 2)->nullable();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->unique(['forecast_date', 'provider_ident', 'producto_ident'], 'forecast_provider_product_unique');
            $table->index(['provider_ident']);
            $table->index(['producto_ident']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_restock_forecasts');
    }
};
