import http from './http';

export type Cliente = {
    id: number;
    nombre: string;
    email: string | null;
    telefono?: string | null;
    created_at?: string;
    updated_at?: string;
};

type ListParams = {
    search?: string;
    limit?: number;
    page?: number;
    per_page?: number;
};

export async function searchClientes(params?: { search?: string; limit?: number }) {
    const { data } = await http.get('/clientes', { params });
    return data;
}

export async function listClientes(params?: ListParams) {
    const { data } = await http.get('/clientes', { params });
    return data;
}

export async function createCliente(payload: { nombre: string; email?: string | null; telefono?: string | null }) {
    const { data } = await http.post('/clientes', payload);
    return data;
}

export async function updateCliente(id: number, payload: { nombre: string; email?: string | null; telefono?: string | null }) {
    const { data } = await http.put(`/clientes/${id}`, payload);
    return data;
}
