<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory_proposals', function (Blueprint $table) {
            $table->id();
            $table->string('horizon', 10)->unique();
            $table->unsignedInteger('lookback_days');
            $table->unsignedInteger('lead_time_days');
            $table->unsignedInteger('minimum_inventory_days');
            $table->json('items');
            $table->timestamp('generated_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_proposals');
    }
};
