// src/api/auth.ts
import http from './http';

export type LoginResponse =
    | { token: string; role: 'admin'; user: { id: number; nombre?: string; email: string } }
    | { token: string; role: 'provider'; provider: { id: number; nombre: string; tel: string } };

export async function login(identifier: string, password: string) {
    const { data } = await http.post<LoginResponse>('/auth/login', { identifier, password });
    return data;
}

export async function me() {
    const { data } = await http.get('/auth/me');
    return data as
        | { role: 'admin'; user: { id: number; nombre?: string; email?: string } }
        | { role: 'provider'; provider: { id: number; nombre: string; tel: string } };
}

export async function logout() {
    await http.post('/auth/logout');
}
