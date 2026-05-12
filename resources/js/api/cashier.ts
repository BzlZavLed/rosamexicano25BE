// src/api/cashier.ts
import http from './http'; // your axios instance with baseURL + auth

export type CashMethod = 'efectivo' | 'tarjeta' | 'transferencia';

export type CashierFindParams = {
    barcode?: number | string;
    search?: string;
    proveedor_id?: number;
    per_page?: number;
};

export async function cajaStatus() {
    const { data } = await http.get('/caja/status');
    return data as { open: boolean; caja: any };
}

export async function cajaOpen(payload: { saldoinicial: number; fecha?: string }) {
    const { data } = await http.post('/caja/open', payload);
    return data;
}

export async function cajaClose(payload?: { saldofinal?: number; fecha?: string }) {
    const { data } = await http.post('/caja/close', payload ?? {});
    return data;
}

export async function findProduct(params: CashierFindParams) {
    const { data } = await http.get('/cashier/find-product', { params });
    // API returns { data: Producto[] }
    return Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
}

export type CheckoutLinePayload = {
    idProd: number;
    nombre: string;
    proveedor: number;
    pUni: number;
    cant: number;
    product_desc?: number;
    totdesc?: number;
    manual_discount?: number;
    promotion_type?: 'descuento' | 'bundle' | 'precio_fijo';
};

export type CheckoutPayload = {
    idventa?: number;
    metodo: CashMethod;
    recibo: number;
    cambio: number;
    vendedor: string;
    concepto?: string;
    lineas: CheckoutLinePayload[];
};

export async function checkout(payload: CheckoutPayload) {
    const { data } = await http.post('/cashier/checkout', payload);
    return data; // { data: {...} }
}

export async function sendSaleTicket(payload: {
    venta_id: number;
    canal: 'email' | 'sms';
    cliente: { nombre: string; email?: string | null; telefono?: string | null };
    ticket_pdf_base64?: string;
}) {
    const { data } = await http.post('/cashier/send-ticket', payload);
    return data;
}

export type RegisterExpensePayload = {
    descripcion: string;
    monto: number;
    fecha: string;
};

export async function registerExpense(payload: RegisterExpensePayload) {
    const { data } = await http.post('/cashier/expenses', payload);
    return data;
}
