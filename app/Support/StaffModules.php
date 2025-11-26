<?php

namespace App\Support;

class StaffModules
{
    public const MODULES = [
        'dashboard',
        'caja',
        'inventario',
        'productos',
        'promociones',
        'proveedores',
        'cobros',
        'clientes',
        'emails',
        'reportes',
        'analisis',
        'configuracion',
        'usuarios',
        'roles',
        'cancelaciones',
    ];

    public static function list(): array
    {
        return self::MODULES;
    }
}
