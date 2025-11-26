import { ADMIN_DEFAULT_MODULES, CASHIER_DEFAULT_MODULES } from '../constants/modules';

export type StaffRole = 'admin' | 'cashier' | '';

const moduleRouteMap: Record<string, string> = {
    dashboard: 'admin-dashboard',
    caja: 'admin-caja',
    inventario: 'admin-inventario-entrada',
    productos: 'admin-productos-form',
    proveedores: 'admin-proveedores-form',
    cobros: 'admin-cobros',
    emails: 'admin-emails-history',
    clientes: 'admin-clientes',
    reportes: 'admin-reports',
    analisis: 'admin-analysis',
    usuarios: 'admin-usuarios',
    promociones: 'admin-promociones',
    roles: 'admin-roles',
};

export function resolveModuleRoute(module: string): { name: string } | null {
    const routeName = moduleRouteMap[module];
    return routeName ? { name: routeName } : null;
}

export function resolveStaffHome(role: StaffRole, modules: string[] = []): { name: string } | null {
    if (role === 'admin') {
        const candidates = modules.length ? modules : Array.from(ADMIN_DEFAULT_MODULES);
        for (const module of candidates) {
            const route = resolveModuleRoute(module);
            if (route) return route;
        }
        return null;
    }
    if (role === 'cashier') {
        const candidates = modules.length ? modules : Array.from(CASHIER_DEFAULT_MODULES);
        for (const module of candidates) {
            const route = resolveModuleRoute(module);
            if (route) return route;
        }
        return null;
    }
    return null;
}
