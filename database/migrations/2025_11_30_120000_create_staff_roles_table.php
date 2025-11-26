<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 80)->unique();
            $table->enum('base_role', ['admin', 'cashier'])->default('admin');
            $table->json('modules')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::table('usuarios', function (Blueprint $table) {
            if (!Schema::hasColumn('usuarios', 'staff_role_id')) {
                $table->foreignId('staff_role_id')->nullable()->after('role')->constrained('staff_roles')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            if (Schema::hasColumn('usuarios', 'staff_role_id')) {
                $table->dropConstrainedForeignId('staff_role_id');
            }
        });

        Schema::dropIfExists('staff_roles');
    }
};
