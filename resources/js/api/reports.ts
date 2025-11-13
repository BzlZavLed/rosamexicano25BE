import http from './http';

export type CajaReportLine = {
    idprod: number;
    nombre: string;
    proveedor: number;
    proveedor_nombre?: string | null;
    proveedor_tipo?: 'normal' | 'consigna' | 'porcentaje';
    proveedor_porcentaje?: number | null;
    puni: number;
    cant: number;
    total: number;
    product_desc?: number;
    descuento_producto?: number;
    cargo_tarjeta_proveedor?: number;
    promotion?: string;
    proveedor_bruto?: number;
    proveedor_descuento?: number;
    proveedor_neto?: number;
    admin_ganancia?: number;
};

export type CajaReportProvider = {
    proveedor_id: number;
    nombre: string;
    tipo: 'normal' | 'consigna' | 'porcentaje';
    porcentaje?: number | null;
    publico_total: number;
    proveedor_bruto: number;
    proveedor_descuento: number;
    provider_card_charge: number;
    proveedor_neto: number;
    admin_ganancia: number;
    percent: number;
};

export type CajaReportVenta = {
    idventa: number;
    fecha: string;
    metodo: string;
    subtotal: number;
    descuento_lineas: number;
    tarjeta_cargo: number;
    totalventa: number;
    ingreso_real: number;
    costo_total: number;
    ganancia_total: number;
    ie: number;
    concepto: string;
    recibo: number;
    cambio: number;
    vendedor: string;
    lineas: CajaReportLine[];
    providers: CajaReportProvider[];
};

export type CajaBasicsSummary = {
    total_ventas: number;
    total_unidades: number;
    total_ingresos: number;
};

export type CajaPaymentSummary = {
    channels: Record<'cash' | 'card' | 'transfer' | 'other', number>;
    total: number;
    methods: Array<{ label: string; amount: number }>;
};

export type CajaProviderDiscount = {
    proveedor_id: number;
    nombre: string;
    tipo: string;
    porcentaje?: number | null;
    ventas_brutas: number;
    card_charge: number;
    descuentos: number;
    neto: number;
};

export type CajaTopProduct = {
    nombre: string;
    proveedor: string | number | null;
    unidades: number;
    total: number;
};

export type CajaReportSummary = {
    ventas_total: number;
    subtotal: number;
    descuento_lineas: number;
    tarjeta_cargo: number;
    total_totalventa: number;
    ingreso_real: number;
    costo_total: number;
    ganancia_total: number;
};

export type CajaReportResponse = {
    from_date: string;
    to_date: string;
    summary: CajaReportSummary;
    ventas: CajaReportVenta[];
    basics?: CajaBasicsSummary;
    payment_summary?: CajaPaymentSummary;
    provider_discounts?: CajaProviderDiscount[];
    top_products?: CajaTopProduct[];
};

export type EgresoCajaMovimiento = {
    idventa: number;
    fecha: string;
    metodo: string;
    concepto: string;
    totalventa: number;
    vendedor: string;
};

export type EgresosCajaSummary = {
    ingresos_total: number;
    egresos_total: number;
    saldo: number;
};

export type EgresosCajaReportResponse = {
    from_date: string;
    to_date: string;
    egresos: EgresoCajaMovimiento[];
    summary: EgresosCajaSummary;
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

export async function getEgresosCajaReport(params: { from_date: string; to_date?: string; download?: boolean }) {
    const { from_date, to_date, download } = params;
    const query: Record<string, string | number> = { from_date };
    if (to_date) query.to_date = to_date;
    if (download) query.download = 1;

    if (download) {
        const { data } = await http.get('/reports/egresos-caja', {
            params: query,
            responseType: 'blob',
        });
        return data as Blob;
    }

    const { data } = await http.get<EgresosCajaReportResponse>('/reports/egresos-caja', { params: query });
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
// REPORT MENSUALIDAD
// ---------------------

export interface MensualidadReportItem {
    id: number;
    proveedor: {
        id: number | null;
        nombre: string | null;
        email: string | null;
    } | null;
    concepto: string;
    nota?: string | null;
    mes_cobro: string;
    fecha_cobro: string | null;
    importe: number;
    cantidad_pago: number;
    restante: number;
    pago_completo: boolean;
    status: string;
    payment_date: string | null;
    receipt_path: string | null;
    cobro_path: string | null;
}

export interface MensualidadReportSummary {
    total_cobros: number;
    importe_total: number;
    pagado_total: number;
    restante_total: number;
    pagos_completos: number;
}

export interface MensualidadReportResponse {
    mes_cobro: string;
    filters: {
        status: string | null;
        proveedor_id: number | null;
    };
    summary: MensualidadReportSummary;
    items: MensualidadReportItem[];
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

export interface ProviderTrendProduct {
    ident: string;
    nombre: string;
    cantidad: number;
    total: number;
}

export interface ProviderTrendEarning {
    date: string;
    amount: number;
}

export interface ProviderTrendsResponse {
    range: { start: string; end: string };
    top_products: ProviderTrendProduct[];
    earnings: ProviderTrendEarning[];
}

// --- Productos API call (standardized to `http`) ---
export async function getProductosReport(opts: {
    q?: string;
    page?: number;
    per_page?: number;
    sort?: 'nombre' | 'proveedor' | 'precio';
    direction?: 'asc' | 'desc';
} = {}): Promise<ProductosReportResponse> {
    const params: Record<string, string | number> = {};
    if (opts.q) params.q = opts.q;
    if (opts.page) params.page = opts.page;
    if (opts.per_page) params.per_page = opts.per_page;
    if (opts.sort) params.sort = opts.sort;
    if (opts.direction) params.direction = opts.direction;

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

export async function getProviderTrends(params: { from_date: string; to_date: string }) {
    const { data } = await http.get<ProviderTrendsResponse>('/reports/provider/trends', { params });
    return data;
}

export async function getMensualidadReport(params: {
    mes_cobro: string;
    status?: string;
    proveedor_id?: number;
    download?: boolean;
}) {
    const query: Record<string, string | number> = {
        mes_cobro: params.mes_cobro,
    };
    if (params.status && params.status !== 'all') query.status = params.status;
    if (typeof params.proveedor_id === 'number') query.proveedor_id = params.proveedor_id;
    if (params.download) query.download = 1;

    if (params.download) {
        const { data } = await http.get('/reports/mensualidad', {
            params: query,
            responseType: 'blob',
        });
        return data as Blob;
    }

    const { data } = await http.get<MensualidadReportResponse>('/reports/mensualidad', { params: query });
    return data;
}
