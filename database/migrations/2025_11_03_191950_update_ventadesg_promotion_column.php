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
            if (!Schema::hasColumn('ventadesg', 'promotion')) {
                $table->string('promotion', 50)->default('normal')->after('descuento_producto');
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
            if (!Schema::hasColumn('ventadesg', 'product_desc')) {
                $table->decimal('product_desc', 10, 2)->default(0)->after('descuento_producto');
            }
        });

        Schema::table('ventadesg', function (Blueprint $table) {
            if (Schema::hasColumn('ventadesg', 'promotion')) {
                $table->dropColumn('promotion');
            }
        });
    }
};
