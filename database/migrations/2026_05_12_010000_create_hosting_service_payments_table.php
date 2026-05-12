<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hosting_service_payments', function (Blueprint $table) {
            $table->id();
            $table->string('implementation_key', 64);
            $table->string('implementation_name', 120);
            $table->date('service_month');
            $table->date('due_date');
            $table->decimal('amount', 10, 2)->default(200);
            $table->boolean('paid')->default(false);
            $table->date('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['implementation_key', 'service_month'], 'hosting_payments_implementation_month_unique');
            $table->index(['due_date', 'paid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hosting_service_payments');
    }
};
