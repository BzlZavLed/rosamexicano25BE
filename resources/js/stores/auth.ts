// src/stores/auth.ts
import { defineStore } from 'pinia';
import { login as apiLogin, me as apiMe, logout as apiLogout } from '../api/auth';
import { ADMIN_DEFAULT_MODULES, CASHIER_DEFAULT_MODULES } from '../constants/modules';

type Role = 'admin' | 'cashier' | 'provider';
type AdminUser = { id: number; nombre?: string; email?: string };
type ProviderUser = { id: number; ident: number; nombre: string; tel: string; email?: string | null };
type StaffRoleProfile = {
    id: number;
    name: string;
    slug: string;
    base_role: 'admin' | 'cashier';
    modules: string[];
    is_default: boolean;
};

const storedModules = (() => {
    try {
        const raw = localStorage.getItem('modules');
        return raw ? (JSON.parse(raw) as string[]) : [];
    } catch {
        return [];
    }
})();

export const useAuthStore = defineStore('auth', {
    state: () => ({
        token: localStorage.getItem('token') || '',
        role: (localStorage.getItem('role') as Role | '') || '',
        admin: null as AdminUser | null,
        provider: null as ProviderUser | null,
        staffRole: null as StaffRoleProfile | null,
        loading: false as boolean,
        error: '' as string,
        modules: storedModules as string[],
    }),
    getters: {
        isAuthenticated: (s) => !!s.token && !!s.role,
        isAdmin: (s) => s.role === 'admin',
        isCashier: (s) => s.role === 'cashier',
        isProvider: (s) => s.role === 'provider',
        allowedModules: (s) => {
            if (s.role === 'admin') {
                return (s.modules.length ? s.modules : ADMIN_DEFAULT_MODULES.slice()) as string[];
            }
            if (s.role === 'cashier') {
                return (s.modules.length ? s.modules : CASHIER_DEFAULT_MODULES.slice()) as string[];
            }
            return [] as string[];
        },
        displayName: (s) =>
            s.role === 'admin' || s.role === 'cashier'
                ? (s.admin?.nombre || s.admin?.email || 'Admin')
                : s.role === 'provider'
                    ? s.provider?.nombre
                    : '',
        canAccessModule: (s) => (module: string) => {
            if (s.role === 'admin') {
                return s.modules.length ? s.modules.includes(module) : ADMIN_DEFAULT_MODULES.includes(module as any);
            }
            if (s.role === 'cashier') {
                return s.modules.length ? s.modules.includes(module) : CASHIER_DEFAULT_MODULES.includes(module as any);
            }
            return false;
        },
    },
    actions: {
        setSession(token: string, role: Role, payload: any) {
            this.token = token;
            this.role = role;
            this.admin = role === 'admin' || role === 'cashier' ? payload?.user ?? null : null;
            this.provider = role === 'provider' ? payload?.provider ?? null : null;
            this.staffRole = payload?.user?.staff_role ?? null;
            const modules = Array.isArray(payload?.user?.modules) ? payload.user.modules : [];
            this.modules = role === 'provider' ? [] : modules;
            localStorage.setItem('token', token);
            localStorage.setItem('role', role);
            localStorage.setItem('modules', JSON.stringify(this.modules));
        },
        clearSession() {
            this.token = '';
            this.role = '';
            this.admin = null;
            this.provider = null;
            this.staffRole = null;
            this.modules = [];
            localStorage.removeItem('token');
            localStorage.removeItem('role');
            localStorage.removeItem('modules');
        },
        async login(identifier: string, password: string) {
            this.loading = true;
            this.error = '';
            try {
                const res = await apiLogin(identifier, password);
                if ('provider' in res) {
                    this.setSession(res.token, 'provider', { provider: res.provider });
                } else {
                    this.setSession(res.token, res.role, { user: res.user });
                }
                return true;
            } catch (e: any) {
                this.error = e?.response?.data?.message || 'Login failed';
                this.clearSession();
                return false;
            } finally {
                this.loading = false;
            }
        },
        async hydrateFromToken() {
            if (!this.token) return false;
            try {
                const data = await apiMe();
                if ('provider' in data) {
                    this.role = 'provider';
                    this.provider = data.provider;
                    this.admin = null;
                    this.staffRole = null;
                    this.modules = [];
                } else {
                    this.role = data.role;
                    this.admin = data.user;
                    this.provider = null;
                    this.staffRole = data.user?.staff_role ?? null;
                    this.modules = Array.isArray(data.user?.modules) ? data.user.modules : [];
                }
                localStorage.setItem('role', this.role);
                localStorage.setItem('modules', JSON.stringify(this.modules));
                return true;
            } catch {
                this.clearSession();
                return false;
            }
        },
        async logout(silent = false) {
            try { if (!silent) await apiLogout(); } catch { }
            this.clearSession();
        }
    }
});
