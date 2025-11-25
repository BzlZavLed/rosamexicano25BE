<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venta_cancelaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('venta_id');
            $table->string('idventa')->nullable();
            $table->unsignedBigInteger('admin_id');
            $table->string('reason', 255)->nullable();
            $table->json('venta_payload');
            $table->json('lineas_payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_cancelaciones');
    }
};
