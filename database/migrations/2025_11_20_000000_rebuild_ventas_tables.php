<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('ventadesg');
        Schema::dropIfExists('ventas');

        Schema::create('ventas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idventa')->unique();
            $table->decimal('totalventa', 14, 2);
            $table->decimal('total_recibido', 14, 2);
            $table->enum('metodo', ['efectivo', 'tarjeta', 'transferencia']);
            $table->decimal('cambio', 14, 2)->default(0);
            $table->string('vendedor');
            $table->date('fecha')->index();
            $table->time('hora');
            $table->boolean('receipt_printed')->default(false);
            $table->boolean('receipt_emailed')->default(false);
            $table->timestamps();
        });

        Schema::create('ventadesg', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idventa')->index();
            $table->date('fecha')->index();
            $table->time('hora');
            $table->unsignedBigInteger('producto_id');
            $table->string('nombre');
            $table->unsignedBigInteger('proveedor_id')->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->integer('quantity');
            $table->integer('free_quantity')->default(0);
            $table->decimal('public_total', 14, 2);
            $table->decimal('venta_total', 14, 2);
            $table->decimal('promotion_discount_percentage', 5, 2)->nullable();
            $table->decimal('promotion_discount_amount', 14, 2)->default(0);
            $table->boolean('free_product')->default(false);
            $table->decimal('credit_card_discount', 14, 2)->default(0);
            $table->decimal('provider_percentage_discount', 14, 2)->default(0);
            $table->decimal('consigna_discount', 14, 2)->default(0);
            $table->decimal('provider_cost', 14, 2)->default(0);
            $table->decimal('provider_payment', 14, 2)->default(0);
            $table->decimal('admin_earnings', 14, 2)->default(0);
            $table->timestamps();

            $table->index(['producto_id', 'proveedor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ventadesg');
        Schema::dropIfExists('ventas');
    }
};
