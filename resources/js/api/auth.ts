// src/api/auth.ts
import http from './http';

type StaffRoleResponse = {
    id: number;
    name: string;
    slug: string;
    base_role: 'admin' | 'cashier';
    modules: string[];
    is_default: boolean;
};

export type LoginResponse =
    | { token: string; role: 'admin' | 'cashier'; user: { id: number; nombre?: string; email: string; modules?: string[] | null; role?: 'admin' | 'cashier'; staff_role?: StaffRoleResponse | null } }
    | { token: string; role: 'provider'; provider: { id: number; ident: number; nombre: string; tel: string; email?: string | null } };

export async function login(identifier: string, password: string) {
    const { data } = await http.post<LoginResponse>('/auth/login', { identifier, password });
    return data;
}

export async function biometricLogin(identifier: string, secret: string) {
    const { data } = await http.post<LoginResponse>('/auth/biometric/login', { identifier, secret });
    return data;
}

export type BiometricRegisterResponse = { credential_id: string; token: string; identifier: string };

export async function registerBiometricCredential(identifier: string) {
    const { data } = await http.post<BiometricRegisterResponse>('/auth/biometric/register', { identifier });
    return data;
}

export async function passkeyRegisterOptions() {
    const { data } = await http.post('/auth/passkey/options', {}, { withCredentials: true });
    return data as any;
}

export async function passkeyRegister(payload: any) {
    const { data } = await http.post('/auth/passkey/register', payload, { withCredentials: true });
    return data;
}

export async function passkeyLoginOptions(identifier: string) {
    const { data } = await http.post('/auth/passkey/login/options', { identifier }, { withCredentials: true });
    return data as any;
}

export async function passkeyLogin(payload: any) {
    const { data } = await http.post<LoginResponse>('/auth/passkey/login', payload, { withCredentials: true });
    return data;
}

export async function me() {
    const { data } = await http.get('/auth/me');
    return data as
        | { role: 'admin' | 'cashier'; user: { id: number; nombre?: string; email?: string; modules?: string[] | null; role?: 'admin' | 'cashier'; staff_role?: StaffRoleResponse | null } }
        | { role: 'provider'; provider: { id: number; ident: number; nombre: string; tel: string; email?: string | null } };
}

export async function logout() {
    await http.post('/auth/logout');
}
