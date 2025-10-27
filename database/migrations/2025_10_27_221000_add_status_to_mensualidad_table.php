<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mensualidad')) {
            return;
        }

        Schema::table('mensualidad', function (Blueprint $table) {
            if (!Schema::hasColumn('mensualidad', 'status')) {
                $table->string('status', 20)->default('pending')->after('importe');
            }
            if (!Schema::hasColumn('mensualidad', 'payment_date')) {
                $table->date('payment_date')->nullable()->after('status');
            }
            if (!Schema::hasColumn('mensualidad', 'receipt_path')) {
                $table->string('receipt_path', 255)->nullable()->after('payment_date');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('mensualidad')) {
            return;
        }

        Schema::table('mensualidad', function (Blueprint $table) {
            if (Schema::hasColumn('mensualidad', 'receipt_path')) {
                $table->dropColumn('receipt_path');
            }
            if (Schema::hasColumn('mensualidad', 'payment_date')) {
                $table->dropColumn('payment_date');
            }
            if (Schema::hasColumn('mensualidad', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};

