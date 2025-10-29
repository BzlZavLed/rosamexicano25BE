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
            if (!Schema::hasColumn('mensualidad', 'mail_status')) {
                $table->boolean('mail_status')->default(false)->after('status');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('mensualidad')) {
            return;
        }

        Schema::table('mensualidad', function (Blueprint $table) {
            if (Schema::hasColumn('mensualidad', 'mail_status')) {
                $table->dropColumn('mail_status');
            }
        });
    }
};
