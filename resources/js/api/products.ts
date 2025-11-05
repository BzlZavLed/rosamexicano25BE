import http from '../api/http';
import type { AxiosResponse } from 'axios';

export type Producto = {
    id: number;
    ident: number;               // 6-digit internal code (barcode)
    nombre: string;
    descripcion?: string;
    precio: number;
    proveedor?: Proveedor;
    proveedorid: number;
    fecha?: string;              // 'YYYY-MM-DD'
    existencia?: number | null;
};

export type Proveedor = { ident: number; nombre: string };

export type ListProductosParams = {
    search?: string;
    page?: number;
    per_page?: number;
    has_inventory?: 'with' | 'without';
    sort?: 'nombre' | 'proveedor' | 'existencia' | 'importe';
    direction?: 'asc' | 'desc';
};

export async function listProductos(params?: ListProductosParams) {
    const { data } = await http.get('/productos', { params });
    return data;
}

export async function exportProductos(params?: ListProductosParams): Promise<AxiosResponse<Blob>> {
    return http.get('/productos/export', { params, responseType: 'blob' });
}

export async function getProducto(id: number) {
    const { data } = await http.get(`/productos/${id}`);
    return data as Producto;
}

export async function createProducto(p: Partial<Producto>) {
    const { data } = await http.post('/productos', p);
    return data as Producto;
}

export async function updateProducto(id: number, p: Partial<Producto>) {
    const { data } = await http.put(`/productos/${id}`, p);
    return data as Producto;
}

export async function deleteProducto(id: number) {
    await http.delete(`/productos/${id}`);
}

export async function listProveedores() {
    const { data } = await http.get('/proveedores');
    return data as Proveedor[];
}

/** Inventory hooks */
export async function setStock(ident: number, existencia: number, provee: number, precio?: number) {
    const { data } = await http.post('/inventario/set-stock', { ident, existencia, provee, precio });
    return data;
}

/** Bulk upload CSV (backend: create /api/productos/bulk-upload) */
export async function bulkUploadCSV(file: File) {
    const form = new FormData();
    form.append('file', file);
    const { data } = await http.post('/productos/bulk-upload', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
    });
    return data;
}
