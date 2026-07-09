<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_logs') || ! Schema::hasColumn('audit_logs', 'record_id')) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('record_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('audit_logs') || ! Schema::hasColumn('audit_logs', 'record_id')) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedInteger('record_id')->nullable()->change();
        });
    }
};
