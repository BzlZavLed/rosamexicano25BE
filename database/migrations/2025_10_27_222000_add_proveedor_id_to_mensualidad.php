<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mensualidad')) {
            return;
        }

        Schema::table('mensualidad', function (Blueprint $table) {
            if (!Schema::hasColumn('mensualidad', 'mes_cobro')) {
                $table->string('mes_cobro', 10)->nullable()->after('fecha');
            }
            if (!Schema::hasColumn('mensualidad', 'proveedor_id')) {
                $table->unsignedInteger('proveedor_id')->nullable()->after('importe');
            }
            if (!Schema::hasColumn('mensualidad', 'nota')) {
                $table->string('nota', 200)->nullable()->after('concepto');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('mensualidad')) {
            return;
        }

        Schema::table('mensualidad', function (Blueprint $table) {
            if (Schema::hasColumn('mensualidad', 'mes_cobro')) {
                $table->dropColumn('mes_cobro');
            }
            if (Schema::hasColumn('mensualidad', 'nota')) {
                $table->dropColumn('nota');
            }
            if (Schema::hasColumn('mensualidad', 'proveedor_id')) {
                $table->dropColumn('proveedor_id');
            }
        });
    }
};
