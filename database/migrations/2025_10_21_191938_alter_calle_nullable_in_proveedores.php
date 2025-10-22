<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            // adjust length to whatever your column currently uses (100 in our schema)
            $table->string('calle', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        // If any NULLs exist, replace them before restoring NOT NULL
        DB::table('proveedores')->whereNull('calle')->update(['calle' => '']);
        Schema::table('proveedores', function (Blueprint $table) {
            $table->string('calle', 100)->nullable(false)->change();
        });
    }
};
