<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('clientes') || Schema::hasColumn('clientes', 'telefono')) {
            return;
        }

        Schema::table('clientes', function (Blueprint $table) {
            $table->string('telefono', 25)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('clientes')) {
            return;
        }

        if (Schema::hasColumn('clientes', 'telefono')) {
            Schema::table('clientes', function (Blueprint $table) {
                $table->dropColumn('telefono');
            });
        }
    }
};

