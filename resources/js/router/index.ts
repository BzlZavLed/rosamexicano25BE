// src/router/index.ts
import { createRouter, createWebHistory } from "vue-router";
import type { RouteRecordRaw } from "vue-router";
import { useAuthStore } from "../stores/auth";
import { resolveStaffHome } from "../utils/staffRoutes";

const LoginView = () => import("../views/LoginView.vue");
const AdminDashboard = () => import("../views/AdminDashboard.vue");
const ProviderDashboard = () => import("../views/ProvidersDashboard.vue");
const ProviderCatalogView = () => import("../views/ProviderCatalogView.vue");
const ProviderReportsView = () => import("../views/ProviderReportsView.vue");
const ProviderProfileView = () => import("../views/ProviderProfileView.vue");
const AdminProductosForm = () => import("../views/AdminProductosForm.vue");
const AdminProveedoresForm = () => import("../views/AdminProveedoresForm.vue");
const AdminInventarioEntrada = () => import("../views/AdminInventarioEntrada.vue");
const AdminInventarioReportView = () => import("../views/AdminInventarioReportView.vue");
const AdminPromociones = () => import("../views/AdminPromociones.vue");
const AdminCajaView = () => import("../views/AdminCajaView.vue");
const AdminEmailHistory = () => import("../views/AdminEmailHistory.vue");
const AdminClientes = () => import("../views/AdminClientes.vue");
const AdminCobros = () => import("../views/AdminCobros.vue");
const AdminReportsView = () => import("../views/AdminReportsView.vue");
const AdminAnalysisView = () => import("../views/AdminAnalysisView.vue");
const AdminUsuariosView = () => import("../views/AdminUsuariosView.vue");
const AdminRolesView = () => import("../views/AdminRolesView.vue");

const routes: RouteRecordRaw[] = [
    { path: "/", redirect: "/auth/login" },
    {
        path: "/auth/login",
        name: "login",
        component: LoginView,
        meta: { public: true },
    },

    // Admin-only area
    {
        path: "/dashboard",
        name: "admin-dashboard",
        component: AdminDashboard,
        alias: "/login",
        meta: { requiresAuth: true, roles: ["admin","cashier"], module: "dashboard" },
    },

    // Provider-only area
    {
        path: "/provider",
        name: "provider-dashboard",
        component: ProviderDashboard,
        meta: { requiresAuth: true, role: "provider" },
    },
    {
        path: "/provider/catalogo",
        name: "provider-catalog",
        component: ProviderCatalogView,
        meta: { requiresAuth: true, role: "provider" },
    },
    {
        path: "/provider/reportes",
        name: "provider-reports",
        component: ProviderReportsView,
        meta: { requiresAuth: true, role: "provider" },
    },
    {
        path: "/provider/perfil",
        name: "provider-profile",
        component: ProviderProfileView,
        meta: { requiresAuth: true, role: "provider" },
    },

    // 404
    { path: "/:pathMatch(.*)*", redirect: "/login" },

    //Productos crear
    {
        path: "/admin/productos/crear",
        name: "admin-productos-form",
        component: AdminProductosForm,
        meta: { requiresAuth: true, roles: ["admin"], module: "productos" },
    },
    //Proveedores crear
    {
        path: '/admin/proveedores',
        name: 'admin-proveedores-form',
        component: AdminProveedoresForm,
        meta: { requiresAuth: true, roles: ['admin','cashier'], module: 'proveedores' }
    },
    //Inventory crear
    {
        path: '/admin/inventario/entrada',
        name: 'admin-inventario-entrada',
        component: AdminInventarioEntrada,
        meta: { requiresAuth: true, roles: ['admin','cashier'], module: 'inventario' }
    },
    {
        path: '/admin/inventario/reporte',
        name: 'admin-inventario-reporte',
        component: AdminInventarioReportView,
        meta: { requiresAuth: true, roles: ['admin','cashier'], module: 'inventario' }
    },
    //Promociones crear
    {
        path: '/admin/promociones',
        name: 'admin-promociones',
        component: AdminPromociones,
        meta: { requiresAuth: true, roles: ['admin','cashier'], module: 'promociones' }
    },
    //Caja view
    {
        path: '/admin/caja',
        name: 'admin-caja',
        component: AdminCajaView,
        meta: { requiresAuth: true, roles: ['admin','cashier'], module: 'caja' }
    },
    //historial de emails
    {
        path: '/admin/emails',
        name: 'admin-emails-history',
        component: AdminEmailHistory,
        meta: { requiresAuth: true, roles: ['admin','cashier'], module: 'emails' }
    },
    //clientes view
    {
        path: '/admin/clientes',
        name: 'admin-clientes',
        component: AdminClientes,
        meta: { requiresAuth: true, roles: ['admin','cashier'], module: 'clientes' }
    },
    //cobros a marcas
    {
        path: '/admin/cobros',
        name: 'admin-cobros',
        component: AdminCobros,
        meta: { requiresAuth: true, roles: ['admin','cashier'], module: 'cobros' }
    },
    {
        path: '/admin/reportes',
        name: 'admin-reports',
        component: AdminReportsView,
        meta: { requiresAuth: true, roles: ['admin','cashier'], module: 'reportes' }
    },
    {
        path: '/admin/analisis',
        name: 'admin-analysis',
        component: AdminAnalysisView,
        meta: { requiresAuth: true, roles: ['admin','cashier'], module: 'analisis' }
    },
    {
        path: '/admin/usuarios',
        name: 'admin-usuarios',
        component: AdminUsuariosView,
        meta: { requiresAuth: true, roles: ['admin','cashier'], module: 'usuarios' }
    },
    {
        path: '/admin/roles',
        name: 'admin-roles',
        component: AdminRolesView,
        meta: { requiresAuth: true, roles: ['admin','cashier'], module: 'roles' }
    }
];

const router = createRouter({history: createWebHistory('/'),routes,});

router.beforeEach(async (to) => {
    const auth = useAuthStore();

    if (!auth.isAuthenticated && !to.meta.public) {
        const ok = await auth.hydrateFromToken();
        if (!ok) return { name: "login" };
    }

    const staffRole: 'admin' | 'cashier' | '' = auth.isAdmin ? 'admin' : auth.isCashier ? 'cashier' : '';

    if (to.meta.requiresAuth) {
        const roleMeta = (Array.isArray(to.meta.roles) ? to.meta.roles : undefined) ??
            (to.meta.role ? [to.meta.role] : undefined);
        if (roleMeta && roleMeta.length && !roleMeta.includes(auth.role)) {
            const fallback = auth.isProvider ? { name: "provider-dashboard" } : resolveStaffHome(staffRole, auth.modules);
            return fallback ?? { name: "login" };
        }
        const requiredModule = to.meta.module as string | undefined;
        if (requiredModule && !(auth.isAdmin || (auth.isCashier && auth.canAccessModule(requiredModule)))) {
            const fallback = resolveStaffHome(staffRole, auth.modules);
            if (fallback && to.name !== fallback.name) {
                return fallback;
            }
            return auth.isProvider ? { name: "provider-dashboard" } : { name: "login" };
        }
    }
});

export default router;
