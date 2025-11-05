// src/api/promociones.ts
import http from './http';

export type Promo = {
    id: number;
    target: 'producto' | 'proveedor';
    producto?: number | null;   // ident (barcode) when target=producto
    proveedor?: number | null;  // ident when target=proveedor
    tipo: 'descuento' | 'bundle';
    descuento?: number | null;  // %
    mincompra?: number | null;  // bundle threshold
    gratis?: number | null;     // bundle bonus
    inicia: string;             // 'YYYY-MM-DD' (your BE may return this)
    vence: string;              // 'YYYY-MM-DD'
    estado: boolean;
};

// Try server-side filtering first; fall back to client date filter.
export async function fetchActivePromosFor(productoIdent: number, proveedorIdent?: number) {
    const params: any[] = [];
    // If your index supports filters, great (otherwise you can fetch all and filter)
    // Example tries to narrow down:
    params.push(['target', 'producto']);
    params.push(['producto', String(productoIdent)]);
    params.push(['estado', '1']);

    // Also ask for proveedor-level ones:
    const [{ data: p1 }, { data: p2 }] = await Promise.all([
        http.get('/promociones', { params: Object.fromEntries(params) }),
        proveedorIdent
            ? http.get('/promociones', { params: { target: 'proveedor', proveedor: proveedorIdent, estado: 1 } })
            : Promise.resolve({ data: [] }),
    ]);

    const list = [
        ...(Array.isArray(p1?.data) ? p1.data : Array.isArray(p1) ? p1 : []),
        ...(Array.isArray(p2?.data) ? p2.data : Array.isArray(p2) ? p2 : []),
    ] as Promo[];

    // Filter by dates and estado on FE just in case:
    const today = new Date();
    const isActive = (promo: Promo) => {
        if (!promo.estado) return false;
        const start = new Date(promo.inicia);
        const end = new Date(promo.vence);
        return start <= today && today <= end;
    };

    return list.filter(isActive);
}
