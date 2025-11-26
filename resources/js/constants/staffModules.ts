export const STAFF_MODULES = [
    { value: 'dashboard', label: 'Dashboard' },
    { value: 'caja', label: 'Caja / POS' },
    { value: 'inventario', label: 'Inventario' },
    { value: 'productos', label: 'Productos' },
    { value: 'promociones', label: 'Promociones' },
    { value: 'proveedores', label: 'Proveedores' },
    { value: 'cobros', label: 'Cobros' },
    { value: 'clientes', label: 'Clientes' },
    { value: 'emails', label: 'Emails' },
    { value: 'reportes', label: 'Reportes' },
    { value: 'analisis', label: 'Análisis' },
    { value: 'configuracion', label: 'Configuración' },
    { value: 'usuarios', label: 'Usuarios' },
    { value: 'roles', label: 'Perfiles de acceso' },
    { value: 'cancelaciones', label: 'Cancelar ventas' },
] as const;

export type StaffModuleValue = (typeof STAFF_MODULES)[number]['value'];
