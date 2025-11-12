<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventadesg', function (Blueprint $table) {
            if (!Schema::hasColumn('ventadesg', 'publico_total')) {
                $table->decimal('publico_total', 12, 2)->nullable()->after('total');
            }
            if (!Schema::hasColumn('ventadesg', 'proveedor_bruto')) {
                $table->decimal('proveedor_bruto', 12, 2)->nullable()->after('publico_total');
            }
            if (!Schema::hasColumn('ventadesg', 'proveedor_descuento')) {
                $table->decimal('proveedor_descuento', 12, 2)->nullable()->after('proveedor_bruto');
            }
            if (!Schema::hasColumn('ventadesg', 'proveedor_neto')) {
                $table->decimal('proveedor_neto', 12, 2)->nullable()->after('proveedor_descuento');
            }
            if (!Schema::hasColumn('ventadesg', 'admin_ganancia')) {
                $table->decimal('admin_ganancia', 12, 2)->nullable()->after('proveedor_neto');
            }
        });

        Schema::table('ventadesg', function (Blueprint $table) {
            foreach (['totdesc', 'product_desc'] as $column) {
                if (Schema::hasColumn('ventadesg', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('ventas', function (Blueprint $table) {
            if (!Schema::hasColumn('ventas', 'costo_total')) {
                $table->decimal('costo_total', 14, 2)->nullable()->after('subtotal');
            }
            if (!Schema::hasColumn('ventas', 'ganancia_total')) {
                $table->decimal('ganancia_total', 14, 2)->nullable()->after('costo_total');
            }
        });

        Schema::table('ventas', function (Blueprint $table) {
            foreach (['descuento_general', 'descuento_general_porcentaje'] as $column) {
                if (Schema::hasColumn('ventas', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('ventadesg', function (Blueprint $table) {
            foreach (['publico_total','proveedor_bruto','proveedor_descuento','proveedor_neto','admin_ganancia'] as $column) {
                if (Schema::hasColumn('ventadesg', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::table('ventadesg', function (Blueprint $table) {
            if (!Schema::hasColumn('ventadesg', 'totdesc')) {
                $table->decimal('totdesc', 10, 2)->default(0)->after('total');
            }
            if (!Schema::hasColumn('ventadesg', 'product_desc')) {
                $table->decimal('product_desc', 10, 2)->default(0)->after('totdesc');
            }
        });

        Schema::table('ventas', function (Blueprint $table) {
            foreach (['costo_total','ganancia_total'] as $column) {
                if (Schema::hasColumn('ventas', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::table('ventas', function (Blueprint $table) {
            if (!Schema::hasColumn('ventas', 'descuento_general')) {
                $table->decimal('descuento_general', 12, 2)->default(0)->after('subtotal');
            }
            if (!Schema::hasColumn('ventas', 'descuento_general_porcentaje')) {
                $table->decimal('descuento_general_porcentaje', 5, 2)->default(0)->after('descuento_general');
            }
        });
    }
};
