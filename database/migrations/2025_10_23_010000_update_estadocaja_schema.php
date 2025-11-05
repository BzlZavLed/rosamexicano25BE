<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('estadocaja')) {
            return;
        }

        if (Schema::hasColumn('estadocaja', 'saldo') && !Schema::hasColumn('estadocaja', 'saldoinicial')) {
            Schema::table('estadocaja', function (Blueprint $table) {
                $table->renameColumn('saldo', 'saldoinicial');
            });
        }

        if (Schema::hasColumn('estadocaja', 'saldoinicial')) {
            Schema::table('estadocaja', function (Blueprint $table) {
                $table->decimal('saldoinicial', 11, 2)->default(0)->change();
            });
        }

        if (!Schema::hasColumn('estadocaja', 'saldofinal')) {
            Schema::table('estadocaja', function (Blueprint $table) {
                $table->decimal('saldofinal', 11, 2)->default(0);
            });

            DB::table('estadocaja')->update([
                'saldofinal' => DB::raw('COALESCE(saldofinal, saldoinicial)')
            ]);

            Schema::table('estadocaja', function (Blueprint $table) {
                $table->decimal('saldofinal', 11, 2)->default(0)->change();
            });
        }

        if (Schema::hasColumn('estadocaja', 'estado')) {
            Schema::table('estadocaja', function (Blueprint $table) {
                $table->string('estado', 10)->nullable(false)->change();
            });
        }

        if (Schema::hasColumn('estadocaja', 'saldosistema')) {
            Schema::table('estadocaja', function (Blueprint $table) {
                $table->decimal('saldosistema', 11, 2)->default(0)->change();
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('estadocaja')) {
            return;
        }

        if (Schema::hasColumn('estadocaja', 'saldofinal')) {
            Schema::table('estadocaja', function (Blueprint $table) {
                $table->dropColumn('saldofinal');
            });
        }

        if (Schema::hasColumn('estadocaja', 'saldoinicial') && !Schema::hasColumn('estadocaja', 'saldo')) {
            Schema::table('estadocaja', function (Blueprint $table) {
                $table->renameColumn('saldoinicial', 'saldo');
            });
        }

        if (Schema::hasColumn('estadocaja', 'estado')) {
            Schema::table('estadocaja', function (Blueprint $table) {
                $table->integer('estado')->nullable(false)->default(0)->change();
            });
        }
    }
};
