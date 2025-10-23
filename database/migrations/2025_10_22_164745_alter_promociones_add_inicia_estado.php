<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('promociones')) {
            return;
        }

        $driver = DB::getDriverName();

        // Normalize legacy columns before adding the new ones.
        if (in_array($driver, ['mysql', 'mariadb'])) {
            if (Schema::hasColumn('promociones', 'productoNombre')) {
                DB::statement('ALTER TABLE promociones DROP COLUMN productoNombre');
            }
            if (Schema::hasColumn('promociones', 'proveedorNombre')) {
                DB::statement('ALTER TABLE promociones DROP COLUMN proveedorNombre');
            }
            if (Schema::hasColumn('promociones', 'proveedorid') && !Schema::hasColumn('promociones', 'proveedor')) {
                DB::statement('ALTER TABLE promociones CHANGE COLUMN proveedorid proveedor INT NULL');
            } else {
                DB::statement('ALTER TABLE promociones MODIFY COLUMN proveedor INT NULL');
            }
            if (Schema::hasColumn('promociones', 'producto')) {
                DB::statement('ALTER TABLE promociones MODIFY COLUMN producto INT NULL');
            }
            if (Schema::hasColumn('promociones', 'tipoPromocion')) {
                DB::statement('ALTER TABLE promociones CHANGE COLUMN tipoPromocion tipo VARCHAR(25) NOT NULL');
            }
            if (Schema::hasColumn('promociones', 'descuento')) {
                DB::statement('ALTER TABLE promociones MODIFY COLUMN descuento DECIMAL(5,2) NULL');
            }
            if (Schema::hasColumn('promociones', 'minimoCompra')) {
                DB::statement('ALTER TABLE promociones CHANGE COLUMN minimoCompra mincompra INT NULL');
            }
            if (Schema::hasColumn('promociones', 'cantidadGratis')) {
                DB::statement('ALTER TABLE promociones CHANGE COLUMN cantidadGratis gratis INT NULL');
            }

            if (Schema::hasColumn('promociones', 'fechaVencimiento')) {
                DB::statement('ALTER TABLE promociones ADD COLUMN vence_temp DATE NULL');
                DB::statement("
                    UPDATE promociones
                    SET vence_temp = NULLIF(STR_TO_DATE(fechaVencimiento, '%Y-%m-%d'), 0)
                ");
                DB::statement('ALTER TABLE promociones DROP COLUMN fechaVencimiento');
                DB::statement('ALTER TABLE promociones CHANGE COLUMN vence_temp vence DATE NULL');
            } elseif (!Schema::hasColumn('promociones', 'vence')) {
                DB::statement('ALTER TABLE promociones ADD COLUMN vence DATE NULL');
            }

        } else {
            // Fallback: ensure essential columns exist with reasonable defaults.
            Schema::table('promociones', function (Blueprint $table) {
                if (!Schema::hasColumn('promociones', 'tipo')) {
                    $table->string('tipo', 25)->nullable();
                }
                if (!Schema::hasColumn('promociones', 'descuento')) {
                    $table->decimal('descuento', 5, 2)->nullable();
                }
                if (!Schema::hasColumn('promociones', 'mincompra')) {
                    $table->integer('mincompra')->nullable();
                }
                if (!Schema::hasColumn('promociones', 'gratis')) {
                    $table->integer('gratis')->nullable();
                }
                if (!Schema::hasColumn('promociones', 'vence')) {
                    $table->date('vence')->nullable();
                }
            });
        }

        $outDriver = DB::getDriverName();
        if (in_array($outDriver, ['mysql', 'mariadb'])) {
            Schema::table('promociones', function (Blueprint $table) {
                if (!Schema::hasColumn('promociones', 'inicia')) {
                    $table->date('inicia')->nullable()->after('tipo');
                }
                if (!Schema::hasColumn('promociones', 'estado')) {
                    $table->boolean('estado')->default(true)->after('vence');
                }
            });
        } else {
            Schema::table('promociones', function (Blueprint $table) {
                if (!Schema::hasColumn('promociones', 'inicia')) {
                    $table->date('inicia')->nullable();
                }
                if (!Schema::hasColumn('promociones', 'estado')) {
                    $table->boolean('estado')->default(true);
                }
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('promociones')) {
            return;
        }

        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'])) {
            if (Schema::hasColumn('promociones', 'estado')) {
                Schema::table('promociones', function (Blueprint $table) {
                    $table->dropColumn('estado');
                });
            }
            if (Schema::hasColumn('promociones', 'inicia')) {
                Schema::table('promociones', function (Blueprint $table) {
                    $table->dropColumn('inicia');
                });
            }

            if (!Schema::hasColumn('promociones', 'fechaVencimiento')) {
                DB::statement('ALTER TABLE promociones ADD COLUMN fechaVencimiento VARCHAR(45) NULL');
                DB::statement("UPDATE promociones SET fechaVencimiento = DATE_FORMAT(vence, '%Y-%m-%d') WHERE vence IS NOT NULL");
            }

            if (Schema::hasColumn('promociones', 'vence')) {
                DB::statement('ALTER TABLE promociones DROP COLUMN vence');
            }

            DB::statement('ALTER TABLE promociones CHANGE COLUMN tipo tipoPromocion VARCHAR(25) NULL');
            DB::statement('ALTER TABLE promociones CHANGE COLUMN mincompra minimoCompra VARCHAR(2) NULL');
            DB::statement('ALTER TABLE promociones CHANGE COLUMN gratis cantidadGratis VARCHAR(2) NULL');
            DB::statement('ALTER TABLE promociones MODIFY COLUMN descuento VARCHAR(3) NULL');
            DB::statement('ALTER TABLE promociones CHANGE COLUMN proveedor proveedorid VARCHAR(45) NULL');
            DB::statement('ALTER TABLE promociones MODIFY COLUMN producto VARCHAR(45) NULL');
            DB::statement('ALTER TABLE promociones ADD COLUMN productoNombre VARCHAR(45) NULL AFTER producto');
            DB::statement('ALTER TABLE promociones ADD COLUMN proveedorNombre VARCHAR(45) NULL AFTER proveedorid');
        } else {
            Schema::table('promociones', function (Blueprint $table) {
                if (Schema::hasColumn('promociones', 'estado')) {
                    $table->dropColumn('estado');
                }
                if (Schema::hasColumn('promociones', 'inicia')) {
                    $table->dropColumn('inicia');
                }
            });
        }
    }
};
