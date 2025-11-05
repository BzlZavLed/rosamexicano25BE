// src/api/autocomplete.ts
import http from './http';

export async function fetchProductosBySearch(q: string) {
    const { data } = await http.get('/productos', { params: { search: q, per_page: 10 } });
    const arr = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
    // Expect each item has: ident, nombre
    return arr.map((p: any) => ({
        ...p,
        _label: `${p.ident} — ${p.nombre}`,
        _value: p.ident, // we pick ident as the value to save in the promo
    }));
}

export async function fetchProveedoresBySearch(q: string) {
    const { data } = await http.get('/proveedores', { params: { search: q, per_page: 10 } });
    const arr = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
    return arr.map((p: any) => ({
        ...p,
        _label: `${p.ident} — ${p.nombre}`,
        _value: p.ident,
    }));
}
