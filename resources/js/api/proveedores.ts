import http from '../api/http';

export type Proveedor = {
    id: number;
    ident: number;
    nombre: string;
    tel?: string;
    email?: string;
    fecha?: string;     // 'YYYY-MM-DD'
    ciudad?: string;
    bancaria?: string;  // cuenta
    sucursal?: string;  // banco (opcional si tu schema lo usa así)
    importe?: number;   // cobro mensual
};

export async function listProveedores(params?: { search?: string; page?: number; per_page?: number }) {
    const out: any = { ...params };
    if ((params as any)?.q && !params?.search) out.search = (params as any).q;
    delete out.q;

    const { data } = await http.get('/proveedores', { params: out });
    return data;
}

export async function getProveedor(id: number) {
    const { data } = await http.get(`/proveedores/${id}`);
    return data as Proveedor;
}

export async function createProveedor(p: Partial<Proveedor>) {
    const { data } = await http.post('/proveedores', p);
    return data as Proveedor;
}

export async function updateProveedor(id: number, p: Partial<Proveedor>) {
    const { data } = await http.put(`/proveedores/${id}`, p);
    return data as Proveedor;
}

export async function deleteProveedor(id: number) {
    await http.delete(`/proveedores/${id}`);
}

export async function listProveedoresAll() {
    // grab “many” so the select has all options; tweak per your data size
    const { data } = await http.get('/proveedores', { params: { per_page: 1000 } });
    // Laravel Resource: { data:[...], links, meta } OR plain array
    return Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
}
