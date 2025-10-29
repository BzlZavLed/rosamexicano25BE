<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('mailer_track')->updateOrInsert(
            ['year' => 2025, 'month' => 10],
            ['sent_count' => 127, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function down(): void
    {
        DB::table('mailer_track')
            ->where('year', 2025)
            ->where('month', 10)
            ->delete();
    }
};
