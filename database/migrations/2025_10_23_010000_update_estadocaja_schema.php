<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('estadocaja')) {
            return;
        }

        $driver = DB::getDriverName();
        if (!in_array($driver, ['mysql', 'mariadb'])) {
            return;
        }

        // Rename saldo -> saldoinicial and ensure proper type
        if (Schema::hasColumn('estadocaja', 'saldo') && !Schema::hasColumn('estadocaja', 'saldoinicial')) {
            DB::statement('ALTER TABLE estadocaja CHANGE COLUMN saldo saldoinicial DECIMAL(11,2) NOT NULL');
        } elseif (Schema::hasColumn('estadocaja', 'saldoinicial')) {
            DB::statement('ALTER TABLE estadocaja MODIFY COLUMN saldoinicial DECIMAL(11,2) NOT NULL');
        }

        // Ensure estado is VARCHAR(10) NOT NULL
        if (Schema::hasColumn('estadocaja', 'estado')) {
            DB::statement('ALTER TABLE estadocaja MODIFY COLUMN estado VARCHAR(10) NOT NULL');
        }

        // Add saldofinal if missing
        if (!Schema::hasColumn('estadocaja', 'saldofinal')) {
            Schema::table('estadocaja', function (Blueprint $table) {
                $table->decimal('saldofinal', 11, 2)->default(0)->after('saldoinicial');
            });

            // Initialize saldofinal with saldoinicial for existing rows
            DB::table('estadocaja')->update([
                'saldofinal' => DB::raw('COALESCE(saldofinal, saldoinicial)')
            ]);

            DB::statement('ALTER TABLE estadocaja MODIFY COLUMN saldofinal DECIMAL(11,2) NOT NULL');
        }

        // Ensure saldosistema is numeric(11,2) NOT NULL
        if (Schema::hasColumn('estadocaja', 'saldosistema')) {
            DB::statement('ALTER TABLE estadocaja MODIFY COLUMN saldosistema DECIMAL(11,2) NOT NULL');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('estadocaja')) {
            return;
        }

        $driver = DB::getDriverName();
        if (!in_array($driver, ['mysql', 'mariadb'])) {
            return;
        }

        if (Schema::hasColumn('estadocaja', 'saldofinal')) {
            Schema::table('estadocaja', function (Blueprint $table) {
                $table->dropColumn('saldofinal');
            });
        }

        if (Schema::hasColumn('estadocaja', 'saldoinicial') && !Schema::hasColumn('estadocaja', 'saldo')) {
            DB::statement('ALTER TABLE estadocaja CHANGE COLUMN saldoinicial saldo DECIMAL(11,2) NOT NULL');
        }

        if (Schema::hasColumn('estadocaja', 'estado')) {
            DB::statement('ALTER TABLE estadocaja MODIFY COLUMN estado INT NOT NULL');
        }
    }
};
