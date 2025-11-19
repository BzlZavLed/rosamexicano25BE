<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('historic_ventas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('legacy_id')->unique();
            $table->unsignedBigInteger('legacy_idventa')->nullable()->index();
            $table->decimal('totalventa', 12, 2)->default(0);
            $table->string('metodo', 120)->nullable();
            $table->decimal('recibo', 12, 2)->default(0);
            $table->decimal('cambio', 12, 2)->default(0);
            $table->string('vendedor')->nullable();
            $table->date('fecha')->nullable()->index();
            $table->string('ie', 20)->nullable();
            $table->string('concepto')->nullable();
            $table->timestamps();
        });

        Schema::create('historic_ventadesg', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('legacy_id')->unique();
            $table->unsignedBigInteger('venta_legacy_id')->nullable()->index();
            $table->date('fecha')->nullable()->index();
            $table->string('producto_ident')->nullable();
            $table->string('producto_nombre')->nullable();
            $table->string('proveedor_ident')->nullable();
            $table->decimal('precio_unitario', 12, 2)->nullable();
            $table->decimal('cantidad', 12, 2)->nullable();
            $table->decimal('total', 12, 2)->nullable();
            $table->decimal('total_descuento', 12, 2)->nullable();
            $table->string('hora', 32)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historic_ventadesg');
        Schema::dropIfExists('historic_ventas');
    }
};
