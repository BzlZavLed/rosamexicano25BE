<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventadesg', function (Blueprint $table) {
            if (Schema::hasColumn('ventadesg', 'publico_total')) {
                $table->dropColumn('publico_total');
            }
            if (Schema::hasColumn('ventadesg', 'proveedor_pago')) {
                $table->dropColumn('proveedor_pago');
            }
        });

        Schema::table('ventas', function (Blueprint $table) {
            if (!Schema::hasColumn('ventas', 'ingreso_real')) {
                $table->decimal('ingreso_real', 14, 2)->nullable()->after('totalventa');
            }
        });

        DB::statement("
            UPDATE ventas
            SET ingreso_real = CASE
                WHEN LOWER(COALESCE(metodo, '')) IN ('efectivo', 'cash')
                    THEN ROUND(COALESCE(recibo, 0) - COALESCE(cambio, 0), 2)
                ELSE COALESCE(totalventa, 0)
            END
        ");
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            if (Schema::hasColumn('ventas', 'ingreso_real')) {
                $table->dropColumn('ingreso_real');
            }
        });

        Schema::table('ventadesg', function (Blueprint $table) {
            if (!Schema::hasColumn('ventadesg', 'publico_total')) {
                $table->decimal('publico_total', 12, 2)->nullable()->after('total');
            }
            if (!Schema::hasColumn('ventadesg', 'proveedor_pago')) {
                $table->decimal('proveedor_pago', 12, 2)->nullable()->after('cargo_tarjeta_proveedor');
            }
        });
    }
};
