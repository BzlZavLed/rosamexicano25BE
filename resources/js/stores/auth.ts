// src/stores/auth.ts
import { defineStore } from 'pinia';
import { login as apiLogin, me as apiMe, logout as apiLogout } from '../api/auth';

type Role = 'admin' | 'provider';
type AdminUser = { id: number; nombre?: string; email?: string };
type ProviderUser = { id: number; nombre: string; tel: string };

export const useAuthStore = defineStore('auth', {
    state: () => ({
        token: localStorage.getItem('token') || '',
        role: (localStorage.getItem('role') as Role | '') || '',
        admin: null as AdminUser | null,
        provider: null as ProviderUser | null,
        loading: false as boolean,
        error: '' as string,
    }),
    getters: {
        isAuthenticated: (s) => !!s.token && !!s.role,
        isAdmin: (s) => s.role === 'admin',
        isProvider: (s) => s.role === 'provider',
        displayName: (s) =>
            s.role === 'admin' ? (s.admin?.nombre || s.admin?.email || 'Admin') :
                s.role === 'provider' ? s.provider?.nombre : '',
    },
    actions: {
        setSession(token: string, role: Role, payload: any) {
            this.token = token;
            this.role = role;
            this.admin = role === 'admin' ? payload?.user ?? null : null;
            this.provider = role === 'provider' ? payload?.provider ?? null : null;
            localStorage.setItem('token', token);
            localStorage.setItem('role', role);
        },
        clearSession() {
            this.token = '';
            this.role = '';
            this.admin = null;
            this.provider = null;
            localStorage.removeItem('token');
            localStorage.removeItem('role');
        },
        async login(identifier: string, password: string) {
            this.loading = true; this.error = '';
            try {
                const res = await apiLogin(identifier, password);
                if (res.role === 'admin') this.setSession(res.token, 'admin', { user: res.user });
                else this.setSession(res.token, 'provider', { provider: res.provider });
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
                if (data.role === 'admin') {
                    this.role = 'admin'; this.admin = data.user; this.provider = null;
                } else {
                    this.role = 'provider'; this.provider = data.provider; this.admin = null;
                }
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
