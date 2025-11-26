import http from './http';

export type StaffRole = {
    id: number;
    name: string;
    slug: string;
    base_role: 'admin' | 'cashier';
    modules: string[];
    is_default: boolean;
};

export async function listStaffRoles() {
    const { data } = await http.get<StaffRole[]>('/admin/staff-roles');
    return data;
}

export async function createStaffRole(payload: {
    name: string;
    slug?: string;
    base_role: 'admin' | 'cashier';
    modules: string[];
    is_default?: boolean;
}) {
    const { data } = await http.post<StaffRole>('/admin/staff-roles', payload);
    return data;
}

export async function updateStaffRole(
    id: number,
    payload: Partial<{ name: string; slug?: string; base_role: 'admin' | 'cashier'; modules: string[]; is_default?: boolean }>
) {
    const { data } = await http.put<StaffRole>(`/admin/staff-roles/${id}`, payload);
    return data;
}

export async function deleteStaffRole(id: number) {
    await http.delete(`/admin/staff-roles/${id}`);
}
