<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('system_settings')) {
            Schema::create('system_settings', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'restock_cron_horizon'],
            ['value' => 'day,week,month', 'created_at' => now(), 'updated_at' => now()]
        );

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'card_charge_percent'],
            ['value' => '4.5', 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
