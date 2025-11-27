<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_rebalance_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('venta_id');
            $table->unsignedBigInteger('ventadesg_id');
            $table->date('fecha_sale');
            $table->decimal('public_total', 14, 2)->default(0);
            $table->decimal('total_venta', 14, 2)->default(0);
            $table->decimal('old_credit_card_discount', 14, 2)->default(0);
            $table->decimal('new_credit_card_discount', 14, 2)->default(0);
            $table->unsignedInteger('proveedor_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_rebalance_changes');
    }
};
