<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            if (Schema::hasColumn('ventas', 'descuento_general')) {
                $table->dropColumn('descuento_general');
            }
            if (Schema::hasColumn('ventas', 'descuento_general_porcentaje')) {
                $table->dropColumn('descuento_general_porcentaje');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            if (!Schema::hasColumn('ventas', 'descuento_general')) {
                $table->decimal('descuento_general', 10, 2)->default(0)->after('subtotal');
            }
            if (!Schema::hasColumn('ventas', 'descuento_general_porcentaje')) {
                $table->decimal('descuento_general_porcentaje', 5, 2)->default(0)->after('descuento_general');
            }
        });
    }
};
