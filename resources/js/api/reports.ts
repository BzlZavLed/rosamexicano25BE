import http from './http';

export type CajaReportLine = {
    idprod: number;
    nombre: string;
    proveedor: number;
    puni: number;
    cant: number;
    total: number;
    product_desc?: number;
    descuento_producto?: number;
    promotion?: string;
};

export type CajaReportVenta = {
    idventa: number;
    fecha: string;
    metodo: string;
    subtotal: number;
    descuento_general?: number;
    descuento_general_percent?: number;
    descuento_general_amount?: number;
    tarjeta_cargo: number;
    descuento_lineas?: number;
    descuento_total?: number;
    totalventa: number;
    ie: number;
    concepto: string;
    recibo: number;
    cambio: number;
    vendedor: string;
    lineas: CajaReportLine[];
};

export type CajaReportResponse = {
    from_date: string;
    to_date: string;
    ventas: CajaReportVenta[];
};

type CajaReportParams = {
    from_date: string;
    to_date: string;
    download?: boolean;
};

export async function getCajaReport(params: CajaReportParams) {
    const { from_date, to_date, download } = params;
    const query: Record<string, any> = { from_date, to_date };
    if (download) query.download = 1;

    if (download) {
        const { data } = await http.get('/reports/caja', {
            params: query,
            responseType: 'blob',
        });
        return data as Blob;
    }

    const { data } = await http.get<CajaReportResponse>('/reports/caja', { params: query });
    return data;
}

// ---------------------
// REPORT PRODUCTOS
// ---------------------

export interface Proveedor {
    ident: string;
    nombre: string;
}

export interface ProductoRow {
    id: number;
    ident: string;
    nombre: string;
    precio: number | null;
    proveedor: Proveedor | null;
}

export interface ProductosPagination {
    total: number;
    count: number;
    per_page: number;
    current_page: number;
    last_page: number;
    next_page_url: string | null;
    prev_page_url: string | null;
}

export interface ProductosReportResponse {
    data: ProductoRow[];
    pagination: ProductosPagination;
}

// ---------------------
// REPORT INVENTARIO
// ---------------------

export interface InventarioRow {
    inventario_id: number;
    producto_ident: string;
    producto_nombre: string;
    precio: number | null;
    existencia: number;
    costo_inventario: number;
    proveedor: Proveedor | null;
}

export interface InventarioReportResponse {
    data: InventarioRow[];
    pagination: ProductosPagination;
}

// ---------------------
// REPORT ENTRADAS
// ---------------------

export interface EntradaRow {
    id: number;
    fecha: string;
    fecha_raw: string;
    fecha_iso: string;
    prodid: string;
    prodnombre: string;
    proveedor_ident: string | null;
    proveedor_nombre: string | null;
    ingreal: number;
    accion_code: number;
    accion: string;
    usuario: string | null;
}

export interface EntradasReportResponse {
    from_date: string;
    to_date: string;
    entradas: EntradaRow[];
}

// ---------------------
// REPORT CAJA PROVEEDORES (CONDENSADO)
// ---------------------

export interface CajaProveedorItem {
    ventadesg_id: number;
    idventa: number;
    venta_id: number;
    fecha: string;
    fecha_raw: string;
    fecha_iso: string;
    producto_ident: string;
    producto_nombre: string;
    cantidad: number;
    precio_unitario: number;
    total: number;
    descuento_producto: number;
    cargo_tarjeta: number;
    descuento_total: number;
    ganancia: number;
    metodo: string;
    vendedor: string;
    venta_total: number;
    promotion?: string | null;
}

export interface CajaProveedorGroup {
    proveedor_id: number;
    proveedor_ident: string;
    proveedor_nombre: string;
    ventas_brutas: number;
    descuentos: number;
    cargos_tarjeta: number;
    ganancia_total: number;
    items: CajaProveedorItem[];
}

export interface CajaProveedoresResumen {
    ventas_brutas: number;
    descuentos: number;
    cargos_tarjeta: number;
    descuento_general: number;
    ganancias: number;
}

export interface CajaProveedoresResponse {
    from_date: string;
    to_date: string;
    resumen: CajaProveedoresResumen;
    descuento_general_total: number;
    cargos_tarjeta_total: number;
    proveedores: CajaProveedorGroup[];
}

// --- Productos API call (standardized to `http`) ---
export async function getProductosReport(opts: {
    q?: string;
    page?: number;
    per_page?: number;
} = {}): Promise<ProductosReportResponse> {
    const params: Record<string, string | number> = {};
    if (opts.q) params.q = opts.q;
    if (opts.page) params.page = opts.page;
    if (opts.per_page) params.per_page = opts.per_page;

    // Match the same style as getCajaReport (baseURL handles /api)
    const { data } = await http.get<ProductosReportResponse>('/reports/productos', { params });
    return data;
}

export async function getInventarioReport(opts: {
    q?: string;
    page?: number;
    per_page?: number;
    sort?: 'producto' | 'existencia' | 'proveedor';
    direction?: 'asc' | 'desc';
} = {}): Promise<InventarioReportResponse> {
    const params: Record<string, string | number> = {};
    if (opts.q) params.q = opts.q;
    if (opts.page) params.page = opts.page;
    if (opts.per_page) params.per_page = opts.per_page;
    if (opts.sort) params.sort = opts.sort;
    if (opts.direction) params.direction = opts.direction;

    const { data } = await http.get<InventarioReportResponse>('/reports/inventario', { params });
    return data;
}

export async function getEntradasReport(params: { from_date: string; to_date: string }) {
    const { from_date, to_date } = params;
    const query: Record<string, string> = { from_date, to_date };
    const { data } = await http.get<EntradasReportResponse>('/reports/entradas', { params: query });
    return data;
}

export async function getCajaProveedoresReport(params: { from_date: string; to_date?: string; download?: boolean }) {
    const query: Record<string, string | number> = { from_date: params.from_date };
    if (params.to_date) query.to_date = params.to_date;
    if (params.download) query.download = 1;
    if (params.download) {
        const { data } = await http.get('/reports/caja-proveedores', {
            params: query,
            responseType: 'blob',
        });
        return data as Blob;
    }
    const { data } = await http.get<CajaProveedoresResponse>('/reports/caja-proveedores', { params: query });
    return data;
}
