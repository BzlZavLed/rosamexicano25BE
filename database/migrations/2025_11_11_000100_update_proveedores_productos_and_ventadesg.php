<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            if (!Schema::hasColumn('proveedores', 'tipo')) {
                $table->enum('tipo', ['normal', 'consigna', 'porcentaje'])
                    ->default('normal')
                    ->after('importe');
            }
            if (!Schema::hasColumn('proveedores', 'porcentaje_comision')) {
                $table->unsignedTinyInteger('porcentaje_comision')
                    ->nullable()
                    ->after('tipo');
            }
        });

        DB::table('proveedores')
            ->whereNull('tipo')
            ->update(['tipo' => 'normal']);

        Schema::table('producto', function (Blueprint $table) {
            if (!Schema::hasColumn('producto', 'precio_proveedor')) {
                $table->decimal('precio_proveedor', 11, 2)
                    ->nullable()
                    ->after('precio');
            }
        });

        DB::statement('UPDATE producto SET precio_proveedor = precio WHERE precio_proveedor IS NULL');

        Schema::table('ventadesg', function (Blueprint $table) {
            if (!Schema::hasColumn('ventadesg', 'proveedor_pago')) {
                $table->decimal('proveedor_pago', 12, 2)
                    ->nullable()
                    ->after('cargo_tarjeta_proveedor');
            }
            if (!Schema::hasColumn('ventadesg', 'proveedor_porcentaje')) {
                $table->decimal('proveedor_porcentaje', 5, 2)
                    ->nullable()
                    ->after('proveedor_pago');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ventadesg', function (Blueprint $table) {
            if (Schema::hasColumn('ventadesg', 'proveedor_porcentaje')) {
                $table->dropColumn('proveedor_porcentaje');
            }
            if (Schema::hasColumn('ventadesg', 'proveedor_pago')) {
                $table->dropColumn('proveedor_pago');
            }
        });

        Schema::table('producto', function (Blueprint $table) {
            if (Schema::hasColumn('producto', 'precio_proveedor')) {
                $table->dropColumn('precio_proveedor');
            }
        });

        Schema::table('proveedores', function (Blueprint $table) {
            if (Schema::hasColumn('proveedores', 'porcentaje_comision')) {
                $table->dropColumn('porcentaje_comision');
            }
            if (Schema::hasColumn('proveedores', 'tipo')) {
                $table->dropColumn('tipo');
            }
        });
    }
};
