<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ventas')) {
            Schema::table('ventas', function (Blueprint $table) {
                if (!Schema::hasColumn('ventas', 'subtotal')) {
                    $table->decimal('subtotal', 10, 2)->default(0)->after('concepto');
                }
                if (!Schema::hasColumn('ventas', 'descuento_general')) {
                    $table->decimal('descuento_general', 10, 2)->default(0)->after('subtotal');
                }
                if (!Schema::hasColumn('ventas', 'tarjeta_cargo')) {
                    $table->decimal('tarjeta_cargo', 10, 2)->default(0)->after('descuento_general');
                }
            });
        }

        if (Schema::hasTable('ventadesg')) {
            Schema::table('ventadesg', function (Blueprint $table) {
                if (!Schema::hasColumn('ventadesg', 'product_desc')) {
                    $table->decimal('product_desc', 10, 2)->default(0)->after('total');
                }
            });

            if (Schema::hasColumn('ventadesg', 'totdesc')) {
                DB::table('ventadesg')->update(['product_desc' => DB::raw('totdesc')]);
                Schema::table('ventadesg', function (Blueprint $table) {
                    $table->dropColumn('totdesc');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ventadesg')) {
            Schema::table('ventadesg', function (Blueprint $table) {
                if (!Schema::hasColumn('ventadesg', 'totdesc')) {
                    $table->decimal('totdesc', 10, 2)->default(0)->after('total');
                }
            });

            if (Schema::hasColumn('ventadesg', 'product_desc')) {
                DB::table('ventadesg')->update(['totdesc' => DB::raw('product_desc')]);
                Schema::table('ventadesg', function (Blueprint $table) {
                    $table->dropColumn('product_desc');
                });
            }
        }

        if (Schema::hasTable('ventas')) {
            Schema::table('ventas', function (Blueprint $table) {
                if (Schema::hasColumn('ventas', 'tarjeta_cargo')) {
                    $table->dropColumn('tarjeta_cargo');
                }
                if (Schema::hasColumn('ventas', 'descuento_general')) {
                    $table->dropColumn('descuento_general');
                }
                if (Schema::hasColumn('ventas', 'subtotal')) {
                    $table->dropColumn('subtotal');
                }
            });
        }
    }
};
