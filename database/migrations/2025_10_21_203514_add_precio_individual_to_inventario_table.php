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
        Schema::table('inventario', function (Blueprint $table) {
            // numeric(10,2) with default 0 for PostgreSQL
            $table->decimal('precio_individual', 10, 2)
                   ->default(0)
                   ->after('cantidad'); // or after another column, adjust as needed
        });
    }

    public function down(): void
    {
        Schema::table('inventario', function (Blueprint $table) {
            $table->dropColumn('precio_individual');
        });
    }
};
