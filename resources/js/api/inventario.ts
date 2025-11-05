import http from '../api/http';

export type InventarioUpsertPayload =
  | { ident: number | string; existencia: number }        // by barcode (preferred in your flow)
  | { product_id: number; existencia: number };           // or by product id

export async function setStockAbsolute(payload: InventarioUpsertPayload) {
    const { data } = await http.post('/inventario/set-stock', payload);
    return data; 
}
