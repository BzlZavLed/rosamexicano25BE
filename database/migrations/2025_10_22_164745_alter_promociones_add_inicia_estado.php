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
        // 1) Convert vence (text/varchar) -> date using explicit USING clause
        DB::statement("
            ALTER TABLE promociones
            ALTER COLUMN vence TYPE date
            USING to_date(vence, 'YYYY-MM-DD')
        ");

        // 2) (optional) enforce NOT NULL if you want it required
        DB::statement("ALTER TABLE promociones ALTER COLUMN vence SET NOT NULL");

        // 3) Add inicia & estado
        Schema::table('promociones', function (Blueprint $table) {
            if (!Schema::hasColumn('promociones', 'inicia')) {
                $table->date('inicia')->nullable()->after('tipo');
            }
            if (!Schema::hasColumn('promociones', 'estado')) {
                $table->boolean('estado')->default(true)->after('inicia');
            }
        });
    }

    public function down(): void
    {
        // Drop added columns
        Schema::table('promociones', function (Blueprint $table) {
            if (Schema::hasColumn('promociones', 'estado')) $table->dropColumn('estado');
            if (Schema::hasColumn('promociones', 'inicia')) $table->dropColumn('inicia');
        });

        // Revert vence to varchar(10)
        DB::statement("
            ALTER TABLE promociones
            ALTER COLUMN vence TYPE varchar(10)
            USING to_char(vence, 'YYYY-MM-DD')
        ");
    }
};
