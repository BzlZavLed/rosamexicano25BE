import http from "./http";

export type SingleCobroPayload = {
    fecha_cobro: string; // YYYY-MM-DD
    mes_cobro: string; // YYYY-MM
    concepto: string;
    nota?: string | null;
    proveedor_id: number;
    importe: number;
    status?: string;
    payment_date?: string | null;
    receipt_pdf_base64?: string | null;
};

export type BulkCobroItem = {
    proveedor_id: number;
    importe: number;
    cobro_pdf_base64?: string | null;
};

export type BulkCobroPayload = {
    concepto: string;
    mes_cobro: string;
    fecha_cobro: string;
    nota?: string | null;
    cobros: BulkCobroItem[];
};

export async function createCobro(payload: SingleCobroPayload) {
    const { data } = await http.post("/mensualidad", payload);
    return data;
}

export type CobroBatchResponse = {
    message?: string;
    created?: number;
    skipped?: number;
    mail?: {
        sent?: number;
        failed?: number;
    };
    data?: Mensualidad[];
};

export async function createCobrosBatch(payload: BulkCobroPayload) {
    const { data } = await http.post("/mensualidad/bulk", payload);
    console.log("createCobrosBatch response data:", data);
    return data as CobroBatchResponse;
}

export type Mensualidad = {
    id: number;
    proveedor_id: number;
    proveedor_nombre?: string;
    nombre?: string;
    proveedor_ident?: number;
    concepto: string;
    nota?: string | null;
    importe: number;
    pagado?: number | null;
    restante?: number | null;
    cantidad_pago?: number | null;
    pago_completo?: boolean | null;
    mes_cobro: string;
    fecha_cobro: string;
    status: string;
    payment_date?: string | null;
    receipt_path?: string | null;
    receipt_url?: string | null;
    cobro_path?: string | null;
    mail_status?: number | null;
    created_at?: string;
    updated_at?: string;
    origen?: "bulk" | "single" | string | null;
    proveedor?: {
        id?: number;
        nombre?: string;
        email?: string | null;
    } | null;
};

export type MensualidadListResponse = {
    data: Mensualidad[];
    meta?: {
        current_page?: number;
        per_page?: number;
        total?: number;
        last_page?: number;
    };
    links?: unknown;
};

export type MensualidadPaymentPayload = {
    provider_id?: number;
    receipt_pdf_base64: string;
    cantidad_pago?: number;
    restante?: number;
    pago_completo?: boolean;
    payment_date?: string | null;
    subject?: string | null;
    message?: string | null;
    email?: string | null;
};

export async function listMensualidad(params?: {
    page?: number;
    per_page?: number;
    search?: string;
    mes_cobro?: string;
    status?: string;
}) {
    const { data } = await http.get("/mensualidad", { params });
    const rows = Array.isArray((data as any)?.data)
        ? (data as any).data
        : Array.isArray(data)
            ? data
            : [];

    const meta = (data as any)?.meta || {};
    const total = meta?.total ?? (data as any)?.total ?? rows.length;
    const perPage =
        meta?.per_page ??
        (data as any)?.per_page ??
        params?.per_page ??
        rows.length;
    const lastPage =
        meta?.last_page ??
        (data as any)?.last_page ??
        (perPage ? Math.ceil(total / perPage) : 1);

    return {
        data: rows as Mensualidad[],
        meta: {
            current_page:
                meta?.current_page ?? (data as any)?.current_page ?? params?.page ?? 1,
            per_page: perPage,
            total,
            last_page: lastPage,
        },
    } as MensualidadListResponse;
}

export async function payMensualidad(
    id: number,
    payload: MensualidadPaymentPayload
) {
    const { data } = await http.post(`/mensualidad/${id}/pay`, payload);
    return data;
}

export async function sendMensualidadMail(
    id: number,
    payload: { email: string; asunto?: string | null }
) {
    const body: Record<string, any> = { email: payload.email };
    if (payload.asunto) body.asunto = payload.asunto;
    const { data } = await http.post(`/mensualidad/${id}/send-mail`, body);
    return data;
}

//WIDGET WRAPPERS

export async function getCobrosResumen(params: {
    year: number;
    month: number;
}) {
    // If your backend expects yyyy-mm, derive it here:
    // const ym = `${params.year}-${String(params.month).padStart(2, '0')}`

    // If you already have a "summary" endpoint, call it here.
    // Otherwise, compute summary from listMensualidad.
    const mes_cobro = `${params.year}-${String(params.month).padStart(2, '0')}`;
    const res = await listMensualidad({ mes_cobro });
    console.log("Cobros resumen data:", res);
    // Map into the shape the widget expects:
    const proveedoresConImporte = Array.isArray(res?.data)
        ? res.data.filter((i: any) => Number(i.importe) > 0).length
        : 0;
    const totalMensual = Array.isArray(res?.data)
        ? res.data.reduce((sum: number, i: any) => sum + Number(i.importe || 0), 0)
        : 0;
    // Decide business rule for canRunBulk (example: if any item pending)
    const canRunBulk = proveedoresConImporte > 0;

    return { proveedoresConImporte, totalMensual, canRunBulk };
}

export async function runBulkCobros(params: { year: number; month: number }) {
    // Construct mes_cobro and fecha_cobro from year and month
    const mes_cobro = `${params.year}-${String(params.month).padStart(2, '0')}`;
    const fecha_cobro = `${params.year}-${String(params.month).padStart(2, '0')}-01`;
    // You may want to adjust concepto, nota, and cobros as needed
    return createCobrosBatch({
        concepto: "",
        mes_cobro,
        fecha_cobro,
        nota: null,
        cobros: [],
    });
}
