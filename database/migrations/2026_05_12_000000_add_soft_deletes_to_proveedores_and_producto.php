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
        if (!Schema::hasColumn('proveedores', 'deleted_at') || !Schema::hasColumn('proveedores', 'delete_reason')) {
            Schema::table('proveedores', function (Blueprint $table) {
                if (!Schema::hasColumn('proveedores', 'deleted_at')) {
                    $table->softDeletes();
                }

                if (!Schema::hasColumn('proveedores', 'delete_reason')) {
                    $table->text('delete_reason')->nullable();
                }
            });
        }

        if (!Schema::hasColumn('producto', 'deleted_at')) {
            Schema::table('producto', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('producto', 'deleted_at')) {
            Schema::table('producto', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }

        if (Schema::hasColumn('proveedores', 'delete_reason') || Schema::hasColumn('proveedores', 'deleted_at')) {
            Schema::table('proveedores', function (Blueprint $table) {
                if (Schema::hasColumn('proveedores', 'delete_reason')) {
                    $table->dropColumn('delete_reason');
                }

                if (Schema::hasColumn('proveedores', 'deleted_at')) {
                    $table->dropSoftDeletes();
                }
            });
        }
    }
};
