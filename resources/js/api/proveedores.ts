import http from '../api/http';

export type ProveedorRecommendation = {
    recommended_importe: number;
    avg_monthly_sales: number;
    total_sales: number;
    months: number;
    percentage_used: number;
    months_window: number;
    period_start?: string | null;
    period_end?: string | null;
    updated_at?: string | null;
};

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
    tipo: 'normal' | 'consigna' | 'porcentaje';
    porcentaje_comision?: number | null;
    recommendation?: ProveedorRecommendation | null;
    deleted_at?: string | null;
    delete_reason?: string | null;
};

export type ProveedorDeletionReceiptProduct = {
    id: number;
    ident?: number | null;
    nombre?: string | null;
    descripcion?: string | null;
    cantidad?: number | string | null;
    existencia?: number | string | null;
    precio?: number | string | null;
    precio_proveedor?: number | string | null;
};

export type ProveedorDeletionReceipt = {
    proveedor: Pick<Proveedor, 'id' | 'ident' | 'nombre' | 'tel' | 'email' | 'ciudad' | 'sucursal' | 'deleted_at' | 'delete_reason'>;
    deleted_at?: string | null;
    delete_reason: string;
    products_count: number;
    products_quantity?: number | string | null;
    products: ProveedorDeletionReceiptProduct[];
};

export async function listProveedores(params?: { search?: string; page?: number; per_page?: number; status?: 'active' | 'deleted' | 'all' }) {
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

export async function updateProviderProfile(payload: { email?: string | null; tel?: string | null }) {
    const { data } = await http.put('/provider/profile', payload);
    return data as Proveedor;
}

export async function deleteProveedor(id: number, deleteReason: string) {
    const { data } = await http.delete(`/proveedores/${id}`, {
        data: { delete_reason: deleteReason },
    });
    return data as { message: string; receipt: ProveedorDeletionReceipt };
}

export async function listProveedoresAll() {
    // grab “many” so the select has all options; tweak per your data size
    const { data } = await http.get('/proveedores', { params: { per_page: 1000 } });
    // Laravel Resource: { data:[...], links, meta } OR plain array
    return Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
}

export type ProveedorImportSummary = {
    created: number;
    updated: number;
    skipped: number;
    errors: Array<{ line: number; message: string }>;
};

export async function importProveedoresCsv(payload: FormData) {
    const { data } = await http.post<ProveedorImportSummary>('/proveedores/import', payload, {
        headers: { 'Content-Type': 'multipart/form-data' },
    });
    return data;
}

export type BulkTipoUpdatePayload = {
    items: Array<{
        id: number;
        tipo: 'normal' | 'consigna' | 'porcentaje';
        importe?: number | null;
        porcentaje?: number | null;
    }>;
};

export async function bulkUpdateProveedorTipo(payload: BulkTipoUpdatePayload) {
    const { data } = await http.post('/proveedores/bulk-tipo', payload);
    return data as {
        updated: number;
        items: Proveedor[];
    };
}
