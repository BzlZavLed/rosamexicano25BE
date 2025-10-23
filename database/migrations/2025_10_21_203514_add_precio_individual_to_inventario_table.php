<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('inventario', 'precio_individual')) {
            return;
        }

        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'])) {
            Schema::table('inventario', function (Blueprint $table) {
                $table->decimal('precio_individual', 10, 2)
                      ->default(0)
                      ->after('importe');
            });
        } else {
            Schema::table('inventario', function (Blueprint $table) {
                $table->decimal('precio_individual', 10, 2)
                      ->default(0);
            });
        }
    }

    public function down(): void
    {
        Schema::table('inventario', function (Blueprint $table) {
            $table->dropColumn('precio_individual');
        });
    }
};
