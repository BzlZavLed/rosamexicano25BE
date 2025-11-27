<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_rebalance_logs', function (Blueprint $table) {
            $table->id();
            $table->date('date_param');
            $table->unsignedInteger('venta_id')->nullable();
            $table->unsignedInteger('sales_processed')->default(0);
            $table->unsignedInteger('sales_updated')->default(0);
            $table->unsignedInteger('lines_updated')->default(0);
            $table->text('sale_ids')->nullable();
            $table->text('message')->nullable();
            $table->unsignedBigInteger('triggered_by')->nullable();
            $table->string('triggered_by_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_rebalance_logs');
    }
};
