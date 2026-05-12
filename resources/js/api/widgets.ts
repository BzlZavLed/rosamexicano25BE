import http from './http';
import type { RestockHorizon } from './reports';

export type MetodoResumen = {
    metodo: "efectivo" | "tarjeta" | "transferencia" | string; // keep string to allow future methods
    total: number;
    transacciones: number;
};

export type SoldProduct = {
    venta_id: number;
    fecha: string;
    producto_id: number;
    producto_nombre: string | null;
    cantidad: number;
    total: number;
    metodo: string | null;
};

export type CashierSummaryResponse = {
    fecha: string; // e.g., "31/10/25" (dd/mm/yy)
    entradas_total: number;
    salidas_total: number;
    transacciones: {
        entradas: number;
        salidas: number;
    };
    productos_vendidos: number;
    metodos: MetodoResumen[];
    saldo_inicial: number;
    saldo_final: number;
    productos?: SoldProduct[];
};

export async function getCashierSummary(fecha?: string) {
    const { data } = await http.get<CashierSummaryResponse>('/widgets/cashier-summary', {
        params: fecha ? { fecha } : undefined,
    });
    return data;
}

export type TopProductsResponse = {
    desde: string;
    hasta: string;
    productos: Array<{
        producto_id: number;
        producto_nombre: string;
        proveedor_id: number;
        proveedor_nombre: string;
        cantidad_vendida: number;
    }>;
};

export async function getTopProducts() {
    const { data } = await http.get<TopProductsResponse>('/widgets/top-products');
    return data;
}

export interface RestockAlertsResponse {
    forecast_date: string;
    horizon: RestockHorizon;
    items: Array<{
        provider_ident: string;
        provider_name: string | null;
        producto_ident: string;
        producto_nombre: string | null;
        inventory_on_hand: number;
        avg_daily_sales: number;
        recommended_inventory: number;
        suggested_order_qty: number;
        days_of_cover: number | null;
        restock_by_date: string;
        restock_asap: boolean;
    }>;
}

export async function getRestockAlerts(params: { horizon?: RestockHorizon; limit?: number } = {}) {
    const query: Record<string, string | number> = {};
    if (params.horizon) query.horizon = params.horizon;
    if (params.limit) query.limit = params.limit;

    const { data } = await http.get<RestockAlertsResponse>('/widgets/restock-alerts', {
        params: Object.keys(query).length ? query : undefined,
    });
    return data;
}

export type HostingServicePayment = {
    id: number;
    implementation_key: string;
    implementation_name: string;
    service_month: string;
    service_month_date: string;
    due_date: string;
    amount: number;
    paid: boolean;
    paid_at: string | null;
};

export type HostingServicePaymentMonth = {
    due_date: string;
    service_month: string;
    service_month_label: string;
    total_amount: number;
    paid_amount: number;
    all_paid: boolean;
    implementations: HostingServicePayment[];
};

export type HostingServicePaymentsResponse = {
    months_requested: number;
    monthly_amount_per_implementation: number;
    implementations_count: number;
    monthly_total: number;
    items: HostingServicePaymentMonth[];
};

export async function getHostingServicePayments(params: { months?: 3 | 6 | 9 | 12 } = {}) {
    const { data } = await http.get<HostingServicePaymentsResponse>('/widgets/hosting-service-payments', {
        params: params.months ? { months: params.months } : undefined,
    });
    return data;
}

export async function updateHostingServicePayment(
    id: number,
    payload: { paid: boolean; paid_at?: string | null }
) {
    const { data } = await http.patch<HostingServicePayment>(`/widgets/hosting-service-payments/${id}`, payload);
    return data;
}
