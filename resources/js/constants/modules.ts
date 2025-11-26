export const ADMIN_DEFAULT_MODULES = [
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
    'roles',
    'usuarios',
    'cancelaciones',
] as const;

export const CASHIER_DEFAULT_MODULES = ['caja'] as const;

export type StaffModule = (typeof ADMIN_DEFAULT_MODULES)[number] | (typeof CASHIER_DEFAULT_MODULES)[number];
