import http from './http';

export type SaleLine = {
    id: number;
    producto_ident: number | string | null;
    producto_nombre: string | null;
    cantidad: number;
    free_quantity: number;
    public_total: number;
    venta_total: number;
    unit_price: number;
};

export type DaySale = {
    id: number;
    idventa: string | number | null;
    fecha: string | null;
    hora: string | null;
    metodo: string | null;
    vendedor: string | null;
    total: number;
    total_recibido: number;
    cambio: number;
    line_items: SaleLine[];
};

export async function listSalesByDate(payload: { date: string; admin_password: string }) {
    const { data } = await http.post<{ date: string; sales: DaySale[]; sales_count: number }>(
        '/admin/sales/list',
        payload
    );
    console.log(data);
    return data;
}

export async function cancelSale(id: number, payload: { admin_password: string; reason?: string }) {
    const { data } = await http.post<{ message: string }>(`/admin/sales/${id}/cancel`, payload);
    return data;
}
