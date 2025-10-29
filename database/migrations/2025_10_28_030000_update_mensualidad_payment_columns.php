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
            if (!Schema::hasColumn('mensualidad', 'cantidad_pago')) {
                $table->decimal('cantidad_pago', 10, 2)->default(0)->after('importe');
            }
            if (!Schema::hasColumn('mensualidad', 'restante')) {
                $table->decimal('restante', 10, 2)->default(0)->after('cantidad_pago');
            }
            if (!Schema::hasColumn('mensualidad', 'pago_completo')) {
                $table->boolean('pago_completo')->default(false)->after('restante');
            }
            if (!Schema::hasColumn('mensualidad', 'cobro_path')) {
                $table->string('cobro_path', 255)->nullable()->after('receipt_path');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('mensualidad')) {
            return;
        }

        Schema::table('mensualidad', function (Blueprint $table) {
            if (Schema::hasColumn('mensualidad', 'cobro_path')) {
                $table->dropColumn('cobro_path');
            }
            if (Schema::hasColumn('mensualidad', 'pago_completo')) {
                $table->dropColumn('pago_completo');
            }
            if (Schema::hasColumn('mensualidad', 'restante')) {
                $table->dropColumn('restante');
            }
            if (Schema::hasColumn('mensualidad', 'cantidad_pago')) {
                $table->dropColumn('cantidad_pago');
            }
        });
    }
};

