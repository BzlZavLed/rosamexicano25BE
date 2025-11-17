<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('daily_cash_summaries', function (Blueprint $table) {
            $table->id();
            $table->date('fecha')->unique();
            $table->decimal('saldo_inicial', 14, 2)->default(0);
            $table->decimal('efectivo', 14, 2)->default(0);
            $table->decimal('transferencia', 14, 2)->default(0);
            $table->decimal('tarjeta', 14, 2)->default(0);
            $table->decimal('egresos', 14, 2)->default(0);
            $table->decimal('saldo_cierre', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::table('estadocaja', function (Blueprint $table) {
            if (!Schema::hasColumn('estadocaja', 'saldo_cierre')) {
                $table->decimal('saldo_cierre', 14, 2)->nullable()->after('saldosistema');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_cash_summaries');

        Schema::table('estadocaja', function (Blueprint $table) {
            if (Schema::hasColumn('estadocaja', 'saldo_cierre')) {
                $table->dropColumn('saldo_cierre');
            }
        });
    }
};
