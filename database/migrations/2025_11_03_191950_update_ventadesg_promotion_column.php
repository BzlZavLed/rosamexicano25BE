<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ventadesg')) {
            return;
        }

        Schema::table('ventadesg', function (Blueprint $table) {
            if (!Schema::hasColumn('ventadesg', 'descuento_producto')) {
                $table->decimal('descuento_producto', 10, 2)->default(0)->after('total');
            }

            if (!Schema::hasColumn('ventadesg', 'promotion')) {
                if (Schema::hasColumn('ventadesg', 'descuento_producto')) {
                    $table->string('promotion', 50)->default('normal')->after('descuento_producto');
                } else {
                    $table->string('promotion', 50)->default('normal');
                }
            }
        });

        Schema::table('ventadesg', function (Blueprint $table) {
            if (Schema::hasColumn('ventadesg', 'product_desc')) {
                $table->dropColumn('product_desc');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ventadesg')) {
            return;
        }

        Schema::table('ventadesg', function (Blueprint $table) {
            if (Schema::hasColumn('ventadesg', 'descuento_producto')) {
                $table->dropColumn('descuento_producto');
            }
        });

        Schema::table('ventadesg', function (Blueprint $table) {
            if (Schema::hasColumn('ventadesg', 'promotion')) {
                $table->dropColumn('promotion');
            }
        });
    }
};
