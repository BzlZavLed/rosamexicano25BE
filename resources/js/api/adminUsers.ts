import http from './http';

export type StaffRoleSummary = {
    id: number;
    name: string;
    slug: string;
    base_role: 'admin' | 'cashier';
    modules: string[];
    is_default: boolean;
};

export type AdminUser = {
    id: number;
    nombre: string;
    email: string;
    role: 'admin' | 'cashier';
    modules: string[] | null;
    staff_role_id: number | null;
    staff_role?: StaffRoleSummary | null;
};

export type AdminUsersResponse = {
    data: AdminUser[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
};

export async function listAdminUsers(params: { search?: string; page?: number; per_page?: number } = {}) {
    const { data } = await http.get<AdminUsersResponse>('/admin/users', { params });
    return data;
}

export async function createAdminUser(payload: {
    nombre: string;
    email: string;
    password: string;
    role: 'admin' | 'cashier';
    staff_role_id?: number | null;
    modules?: string[];
}) {
    const { data } = await http.post<AdminUser>('/admin/users', payload);
    return data;
}

export async function updateAdminUser(
    id: number,
    payload: Partial<{
        nombre: string;
        email: string;
        password: string;
        role: 'admin' | 'cashier';
        staff_role_id?: number | null;
        modules?: string[];
    }>
) {
    const { data } = await http.patch<AdminUser>(`/admin/users/${id}`, payload);
    return data;
}

export async function deleteAdminUser(id: number) {
    await http.delete(`/admin/users/${id}`);
}
