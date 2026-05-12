<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('promociones')) {
            return;
        }

        Schema::table('promociones', function (Blueprint $table) {
            if (!Schema::hasColumn('promociones', 'monto')) {
                $table->decimal('monto', 12, 2)->nullable()->after('descuento');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('promociones') || !Schema::hasColumn('promociones', 'monto')) {
            return;
        }

        Schema::table('promociones', function (Blueprint $table) {
            $table->dropColumn('monto');
        });
    }
};
