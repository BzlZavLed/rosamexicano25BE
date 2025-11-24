<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommended_importes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('proveedor_id')->nullable();
            $table->string('provider_ident');
            $table->string('provider_name')->nullable();
            $table->string('provider_email')->nullable();
            $table->decimal('current_importe', 12, 2)->default(0);
            $table->decimal('avg_monthly_sales', 12, 2)->default(0);
            $table->decimal('recommended_importe', 12, 2)->default(0);
            $table->decimal('total_sales', 12, 2)->default(0);
            $table->unsignedInteger('months')->default(0);
            $table->boolean('is_recommended')->default(false);
            $table->decimal('percentage_used', 5, 2)->default(0);
            $table->unsignedInteger('months_window')->default(0);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestamps();

            $table->unique('provider_ident');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommended_importes');
    }
};
