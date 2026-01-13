import http from '../api/http';

export type InventarioUpsertPayload =
  | { ident: number | string; existencia: number }        // by barcode (preferred in your flow)
  | { product_id: number; existencia: number };           // or by product id

export async function setStockAbsolute(payload: InventarioUpsertPayload) {
    const { data } = await http.post('/inventario/set-stock', payload);
    return data; 
}

export type InventarioProductoInfo = {
    id?: number;
    ident: number | string;
    nombre?: string | null;
    descripcion?: string | null;
    precio?: number | null;
};

export type InventarioProveedorInfo = {
    id?: number;
    nombre?: string | null;
};

export type InventarioListItem = {
    id: number;
    ident: number | string;
    existencia: number;
    importe: number;
    producto?: InventarioProductoInfo | null;
    proveedor?: InventarioProveedorInfo | null;
};

export type InventarioListMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

export type InventarioListResponse = {
    data: InventarioListItem[];
    meta?: InventarioListMeta;
};

export async function getProveedorInventario(params: {
    proveedorId: number;
    page?: number;
    per_page?: number;
    sort?: 'nombre' | 'proveedor' | 'existencia' | 'puni';
    direction?: 'asc' | 'desc';
    search?: string;
}) {
    const { proveedorId, ...rest } = params;
    const { data } = await http.get<InventarioListResponse>(`/proveedores/${proveedorId}/inventario`, {
        params: rest,
    });
    return data;
}
