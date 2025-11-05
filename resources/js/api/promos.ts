// src/api/promos.ts
import http from './http'; // your axios instance

export type PromoTipo = 'descuento' | 'bundle';

export type Promocion = {
    id: number;
    producto: number | null;      // producto.ident
    proveedor: number | null;     // proveedores.ident
    tipo: PromoTipo;
    descuento: number | null;     // %
    mincompra: number | null;     // for 'gratis'
    gratis: number | null;        // for 'gratis'
    inicia: string | null;        // YYYY-MM-DD
    vence: string | null;         // YYYY-MM-DD
    estado: boolean;
    activa?: boolean;

    // optional joined fields from resource
    producto_nombre?: string | null;
    proveedor_nombre?: string | null;
};

export async function listPromos(params?: {
    search?: string;
    producto?: number;    // ident
    proveedor?: number;   // ident
    activa?: 0 | 1;
    estado?: 0 | 1;
    page?: number;
    per_page?: number;
}) {
    const { data } = await http.get('/promociones', { params });
    return data;
}

export async function getPromo(id: number) {
    const { data } = await http.get(`/promociones/${id}`);
    return data as { data: Promocion } | Promocion;
}

export async function createPromo(payload: Partial<Promocion>) {
    const { data } = await http.post('/promociones', payload);
    return (data as { data: Promocion }).data ?? (data as Promocion);
}

export async function updatePromo(id: number, payload: Partial<Promocion>) {
    const { data } = await http.put(`/promociones/${id}`, payload);
    return (data as { data: Promocion }).data ?? (data as Promocion);
}

export async function deletePromo(id: number) {
    const { data } = await http.delete(`/promociones/${id}`);
    return data;
}
