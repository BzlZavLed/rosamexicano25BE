<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('mensualidad');

        Schema::create('mensualidad', function (Blueprint $table) {
            $table->increments('id');
            $table->date('fecha');
            $table->string('mes_cobro', 10);
            $table->string('nombre', 200);
            $table->string('concepto', 200);
            $table->string('nota', 200)->nullable();
            $table->decimal('importe', 10, 2);
            $table->unsignedInteger('proveedor_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->date('payment_date')->nullable();
            $table->string('receipt_path', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mensualidad');
    }
};
