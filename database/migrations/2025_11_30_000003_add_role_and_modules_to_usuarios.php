<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            if (!Schema::hasColumn('usuarios', 'role')) {
                $table->string('role', 20)->default('admin')->after('password');
            }
            if (!Schema::hasColumn('usuarios', 'modules')) {
                $table->json('modules')->nullable()->after('role');
            }
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            if (Schema::hasColumn('usuarios', 'modules')) {
                $table->dropColumn('modules');
            }
            if (Schema::hasColumn('usuarios', 'role')) {
                $table->dropColumn('role');
            }
        });
    }
};
