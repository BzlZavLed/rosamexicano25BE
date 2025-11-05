import http from './http';

export type MetodoResumen = {
    metodo: "efectivo" | "tarjeta" | "transferencia" | string; // keep string to allow future methods
    total: number;
    transacciones: number;
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
