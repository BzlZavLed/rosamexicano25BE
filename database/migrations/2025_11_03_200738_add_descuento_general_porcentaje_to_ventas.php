<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ventas')) {
            return;
        }

        Schema::table('ventas', function (Blueprint $table) {
            if (!Schema::hasColumn('ventas', 'descuento_general_porcentaje')) {
                $table->decimal('descuento_general_porcentaje', 5, 2)->default(0)->after('descuento_general');
            }
        });

        if (Schema::hasColumn('ventas', 'descuento_general') && Schema::hasColumn('ventas', 'subtotal')) {
            DB::statement("UPDATE ventas SET descuento_general_porcentaje = COALESCE(descuento_general, 0), descuento_general = ROUND(subtotal * (COALESCE(descuento_general, 0) / 100), 2)");
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('ventas')) {
            return;
        }

        if (Schema::hasColumn('ventas', 'descuento_general') && Schema::hasColumn('ventas', 'descuento_general_porcentaje')) {
            DB::statement("UPDATE ventas SET descuento_general = CASE WHEN subtotal <> 0 THEN ROUND((descuento_general / NULLIF(subtotal,0)) * 100, 2) ELSE 0 END");
        }

        Schema::table('ventas', function (Blueprint $table) {
            if (Schema::hasColumn('ventas', 'descuento_general_porcentaje')) {
                $table->dropColumn('descuento_general_porcentaje');
            }
        });
    }
};
