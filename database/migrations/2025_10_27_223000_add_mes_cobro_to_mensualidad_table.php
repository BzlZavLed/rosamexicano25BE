<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mensualidad')) {
            return;
        }

        Schema::table('mensualidad', function (Blueprint $table) {
            if (!Schema::hasColumn('mensualidad', 'mes_cobro')) {
                $table->string('mes_cobro', 10)->nullable()->after('concepto');
            }
        });

        if (Schema::hasColumn('mensualidad', 'mes_cobro') && Schema::hasColumn('mensualidad', 'concepto')) {
            DB::table('mensualidad')
                ->whereNull('mes_cobro')
                ->update(['mes_cobro' => DB::raw('concepto')]);
        }
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
        });
    }
};

