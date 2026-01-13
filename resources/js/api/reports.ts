import http from './http';

export type RestockHorizon = '2w' | '4w' | '6w';

export type CajaReportLine = {
    producto_id: number;
    nombre: string;
    provider: {
        id: number | null;
        nombre: string | null;
        tipo: 'normal' | 'consigna' | 'porcentaje';
        porcentaje?: number | null;
    } | null;
    quantity: number;
    free_quantity: number;
    unit_price: number;
    public_total: number;
    promotion_discount_amount: number;
    manual_discount_amount: number;
    credit_card_discount: number;
    provider_price: number;
    provider_discount_type: 'normal' | 'consigna' | 'porcentaje';
    provider_discount_amount: number;
    provider_payment: number;
    admin_earnings: number;
    free_product: boolean;
};

export type CajaReportVenta = {
    idventa: number;
    fecha: string;
    hora: string;
    metodo: string;
    vendedor?: string | null;
    totalventa: number;
    total_recibido: number;
    cambio: number;
    lineas: CajaReportLine[];
};

export type CajaMethodSummary = {
    metodo: string;
    total: number;
    count: number;
};

export type CajaReportSummary = {
    ventas_total: number;
    total_totalventa: number;
    total_recibido: number;
    total_cambio: number;
    metodos: CajaMethodSummary[];
};

export type CajaReportResponse = {
    from_date: string;
    to_date: string;
    summary: CajaReportSummary;
    ventas: CajaReportVenta[];
};

export type EgresoCajaMovimiento = {
    id: number;
    fecha: string;
    descripcion: string;
    monto: number;
    creado_por: string | null;
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

export interface FlujoCajaRow {
    fecha: string;
    saldo_inicial: number;
    efectivo: number;
    transferencia: number;
    tarjeta: number;
    ingresos_total: number;
    egresos: number;
    saldo_cierre: number;
}

export interface FlujoCajaResumen {
    dias: number;
    saldo_inicial: number;
    efectivo: number;
    transferencia: number;
    tarjeta: number;
    ingresos_total: number;
    egresos: number;
    saldo_cierre: number;
}

export interface FlujoCajaResponse {
    from_date: string;
    to_date: string;
    resumen: FlujoCajaResumen;
    items: FlujoCajaRow[];
}

export interface RestockForecastItem {
    provider_ident: string;
    provider_name: string | null;
    provider_email?: string | null;
    producto_ident: string;
    producto_nombre: string | null;
    avg_daily_sales: number;
    inventory_on_hand: number;
    projected_demand: number;
    recommended_inventory: number;
    suggested_order_qty: number;
    days_of_cover: number | null;
    lead_time_days: number;
    lookback_days: number;
    restock_by_date: string;
    restock_asap: boolean;
}

export interface RestockForecastResponse {
    forecast_date: string;
    horizon: RestockHorizon;
    lookback_days: number;
    lead_time_days: number;
    minimum_inventory_days?: number;
    summary: {
        total_items: number;
        total_suggested: number;
        avg_daily_sales: number;
    };
    items: RestockForecastItem[];
}

export interface RestockNotifyResponse {
    forecast_date: string;
    horizon: RestockHorizon;
    sent: number;
    skipped: number;
    providers_notified: Array<{
        provider_ident: string;
        provider_name: string | null;
        email: string;
    }>;
    providers_skipped: Array<{
        provider_ident: string | null;
        provider_name: string | null;
        reason: string;
    }>;
    message: string;
}

export interface InventoryProposalItem {
    producto_ident: string;
    producto_nombre: string | null;
    provider_ident: string | null;
    provider_name: string | null;
    avg_daily_sales: number;
    recommended_inventory: number;
    inventory_on_hand: number | null;
    total_units: number;
}

export interface InventoryProposalSummary {
    horizon: RestockHorizon;
    generated_at: string | null;
    lookback_days: number;
    lead_time_days: number;
    minimum_inventory_days: number;
    total_items: number;
}

export interface InventoryProposalResponse {
    horizon: RestockHorizon;
    generated_at: string | null;
    lookback_days: number;
    lead_time_days: number;
    minimum_inventory_days: number;
    items: InventoryProposalItem[];
}

export interface InventoryProposalNotifyResponse {
    forecast_date: string;
    horizon: RestockHorizon;
    sent: number;
    skipped: number;
    providers_notified: Array<{
        provider_ident: string;
        provider_name: string | null;
        email: string;
    }>;
    providers_skipped: Array<{
        provider_ident: string | null;
        provider_name: string | null;
        reason: string;
    }>;
    message: string;
}

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

export async function getFlujoCajaReport(params: { from_date: string; to_date?: string; download?: boolean }) {
    const { from_date, to_date, download } = params;
    const query: Record<string, string> = { from_date };
    if (to_date) query.to_date = to_date;
    if (download) query.download = '1';

    if (download) {
        const { data } = await http.get('/reports/flujo-caja', {
            params: query,
            responseType: 'blob',
        });
        return data as Blob;
    }

    const { data } = await http.get<FlujoCajaResponse>('/reports/flujo-caja', { params: query });
    return data;
}

export async function getRestockForecastReport(params: { forecast_date?: string; provider?: string; horizon?: RestockHorizon }) {
    const query: Record<string, string> = {};
    if (params.forecast_date) query.forecast_date = params.forecast_date;
    if (params.provider) query.provider = params.provider;
    if (params.horizon) query.horizon = params.horizon;

    const { data } = await http.get<RestockForecastResponse>('/reports/restock-forecast', { params: query });
    return data;
}

export async function updateRestockPreference(horizon: RestockHorizon) {
    const { data } = await http.post('/reports/restock-forecast/preference', { horizon });
    return data as { horizon: RestockHorizon };
}

export async function notifyRestockForecast(params: { horizon: RestockHorizon; providers?: string[] }) {
    const { data } = await http.post<RestockNotifyResponse>('/reports/restock-forecast/notify', params);
    return data;
}

export async function listInventoryProposals() {
    const { data } = await http.get<{ proposals: InventoryProposalSummary[] }>('/reports/inventory-proposals');
    return data.proposals;
}

export async function getInventoryProposal(horizon: RestockHorizon) {
    const { data } = await http.get<InventoryProposalResponse>(`/reports/inventory-proposals/${horizon}`);
    return data;
}

export async function generateInventoryProposal(params: { horizon: RestockHorizon; lookback_days?: number }) {
    const { data } = await http.post<InventoryProposalResponse>('/reports/inventory-proposals', params);
    return data;
}

export async function notifyInventoryProposal(params: { horizon: RestockHorizon; providers?: string[] }) {
    const { data } = await http.post<InventoryProposalNotifyResponse>('/reports/inventory-proposals/notify', params);
    return data;
}

// ---------------------
// REPORT PRODUCTOS
// ---------------------

export interface Proveedor {
    ident: string;
    nombre: string;
    tipo?: 'normal' | 'consigna' | 'porcentaje' | null;
    porcentaje_comision?: number | null;
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
    producto_descripcion?: string | null;
    precio: number | null;
    precio_proveedor: number | null;
    existencia: number;
    costo_inventario: number;
    proveedor: Proveedor | null;
}

export interface InventarioReportResponse {
    data: InventarioRow[];
    pagination: ProductosPagination;
    totals: {
        total_productos: number;
        total_existencia: number;
        valor_publico: number;
        valor_proveedor: number;
    };
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
    summary: MensualidadReportSummary;
    items: MensualidadReportItem[];
}

// ---------------------
// REPORT CAJA PROVEEDORES (CONDENSADO)
// ---------------------

export interface CajaProveedorItem {
    ventadesg_id: number;
    idventa: number;
    venta_id: number | null;
    fecha: string | null;
    fecha_raw?: string | null;
    fecha_iso?: string | null;
    producto_ident: string;
    producto_nombre: string;
    cantidad: number;
    precio_unitario: number;
    total: number;
    provider_discount: number;
    manual_discount: number;
    card_fee: number;
    real_earning: number;
    expected_earning?: number;
    provider_price?: number | null;
    provider_cost_total?: number | null;
    proveedor_tipo?: string | null;
    proveedor_porcentaje?: number | null;
    metodo: string;
    vendedor: string;
    venta_total: number;
    promotion?: string | null;
}

export interface CajaProveedorGroup {
    proveedor_id: number | null;
    proveedor_ident: string | null;
    proveedor_nombre: string;
    proveedor_tipo: string;
    proveedor_porcentaje?: number | null;
    total_vendido: number;
    card_fee_total: number;
    manual_discount_total: number;
    tipo_descuento_total: number;
    real_earning: number;
    expected_earning?: number;
    totals?: {
        cantidad: number;
        precio_promedio: number;
        total: number;
        provider_discount: number;
        manual_discount: number;
        card_fee: number;
        ganancia: number;
    };
    items: CajaProveedorItem[];
}

export interface CajaProveedoresResumen {
    ventas_brutas: number;
    descuentos: number;
    manual_descuentos: number;
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
    manual_descuentos_total: number;
    proveedores: CajaProveedorGroup[];
    items_meta?: {
        current_page: number;
        per_page: number;
        total: number;
        last_page: number;
    };
    items_totals?: {
        cantidad: number;
        precio_promedio: number;
        total: number;
        provider_discount: number;
        manual_discount: number;
        card_fee: number;
        ganancia: number;
    };
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
    provider_tipo?: 'normal' | 'consigna' | 'porcentaje';
    proveedor_id?: number;
} = {}): Promise<InventarioReportResponse> {
    const params: Record<string, string | number> = {};
    if (opts.q) params.q = opts.q;
    if (opts.page) params.page = opts.page;
    if (opts.per_page) params.per_page = opts.per_page;
    if (opts.sort) params.sort = opts.sort;
    if (opts.direction) params.direction = opts.direction;
    if (opts.provider_tipo) params.provider_tipo = opts.provider_tipo;
    if (opts.proveedor_id) params.proveedor_id = opts.proveedor_id;

    const { data } = await http.get<InventarioReportResponse>('/reports/inventario', { params });
    return data;
}

export async function downloadInventarioReport(opts: {
    q?: string;
    sort?: 'producto' | 'existencia' | 'proveedor';
    direction?: 'asc' | 'desc';
    provider_tipo?: 'normal' | 'consigna' | 'porcentaje';
    proveedor_id?: number;
} = {}): Promise<Blob> {
    const params: Record<string, string | number> = { download: 1 };
    if (opts.q) params.q = opts.q;
    if (opts.sort) params.sort = opts.sort;
    if (opts.direction) params.direction = opts.direction;
    if (opts.provider_tipo) params.provider_tipo = opts.provider_tipo;
    if (opts.proveedor_id) params.proveedor_id = opts.proveedor_id;

    const { data } = await http.get('/reports/inventario', {
        params,
        responseType: 'blob',
    });
    return data as Blob;
}

export async function getEntradasReport(params: { from_date: string; to_date: string }) {
    const { from_date, to_date } = params;
    const query: Record<string, string> = { from_date, to_date };
    const { data } = await http.get<EntradasReportResponse>('/reports/entradas', { params: query });
    return data;
}

export async function getCajaProveedoresReport(params: {
    from_date: string;
    to_date?: string;
    download?: boolean;
    q?: string;
    page?: number;
    per_page?: number;
}) {
    const query: Record<string, string | number> = { from_date: params.from_date };
    if (params.to_date) query.to_date = params.to_date;
    if (params.download) query.download = 1;
    if (params.q) query.q = params.q;
    if (params.page) query.page = params.page;
    if (params.per_page) query.per_page = params.per_page;
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

export async function getMensualidadReport(params: { download?: boolean } = {}) {
    const query: Record<string, string | number> = {};
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

export type CancelacionReportItem = {
    id: number;
    venta_id: number;
    idventa: string | number | null;
    reason: string | null;
    cancelled_at: string | null;
    sale_date: string | null;
    sale_time: string | null;
    metodo: string | null;
    vendedor: string | null;
    total: number | null;
    admin: {
        id: number;
        nombre: string | null;
        email: string | null;
    } | null;
    line_items: Array<{
        producto_nombre: string | null;
        producto_ident: string | number | null;
        cantidad: number | null;
        unit_price: number | null;
        line_total: number | null;
    }>;
};

export type CancelacionesReportResponse = {
    range: { from: string; to: string };
    count: number;
    items: CancelacionReportItem[];
};

export async function getCancelacionesReport(params: { from_date: string; to_date?: string; q?: string }) {
    const query: Record<string, string> = { from_date: params.from_date };
    if (params.to_date) query.to_date = params.to_date;
    if (params.q) query.q = params.q;
    const { data } = await http.get<CancelacionesReportResponse>('/reports/cancelaciones', { params: query });
    return data;
}
