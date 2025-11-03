<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ventadesg', function (Blueprint $table) {
            // decimal(10,2) nullable so legacy rows stay valid
            $table->decimal('cargo_tarjeta_proveedor', 10, 2)->nullable()->after('descuento_producto');
        });
    }

    public function down(): void
    {
        Schema::table('ventadesg', function (Blueprint $table) {
            $table->dropColumn('cargo_tarjeta_proveedor');
        });
    }
};
