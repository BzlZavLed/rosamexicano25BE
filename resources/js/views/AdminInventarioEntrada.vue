<script setup lang="ts">
import { ref, reactive, computed, watch, onMounted, onUnmounted } from 'vue';
import AppLayout from '../components/layout/AppLayout.vue';
import { listProductos, type Producto } from '../api/products';

import { setStockAbsolute } from '../api/inventario';
import { jsPDF } from 'jspdf';
import JsBarcode from 'jsbarcode';

type PickedProduct = Producto & {
    proveedor_nombre?: string;
    proveedor?: { id: number; nombre: string } | null;
    inventario?: {
        id?: number;
        existencia?: number | string;
        importe?: number | string;
        precio_individual?: number | string;
        provee?: number | string;
    } | null;
};

type BatchMode = 'add' | 'set';
type BatchStatus = 'pending' | 'processing' | 'success' | 'error';

type BatchItem = {
    id: number;
    product: PickedProduct;
    mode: BatchMode;
    cantidad?: number;
    fecha?: string;
    newStock?: number;
    status: BatchStatus;
    error?: string;
};

const loading = ref(false);
const saving = ref(false);
const message = ref('');
const error = ref('');

const search = ref('');
const results = ref<PickedProduct[]>([]);
const selected = ref<PickedProduct | null>(null);
const pagination = reactive({
    page: 1,
    perPage: 10,
    lastPage: 1,
    total: 0
});
const pageNumbers = computed(() => {
    const pages = Math.max(1, pagination.lastPage || 1);
    return Array.from({ length: pages }, (_, idx) => idx + 1);
});
const pageInfo = computed(() => {
    if (!pagination.total) return null;
    const start = (pagination.page - 1) * pagination.perPage + 1;
    const end = Math.min(start + pagination.perPage - 1, pagination.total);
    return { start, end };
});

// Entrada (sumar)
const cantidad = ref<number | null>(null);
const entradaFecha = ref<string>(new Date().toISOString().slice(0, 10)); // YYYY-MM-DD

// Corrección (SET)
const newStock = ref<number | null>(null);

const unitPrice = computed<number>(() => Number(selected.value?.precio ?? 0));
const totalImporte = computed<number>(() => Number(cantidad.value || 0) * unitPrice.value);

const currentStock = computed<number>(() =>
    Number((selected.value as any)?.inventario?.existencia ?? 0)
);
const newImporte = computed<number>(() => {
    const target = Number(newStock.value ?? currentStock.value);
    return target * unitPrice.value;
});

const batchMode = ref(false);
const batchItems = ref<BatchItem[]>([]);
const processingBatch = ref(false);
const batchGeneratePDF = ref(false);
const hasBatchItems = computed(() => batchItems.value.length > 0);
const batchHasInvalid = computed(() =>
    batchItems.value.some((item) => {
        if (item.mode === 'add') {
            const value = Number(item.cantidad ?? NaN);
            return !Number.isFinite(value) || value < 0;
        }
        const target = Number(item.newStock ?? NaN);
        return !Number.isFinite(target) || target < 0;
    })
);
const canGenerateReceipts = computed(() => batchItems.value.some((item) => item.mode === 'add'));
const existenciaFilter = ref<'all' | 'with' | 'without'>('all');
const existenciaOptions = [
    { value: 'all', label: 'Todas las existencias' },
    { value: 'with', label: 'Con existencia' },
    { value: 'without', label: 'Sin existencia' },
] as const;
const sortField = ref<'nombre' | 'proveedor' | 'existencia' | 'importe'>('nombre');
const sortOptions = [
    { value: 'nombre', label: 'Nombre' },
    { value: 'proveedor', label: 'Proveedor' },
    { value: 'existencia', label: 'Existencia' },
    { value: 'importe', label: 'Importe' },
] as const;
const sortDirection = ref<'asc' | 'desc'>('asc');
const directionOptions = [
    { value: 'asc', label: 'Ascendente' },
    { value: 'desc', label: 'Descendente' },
] as const;

const todayIso = () => new Date().toISOString().slice(0, 10);

function normalizeDateInput(raw?: string | null): string {
    const fallback = todayIso();
    if (raw === undefined || raw === null) return fallback;
    const trimmed = String(raw).trim();
    if (!trimmed) return fallback;
    if (/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) return trimmed;

    const replaced = trimmed.replace(/[\/\.]/g, '-');
    if (/^\d{4}-\d{2}-\d{2}$/.test(replaced)) return replaced;
    if (/^\d{2}-\d{2}-\d{4}$/.test(replaced)) {
        const [day, month, year] = replaced.split('-').map(Number);
        if (
            Number.isFinite(day) &&
            Number.isFinite(month) &&
            Number.isFinite(year)
        ) {
            const parsed = new Date(year as number, (month as number) - 1, day as number);
            if (!Number.isNaN(parsed.getTime())) return parsed.toISOString().slice(0, 10);
        }
    }
    const parsed = new Date(trimmed);
    if (!Number.isNaN(parsed.getTime())) return parsed.toISOString().slice(0, 10);
    const parsedReplaced = new Date(replaced);
    if (!Number.isNaN(parsedReplaced.getTime())) return parsedReplaced.toISOString().slice(0, 10);
    return fallback;
}

const batchCsvLoading = ref(false);
const batchCsvError = ref('');

watch(batchMode, (enabled) => {
    if (!enabled && batchItems.value.length === 0) {
        batchGeneratePDF.value = false;
    }
});

watch(batchItems, () => {
    if (!canGenerateReceipts.value) {
        batchGeneratePDF.value = false;
    }
}, { deep: true });

// ---------- Search (debounced) ----------
const SEARCH_DELAY_MS = 350;
let t: number | undefined;

async function runSearch() {
    loading.value = true; error.value = '';
    try {
        const params: Record<string, any> = {
            page: pagination.page,
            per_page: pagination.perPage
        };
        if (search.value) params.search = search.value;
        if (existenciaFilter.value !== 'all') params.has_inventory = existenciaFilter.value;
        if (sortField.value) params.sort = sortField.value;
        if (sortDirection.value) params.direction = sortDirection.value;
        const resp = await listProductos(params);
        const arr = Array.isArray(resp?.data) ? resp.data : (Array.isArray(resp) ? resp : []);
        results.value = arr as PickedProduct[];

        const meta = resp?.meta ?? null;
        const total = meta?.total ?? resp?.total ?? arr.length;
        const lastPage = meta?.last_page ?? meta?.lastPage ?? (total ? Math.ceil(total / pagination.perPage) : 1);
        pagination.total = total;
        pagination.lastPage = Math.max(1, lastPage || 1);
        if (pagination.page > pagination.lastPage) {
            pagination.page = pagination.lastPage;
        }
    } catch (e: any) {
        error.value = e?.response?.data?.message || 'Error buscando productos';
    } finally {
        loading.value = false;
    }
}

watch(search, () => {
    pagination.page = 1;
    if (t) clearTimeout(t);
    t = window.setTimeout(runSearch, SEARCH_DELAY_MS);
});
watch([existenciaFilter, sortField, sortDirection], () => {
    pagination.page = 1;
    if (t) clearTimeout(t);
    t = window.setTimeout(runSearch, SEARCH_DELAY_MS);
});
onUnmounted(() => { if (t) clearTimeout(t); });

watch(() => pagination.perPage, (newVal, oldVal) => {
    if (oldVal === undefined || newVal === oldVal) return;
    pagination.page = 1;
    if (t) { clearTimeout(t); t = undefined; }
    runSearch();
});

watch(() => pagination.page, (newVal, oldVal) => {
    if (oldVal === undefined || newVal === oldVal) return;
    if (t) { clearTimeout(t); t = undefined; }
    runSearch();
});

// ---------- Picking a product ----------
function pickProduct(p: PickedProduct) {
    const proveedorNombre = (p as any)?.proveedor?.nombre
        ?? (p as any)?.proveedor_nombre
        ?? '';
    selected.value = {
        ...p,
        precio: Number(p.precio),
        proveedor: p.proveedor ?? null,
        proveedor_nombre: proveedorNombre,
        inventario: (p as any).inventario ?? null,
    } as any;
    // reset form inputs
    cantidad.value = null;
    newStock.value = null;
    message.value = '';
    error.value = '';
}

// ---------- Inventory submitters ----------
const activeTab = ref<'entrada' | 'correccion'>('entrada');

type ProviderBatchEntry = {
    product: PickedProduct;
    cantidad: number;
    fecha: string;
};

async function fetchProductByIdent(ident: string | number): Promise<PickedProduct | null> {
    try {
        const resp = await listProductos({ search: String(ident), per_page: 5, page: 1 });
        const data = Array.isArray(resp?.data) ? resp.data : Array.isArray(resp) ? resp : [];
        const product = data.find((p: Producto) => String(p.ident) === String(ident)) ?? data[0];
        if (!product) return null;
        const proveedorNombre = (product as any)?.proveedor?.nombre ?? (product as any)?.proveedor_nombre ?? '';
        return {
            ...(product as any),
            precio: Number(product.precio),
            proveedor: (product as any).proveedor ?? null,
            proveedor_nombre: proveedorNombre,
            inventario: (product as any).inventario ?? null,
        } as PickedProduct;
    } catch (err) {
        console.error('fetchProductByIdent error', err);
        return null;
    }
}

function normalizeMode(raw: string): BatchMode | null {
    const value = raw.trim().toLowerCase();
    if (['add', 'entrada', 'sumar'].includes(value)) return 'add';
    if (['set', 'correccion', 'actualizar'].includes(value)) return 'set';
    return null;
}

function parseCsvContent(raw: string): Array<Record<string, string>> {
    const lines = raw.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
    if (lines.length === 0) return [];
    const header = lines[0] ? lines[0].split(',').map((h) => h.trim().toLowerCase()) : [];
    const rows: Array<Record<string, string>> = [];
    for (let i = 1; i < lines.length; i += 1) {
        const line = lines[i];
        if (!line) continue;
        const values = line.split(',').map((v) => v.trim());
        const row: Record<string, string> = {};
        header.forEach((key, idx) => {
            row[key] = values[idx] ?? '';
        });
        rows.push(row);
    }
    return rows;
}

async function importBatchFromCSV(file: File) {
    batchCsvLoading.value = true;
    batchCsvError.value = '';
    try {
        const text = await file.text();
        const parsedRows = parseCsvContent(text);
        if (parsedRows.length === 0) {
            batchCsvError.value = 'El archivo CSV está vacío o no tiene registros válidos.';
            return;
        }

        batchItems.value = [];
        const importedItems: BatchItem[] = [];
        const errors: string[] = [];

        for (let idx = 0; idx < parsedRows.length; idx += 1) {
            const row = parsedRows[idx];
            if (!row) {
                errors.push(`Fila ${idx + 2}: fila inválida o indefinida.`);
                continue;
            }
            const ident = row.ident || row['producto_ident'] || row['id'] || '';
            if (!ident) {
                errors.push(`Fila ${idx + 2}: falta la columna "ident".`);
                continue;
            }

            const modeRaw = row.mode || row['tipo'] || '';
            const mode = normalizeMode(modeRaw || 'add');
            if (!mode) {
                errors.push(`Fila ${idx + 2}: modo inválido "${modeRaw}". Usa "add" o "set".`);
                continue;
            }

            const product = await fetchProductByIdent(ident);
            if (!product) {
                errors.push(`Fila ${idx + 2}: no se encontró el producto con ident ${ident}.`);
                continue;
            }

            if (mode === 'add') {
                const cantidadRaw = row.cantidad ?? row['qty'] ?? row['cantidad_ingresar'] ?? '';
                const fechaValue = normalizeDateInput(
                    (row.fecha ?? row['fecha_entrada'] ?? row['date']) as string | undefined
                );
                const cantidadNum = Number(cantidadRaw);
                if (!Number.isFinite(cantidadNum) || cantidadNum <= 0) {
                    errors.push(`Fila ${idx + 2}: cantidad inválida "${cantidadRaw}".`);
                    continue;
                }
                importedItems.push({
                    id: Date.now() + Math.random(),
                    product,
                    mode,
                    cantidad: cantidadNum,
                    fecha: fechaValue,
                    status: 'pending',
                });
            } else {
                const newStockRaw = row['nueva_existencia'] ?? row['new_stock'] ?? row['existencia'] ?? '';
                const newStockNum = Number(newStockRaw);
                if (!Number.isFinite(newStockNum) || newStockNum < 0) {
                    errors.push(`Fila ${idx + 2}: existencia nueva inválida "${newStockRaw}".`);
                    continue;
                }
                importedItems.push({
                    id: Date.now() + Math.random(),
                    product,
                    mode,
                    newStock: newStockNum,
                    status: 'pending',
                });
            }
        }

        if (importedItems.length) {
            batchItems.value = [...batchItems.value, ...importedItems];
            batchMode.value = true;
            message.value = `Se importaron ${importedItems.length} movimientos desde CSV.`;
        }
        if (errors.length) {
            batchCsvError.value = errors.join(' ');
        }
    } catch (err: any) {
        console.error('importBatchFromCSV error', err);
        batchCsvError.value = err?.message || 'No se pudo procesar el archivo CSV.';
    } finally {
        batchCsvLoading.value = false;
    }
}

function ensureBatchMode() {
    if (!batchMode.value) batchMode.value = true;
}

function validateEntrada(): string | null {
    if (!selected.value?.id) return 'Selecciona un producto';
    const c = Number(cantidad.value);
    if (!Number.isFinite(c) || c < 0) return 'Cantidad inválida';
    if (!entradaFecha.value) return 'Selecciona fecha de entrada';
    return null;
}

function validateCorreccion(): string | null {
    if (!selected.value?.id) return 'Selecciona un producto';
    const target = Number(newStock.value);
    if (!Number.isFinite(target) || target < 0) return 'Existencia nueva inválida';
    return null;
}

function addToBatch() {
    error.value = '';
    message.value = '';
    if (!selected.value?.id) {
        error.value = 'Selecciona un producto para agregar al lote.';
        return;
    }

    const mode: BatchMode = activeTab.value === 'entrada' ? 'add' : 'set';
    if (mode === 'add') {
        const validation = validateEntrada();
        if (validation) {
            error.value = validation;
            return;
        }
        const fechaValue = normalizeDateInput(entradaFecha.value);
        batchItems.value.push({
            id: Date.now() + Math.random(),
            product: { ...selected.value },
            mode,
            cantidad: Number(cantidad.value ?? 0),
            fecha: fechaValue,
            status: 'pending',
        });
    } else {
        const validation = validateCorreccion();
        if (validation) {
            error.value = validation;
            return;
        }
        batchItems.value.push({
            id: Date.now() + Math.random(),
            product: { ...selected.value },
            mode,
            newStock: Number(newStock.value ?? 0),
            status: 'pending',
        });
    }

    ensureBatchMode();
    message.value = 'Producto agregado al lote.';
}

function removeBatchItem(id: number) {
    batchItems.value = batchItems.value.filter((item) => item.id !== id);
    if (batchItems.value.length === 0) {
        processingBatch.value = false;
    }
}

function clearBatch() {
    batchItems.value = [];
    processingBatch.value = false;
}

function handleBatchCsvChange(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    importBatchFromCSV(file).finally(() => {
        if (input) input.value = '';
    });
}

// One endpoint, two modes: 'add' (sumar) | 'set' (absoluto)
async function saveStock(mode: 'add' | 'set') {
    error.value = ''; message.value = '';
    const v = mode === 'add' ? validateEntrada() : validateCorreccion();
    if (v) { error.value = v; return; }

    saving.value = true;
    try {
        const payload =
            mode === 'add'
                ? { ident: Number(selected.value!.ident), existencia: Number(cantidad.value), mode: 'add' as const }
                : { ident: Number(selected.value!.ident), existencia: Number(newStock.value), mode: 'set' as const };

        const res = await setStockAbsolute(payload as any);
        message.value = mode === 'add'
            ? 'Inventario actualizado (entrada)'
            : 'Inventario actualizado (corrección SET)';

        // Merge response inventario if present (supports different shapes)
        (selected.value as any).inventario =
            res?.data?.inventario ?? res?.inventario ?? res ?? (selected.value as any).inventario;

        await runSearch();

        // Generate receipt only for "entrada"
        if (mode === 'add') {
            await makeReceiptPDF();
        }
    } catch (e: any) {
        error.value = e?.response?.data?.message || 'No se pudo actualizar inventario';
    } finally {
        saving.value = false;
    }
}

const statusLabels: Record<BatchStatus, string> = {
    pending: 'Pendiente',
    processing: 'Procesando…',
    success: 'Listo',
    error: 'Error',
};

function batchStatusClass(status: BatchStatus) {
    switch (status) {
        case 'success':
            return 'text-emerald-600';
        case 'processing':
            return 'text-amber-600';
        case 'error':
            return 'text-rose-600';
        default:
            return 'text-gray-500';
    }
}

async function processBatch() {
    if (batchItems.value.length === 0 || processingBatch.value) return;
    processingBatch.value = true;
    message.value = '';
    error.value = '';
    const providerEntries: ProviderBatchEntry[] = [];

    try {
        for (const item of batchItems.value) {
            item.status = 'processing';
            try {
                if (item.mode === 'add') {
                    const qty = Number(item.cantidad ?? 0);
                    const fecha = item.fecha || new Date().toISOString().slice(0, 10);
                    await setStockAbsolute({
                        ident: Number(item.product.ident),
                        existencia: qty,
                        mode: 'add',
                    } as any);
                    if (batchGeneratePDF.value && qty > 0) {
                        providerEntries.push({
                            product: item.product,
                            cantidad: qty,
                            fecha,
                        });
                    }
                } else {
                    await setStockAbsolute({
                        ident: Number(item.product.ident),
                        existencia: Number(item.newStock ?? 0),
                        mode: 'set',
                    } as any);
                }
                item.status = 'success';
                item.error = '';
            } catch (err: any) {
                console.error('Batch item failed:', err);
                item.status = 'error';
                item.error = err?.response?.data?.message || err?.message || 'Error actualizando inventario.';
            }
        }

        await runSearch();
        if (batchGeneratePDF.value && providerEntries.length) {
            await makeProviderReceipts(providerEntries);
        }
        const hasErrors = batchItems.value.some((item) => item.status === 'error');
        if (hasErrors) {
            error.value = 'Algunas operaciones del lote no se completaron. Revisa los detalles.';
            message.value = '';
        } else {
            message.value = 'Lote procesado con éxito.';
        }
    } catch (err: any) {
        console.error(err);
        error.value = err?.response?.data?.message || err?.message || 'Error procesando lote.';
    } finally {
        processingBatch.value = false;
    }
}

// ---------- PDF Receipt ----------
function barcodePng(value: string) {
    const canvas = document.createElement('canvas');
    JsBarcode(canvas, value, { format: 'CODE128', displayValue: false, margin: 0, width: 1.6, height: 60 });
    return canvas.toDataURL('image/png');
}
function currency(n: number, currency = 'MXN', locale = 'es-MX') {
    try { return new Intl.NumberFormat(locale, { style: 'currency', currency }).format(n); }
    catch { return `$${n.toFixed(2)}`; }
}

async function makeReceiptPDF(
    fromBatch?: {
        product: PickedProduct;
        cantidad: number;
        fecha: string;
    }
) {
    const product = fromBatch?.product ?? selected.value;
    if (!product?.id) return;
    const qty = fromBatch ? Number(fromBatch.cantidad ?? 0) : Number(cantidad.value || 0);
    const fechaEntrada = normalizeDateInput(fromBatch?.fecha ?? entradaFecha.value);

    const doc = new jsPDF({ unit: 'mm', format: 'letter' });
    const ymd = fechaEntrada || new Date().toISOString().slice(0, 10);

    const proveedorNombre =
        (product as any)?.proveedor_nombre ||
        (product as any)?.proveedor?.nombre ||
        '—';

    // Header
    doc.setFont('helvetica', 'bold'); doc.setFontSize(14);
    doc.text('Entrada de inventario', 15, 20);
    doc.setFont('helvetica', 'normal'); doc.setFontSize(10);
    doc.text(`Fecha: ${ymd}`, 15, 27);
    doc.text(`Proveedor: ${proveedorNombre || '(desconocido)'}`, 15, 33);

    // Product
    doc.setDrawColor(220); doc.rect(12, 40, 186, 52);
    doc.setFont('helvetica', 'bold'); doc.text('Producto', 15, 47);
    doc.setFont('helvetica', 'normal');
    doc.text(product.nombre || 'Producto', 15, 53);
    doc.text(`Ident: ${String(product.ident).padStart(6, '0')}`, 15, 58);

    const url = barcodePng(String(product.ident).padStart(6, '0'));
    doc.addImage(url, 'PNG', 150, 44, 42, 16);

    // Amounts
    doc.setFont('helvetica', 'bold'); doc.text('Detalle', 15, 70);
    doc.setFont('helvetica', 'normal');
    const u = Number(product.precio ?? 0);
    const total = Number(qty) * u;

    doc.text(`Cantidad: ${qty}`, 15, 76);
    doc.text(`Precio unitario: ${currency(u)}`, 15, 82);
    doc.setFont('helvetica', 'bold');
    doc.text(`Importe: ${currency(total)}`, 15, 88);

    // Footer
    doc.setFont('helvetica', 'normal'); doc.setFontSize(9);
    doc.text('Firma proveedor:', 15, 110); doc.line(45, 110, 110, 110);
    doc.text('Firma recepción:', 120, 110); doc.line(150, 110, 200, 110);

    const fname = `entrada_${String(product.ident).padStart(6, '0')}_${ymd}.pdf`;
    doc.save(fname);
}

function providerNameFor(product: PickedProduct) {
    return product.proveedor_nombre || product.proveedor?.nombre || 'Proveedor sin asignar';
}

async function makeProviderReceipts(entries: ProviderBatchEntry[]) {
    if (!entries.length) return;
    const grouped = new Map<string, ProviderBatchEntry[]>();
    entries.forEach((entry) => {
        const provider = providerNameFor(entry.product);
        if (!grouped.has(provider)) grouped.set(provider, []);
        grouped.get(provider)!.push(entry);
    });

    const doc = new jsPDF({ unit: 'mm', format: 'letter' });
    const generationDate = new Date().toISOString().slice(0, 10);

    const renderHeader = (providerName: string) => {
        doc.setFont('helvetica', 'bold'); doc.setFontSize(16);
        doc.text('Entrada de inventario', 15, 20);
        doc.setFont('helvetica', 'normal'); doc.setFontSize(11);
        doc.text(`Proveedor: ${providerName}`, 15, 28);
        doc.text(`Fecha de generación: ${generationDate}`, 15, 34);

        doc.setFont('helvetica', 'bold'); doc.setFontSize(10);
        doc.text('Producto', 15, 44);
        doc.text('Ident', 80, 44);
        doc.text('Cantidad', 105, 44);
        doc.text('Precio', 130, 44);
        doc.text('Importe', 160, 44);
        doc.text('Fecha', 195, 44, { align: 'right' });
        doc.setDrawColor(220);
        doc.line(12, 47, 198, 47);
    };

    const renderFooter = () => {
        doc.setFont('helvetica', 'normal'); doc.setFontSize(9);
        doc.text('Firma proveedor:', 15, 270); doc.line(45, 270, 110, 270);
        doc.text('Firma recepción:', 120, 270); doc.line(150, 270, 200, 270);
    };

    let isFirstPage = true;
    grouped.forEach((items, providerName) => {
        if (!isFirstPage) {
            doc.addPage('letter', 'portrait');
        }
        isFirstPage = false;
        renderHeader(providerName);

        let y = 54;
        let total = 0;
        doc.setFont('helvetica', 'normal'); doc.setFontSize(7);
        items.forEach((entry) => {
            const unitPrice = Number(entry.product.precio ?? 0);
            const importe = unitPrice * Number(entry.cantidad ?? 0);
            total += importe;
            if (y > 250) {
                doc.addPage('letter', 'portrait');
                renderHeader(providerName);
                y = 54;
            }
            const productName = entry.product.nombre || 'Producto';
            const shortName = productName.length > 50 ? `${productName.slice(0, 47)}…` : productName;
            doc.text(shortName, 15, y);
            doc.text(String(entry.product.ident).padStart(6, '0'), 80, y);
            doc.text(String(entry.cantidad ?? 0), 105, y);
            doc.text(currency(unitPrice), 130, y);
            doc.text(currency(importe), 160, y);
            doc.text(entry.fecha || generationDate, 195, y, { align: 'right' });
            y += 8;
        });

        doc.setFont('helvetica', 'bold');doc.setFontSize(9);
        doc.text('Total proveedor:', 130, y + 4);
        doc.text(currency(total), 198, y + 4, { align: 'right' });
        renderFooter();
    });

    const filename = `entradas_proveedores_${generationDate}.pdf`;
    doc.save(filename);
}

// Money helper for template
const money = (v: number | string | null | undefined, currency = 'MXN', locale = 'es-MX') => {
    const n = Number(v ?? 0);
    if (!Number.isFinite(n)) return '—';
    try {
        return new Intl.NumberFormat(locale, { style: 'currency', currency, minimumFractionDigits: 2 }).format(n);
    } catch {
        return `$${n.toFixed(2)}`;
    }
};

function goToPage(page: number) {
    const target = Math.max(1, Math.min(page, pagination.lastPage || 1));
    if (target === pagination.page) return;
    pagination.page = target;
}

function goToPrevPage() {
    goToPage(pagination.page - 1);
}

function goToNextPage() {
    goToPage(pagination.page + 1);
}

// Init
onMounted(() => { runSearch(); });
</script>

<template>
    <AppLayout>
        <div class="space-y-6">
            <!-- Search & results -->
            <section class="bg-white border border-gray-200 rounded-xl shadow-sm p-5 md:p-6 space-y-4">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="text-xl font-semibold">Buscar producto</h2>
                    <div class="flex items-center gap-2 text-xs text-gray-500">
                        <span>Encuentra el producto y selecciónalo para actualizar inventario.</span>
                    </div>
                </div>

                <div class="space-y-2">
                    <div class="relative">
                        <input v-model="search" type="text" placeholder="Nombre, descripción, ident…"
                            class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2 pr-9"
                            aria-label="Buscar producto" />
                        <button v-if="search"
                            class="absolute inset-y-0 right-0 mr-2 my-1 px-2 text-sm text-gray-500 hover:text-gray-700 rounded-md hover:bg-gray-100"
                            @click="search = ''; runSearch()" type="button" aria-label="Limpiar búsqueda">✕</button>
                    </div>
                    <p class="text-xs text-gray-500">Muestra los primeros 10 resultados que coincidan con la búsqueda.
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Existencia</label>
                        <select v-model="existenciaFilter"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900">
                            <option v-for="opt in existenciaOptions" :key="opt.value" :value="opt.value">
                                {{ opt.label }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Ordenar por</label>
                        <select v-model="sortField"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900">
                            <option v-for="opt in sortOptions" :key="opt.value" :value="opt.value">
                                {{ opt.label }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Dirección</label>
                        <select v-model="sortDirection"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900">
                            <option v-for="opt in directionOptions" :key="opt.value" :value="opt.value">
                                {{ opt.label }}
                            </option>
                        </select>
                    </div>
                </div>

                <div class="border rounded-lg overflow-hidden">
                    <!-- Desktop table -->
                    <table class="hidden min-w-full text-sm md:table">
                        <thead class="bg-gray-50 text-gray-500">
                            <tr>
                                <th class="text-left font-medium px-3 py-2">ID</th>
                                <th class="text-left font-medium px-3 py-2">Ident</th>
                                <th class="text-left font-medium px-3 py-2">Producto</th>
                                <th class="text-left font-medium px-3 py-2">Proveedor</th>
                                <th class="text-left font-medium px-3 py-2">Existencia</th>
                                <th class="text-right font-medium px-3 py-2">Precio</th>
                                <th class="text-right font-medium px-3 py-2">Importe</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in results" :key="p.id" @click="pickProduct(p)"
                                :class="['cursor-pointer hover:bg-gray-50 transition', selected?.id === p.id ? 'bg-gray-100' : '']">
                                <td class="px-3 py-2">{{ p.id }}</td>
                                <td class="px-3 py-2">{{ p.ident }}</td>
                                <td class="px-3 py-2">{{ p.nombre }}</td>
                                <td class="px-3 py-2">
                                    <span v-if="p.proveedor?.nombre">{{ p.proveedor.nombre }}</span>
                                    <span v-else class="text-rose-600 font-medium">No proveedor definido</span>
                                </td>
                                <td class="px-3 py-2">{{ (p as any)?.inventario?.existencia ?? 0 }} unidades</td>
                                <td class="px-3 py-2 text-right">{{ money(p.precio) }}</td>
                                <td class="px-3 py-2 text-right">{{ money((p as any)?.inventario?.importe) }}</td>
                            </tr>
                            <tr v-if="!loading && results.length === 0">
                                <td colspan="7" class="px-3 py-3 text-center text-gray-500">Sin resultados</td>
                            </tr>
                            <tr v-if="loading">
                                <td colspan="7" class="px-3 py-3 text-center text-gray-500">Buscando…</td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Mobile cards -->
                    <div class="md:hidden divide-y divide-gray-100 max-h-80 overflow-auto">
                        <button v-for="p in results" :key="p.id" @click="pickProduct(p)"
                            class="w-full text-left p-3 space-y-2 transition hover:bg-gray-50"
                            :class="selected?.id === p.id ? 'bg-gray-100' : 'bg-white'">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-semibold text-gray-800">{{ p.nombre }}</span>
                                <span class="text-xs text-gray-500">#{{ p.ident }}</span>
                            </div>
                            <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-gray-600">
                                <div><span class="font-medium text-gray-700">Proveedor:</span> {{
                                    p.proveedor?.nombre || '—'
                                    }}</div>
                                <div class="text-right"><span class="font-medium text-gray-700">Existencia:</span> {{
                                    (p as any)?.inventario?.existencia ?? 0 }} uds</div>
                                <div><span class="font-medium text-gray-700">Precio:</span>
                                    {{ money(p.precio) }}</div>
                                <div class="text-right"><span class="font-medium text-gray-700">Importe:</span>
                                    {{ money((p as any)?.inventario?.importe) }}</div>
                            </div>
                        </button>
                        <div v-if="!loading && results.length === 0" class="p-4 text-center text-sm text-gray-500">
                            Sin resultados
                        </div>
                        <div v-if="loading" class="p-4 text-center text-sm text-gray-500">Buscando…</div>
                    </div>
                </div>
                <div
                    class="flex flex-col gap-3 text-sm text-gray-600 md:flex-row md:items-center md:justify-between">
                    <div class="flex flex-wrap items-center gap-2">
                        <span>Filas por página:</span>
                        <select v-model.number="pagination.perPage"
                            class="rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-gray-900 focus:ring-gray-900">
                            <option v-for="option in [5, 10, 20, 50]" :key="option" :value="option">{{ option }}</option>
                        </select>
                        <span v-if="pageInfo">Mostrando {{ pageInfo.start }} – {{ pageInfo.end }} de {{
                            pagination.total }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="goToPrevPage" :disabled="pagination.page <= 1"
                            class="rounded-lg border px-3 py-1.5 text-sm hover:bg-gray-50 disabled:opacity-50">
                            Anterior
                        </button>
                        <select v-model.number="pagination.page"
                            class="rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-gray-900 focus:ring-gray-900">
                            <option v-for="pageNumber in pageNumbers" :key="pageNumber" :value="pageNumber">
                                Página {{ pageNumber }}
                            </option>
                        </select>
                        <button @click="goToNextPage" :disabled="pagination.page >= pagination.lastPage"
                            class="rounded-lg border px-3 py-1.5 text-sm hover:bg-gray-50 disabled:opacity-50">
                            Siguiente
                        </button>
                    </div>
                </div>
            </section>

            <!-- Selected product / inventory actions -->
            <section class="bg-white border border-gray-200 rounded-xl shadow-sm p-5 md:p-6 space-y-5">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-xl font-semibold">Entrada de inventario</h2>
                        <p class="text-xs text-gray-500 mt-1">Registra entradas o corrige el stock actual del producto
                            seleccionado.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <label class="inline-flex items-center gap-2 rounded-full border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">
                            <input type="checkbox" v-model="batchMode"
                                class="rounded border-gray-300 text-gray-900 focus:ring-gray-900" />
                            <span>Modo lote</span>
                        </label>
                        <div class="flex gap-2">
                            <button class="px-3 py-1.5 text-sm rounded-full"
                            :class="activeTab === 'entrada'
                                ? 'bg-gray-900 text-white shadow-sm'
                                : 'border border-gray-300 text-gray-600 hover:bg-gray-50'"
                            @click="activeTab = 'entrada'" type="button">
                            Entrada
                        </button>
                        <button class="px-3 py-1.5 text-sm rounded-full"
                                :class="activeTab === 'correccion'
                                ? 'bg-gray-900 text-white shadow-sm'
                                : 'border border-gray-300 text-gray-600 hover:bg-gray-50'"
                            @click="activeTab = 'correccion'" type="button">
                            Corrección SET
                        </button>
                        </div>
                    </div>
                </div>

                <div v-if="message"
                    class="rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-700 px-4 py-2 text-sm">
                    {{ message }}
                </div>
                <div v-if="error"
                    class="rounded-lg border border-rose-200 bg-rose-50 text-rose-700 px-4 py-2 text-sm">
                    {{ error }}
                </div>

                <div v-if="selected?.id" class="space-y-5">
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                        <div class="flex flex-col gap-3 md:flex-row md:justify-between md:items-start">
                            <div class="space-y-1">
                                <p class="text-sm text-gray-500 uppercase tracking-wide">Producto seleccionado</p>
                                <h3 class="text-lg font-semibold text-gray-900">{{ selected?.nombre }}</h3>
                                <p class="text-xs text-gray-500">Proveedor: {{ selected?.proveedor_nombre || '—' }}</p>
                            </div>
                            <div
                                class="grid grid-cols-2 gap-3 text-xs text-gray-600 md:grid-cols-3 md:gap-4 md:min-w-[280px]">
                                <div class="p-2 rounded-md bg-white border">
                                    <p class="text-[11px] uppercase text-gray-400">Ident</p>
                                    <p class="text-sm font-semibold text-gray-800">{{ String(selected?.ident).padStart(6,
                                        '0') }}</p>
                                </div>
                                <div class="p-2 rounded-md bg-white border">
                                    <p class="text-[11px] uppercase text-gray-400">Precio</p>
                                    <p class="text-sm font-semibold text-gray-800">{{ money(selected?.precio) }}</p>
                                </div>
                                <div class="p-2 rounded-md bg-white border">
                                    <p class="text-[11px] uppercase text-gray-400">Existencia</p>
                                    <p class="text-sm font-semibold text-gray-800">{{ currentStock }} uds</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Entrada tab -->
                    <div v-if="activeTab === 'entrada'" class="space-y-4">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <div class="space-y-1">
                                <label class="block text-sm font-medium text-gray-700">Cantidad a ingresar</label>
                                <input v-model.number="cantidad" type="number" min="0" step="1"
                                    class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2"
                                    placeholder="0" />
                            </div>
                            <div class="space-y-1">
                                <label class="block text-sm font-medium text-gray-700">Fecha de entrada</label>
                                <input v-model="entradaFecha" type="date"
                                    class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2" />
                            </div>
                            <div class="space-y-1">
                                <label class="block text-sm font-medium text-gray-700">Importe total</label>
                                <div class="h-[42px] flex items-center justify-between rounded-lg border border-gray-200 bg-white px-3">
                                    <span class="text-sm text-gray-500">cantidad × precio</span>
                                    <span class="text-lg font-semibold text-[#E4007C]">{{
                                        money(totalImporte)
                                    }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:flex-wrap">
                            <button :disabled="saving || !selected?.id || !cantidad" @click="saveStock('add')"
                                class="w-full rounded-lg bg-[#E4007C] hover:bg-[#cc006f] text-white px-4 py-2 text-sm disabled:opacity-60 sm:w-auto">
                                Guardar entrada + recibo
                            </button>
                            <button type="button" :disabled="!selected?.id || !cantidad" @click="() => makeReceiptPDF()"
                                class="w-full rounded-lg border px-4 py-2 text-sm hover:bg-gray-50 disabled:opacity-60 sm:w-auto">
                                Generar recibo (PDF) sin guardar
                            </button>
                            <button type="button" v-if="batchMode"
                                :disabled="!selected?.id || !cantidad || !entradaFecha"
                                @click="addToBatch"
                                class="w-full rounded-lg border border-dashed px-4 py-2 text-sm hover:bg-gray-50 disabled:opacity-60 sm:w-auto">
                                Agregar al lote
                            </button>
                        </div>
                    </div>

                    <!-- Corrección tab -->
                    <div v-else class="space-y-4">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <div class="space-y-1">
                                <label class="block text-sm font-medium text-gray-700">Existencia actual</label>
                                <div
                                    class="h-[42px] flex items-center rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-700">
                                    {{ currentStock }} unidades
                                </div>
                            </div>
                            <div class="space-y-1">
                                <label class="block text-sm font-medium text-gray-700">Existencia nueva</label>
                                <input v-model.number="newStock" type="number" min="0" step="1"
                                    class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2"
                                    placeholder="0" />
                            </div>
                            <div class="space-y-1">
                                <label class="block text-sm font-medium text-gray-700">Importe nuevo</label>
                                <div class="h-[42px] flex items-center justify-between rounded-lg border border-gray-200 bg-white px-3">
                                    <span class="text-sm text-gray-500">existencia × precio</span>
                                    <span class="text-lg font-semibold text-[#E4007C]">{{ money(newImporte) }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:flex-wrap">
                            <button :disabled="saving || newStock === null || newStock < 0" @click="saveStock('set')"
                                class="w-full rounded-lg bg-gray-900 hover:bg-gray-800 text-white px-4 py-2 text-sm disabled:opacity-60 sm:w-auto">
                                Actualizar inventario (SET)
                            </button>
                            <button type="button" v-if="batchMode"
                                :disabled="newStock === null || newStock < 0 || !selected?.id"
                                @click="addToBatch"
                                class="w-full rounded-lg border border-dashed px-4 py-2 text-sm hover:bg-gray-50 disabled:opacity-60 sm:w-auto">
                                Agregar al lote
                            </button>
                        </div>
                    </div>
                </div>

                <div v-else
                    class="rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-6 text-center text-sm text-gray-600">
                    Selecciona un producto para comenzar a registrar entradas o correcciones.
                </div>
            </section>

            <section v-if="batchMode || hasBatchItems"
                class="bg-white border border-gray-200 rounded-xl shadow-sm p-5 md:p-6 space-y-4">
                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Trabajo en lote</h3>
                        <p class="text-xs text-gray-500 mt-1">
                            Prepara varias entradas o correcciones y procesa todo con un solo clic.
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <label v-if="canGenerateReceipts" class="inline-flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" v-model="batchGeneratePDF"
                                class="rounded border-gray-300 text-gray-900 focus:ring-gray-900" />
                            <span>Generar recibo PDF por entrada</span>
                        </label>
                        <button type="button" @click="clearBatch" :disabled="processingBatch || batchItems.length === 0"
                            class="w-full rounded-lg border px-3 py-1.5 text-sm hover:bg-gray-50 disabled:opacity-50 sm:w-auto">
                            Quitar todos
                        </button>
                        <button type="button" @click="processBatch"
                            :disabled="processingBatch || batchItems.length === 0 || batchHasInvalid"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-gray-900 text-white px-4 py-2 text-sm hover:bg-gray-800 disabled:opacity-60 sm:w-auto sm:justify-start">
                            <svg v-if="processingBatch" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                class="h-4 w-4 animate-spin text-white" aria-hidden="true">
                                <path fill="currentColor"
                                    d="M12 2a1 1 0 0 1 1 1v2.05a1 1 0 0 1-2 0V3a1 1 0 0 1 1-1Zm6.36 3.64a1 1 0 0 1 0 1.41l-1.45 1.45a1 1 0 1 1-1.41-1.41l1.45-1.45a1 1 0 0 1 1.41 0ZM21 11a1 1 0 1 1 0 2h-2.05a1 1 0 0 1 0-2H21ZM8.5 6.09a1 1 0 0 1-1.41 1.41L5.64 6.05A1 1 0 1 1 7.05 4.64L8.5 6.09ZM7 11a1 1 0 0 1 1 1H7Zm1 0a1 1 0 0 1 2 0H8Zm2 0a1 1 0 0 1 2 0h-2Zm2 0a1 1 0 0 1 2 0h-2Zm2 0a1 1 0 0 1 2 0h-2Z" />
                            </svg>
                            <span>{{ processingBatch ? 'Procesando lote…' : 'Procesar lote' }}</span>
                        </button>
                    </div>
                </div>

                <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4 space-y-2">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="space-y-1">
                            <p class="text-xs text-gray-600">
                                También puedes cargar un CSV para prellenar el lote. Usa estas columnas:
                            </p>
                            <ul class="text-xs text-gray-600 list-disc list-inside space-y-0.5">
                                <li><code>ident</code> (requerido)</li>
                                <li><code>mode</code> (`add` para entradas, `set` para correcciones)</li>
                                <li><code>cantidad</code> (para `add`) o <code>new_stock</code> (para `set`)</li>
                                <li><code>fecha</code>/<code>fecha_entrada</code>/<code>date</code> (opcional, formato AAAA-MM-DD)</li>
                            </ul>
                        </div>
                        <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                            <input type="file" accept=".csv" @change="handleBatchCsvChange"
                                :disabled="batchCsvLoading"
                                class="w-full rounded border border-gray-300 px-3 py-2 text-sm file:mr-3 file:rounded file:border file:border-gray-300 file:bg-white file:px-3 file:py-1.5 file:text-sm hover:file:bg-gray-50 disabled:opacity-60 sm:w-auto" />
                            <span v-if="batchCsvLoading" class="text-xs text-gray-500">Cargando CSV…</span>
                        </div>
                    </div>
                    <p v-if="batchCsvError" class="text-xs text-rose-600">{{ batchCsvError }}</p>
                </div>

                <div v-if="batchItems.length === 0"
                    class="rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-6 text-center text-sm text-gray-600">
                    Usa &ldquo;Agregar al lote&rdquo; en las acciones del producto o carga un CSV para sumar movimientos aquí.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-500">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium">Producto</th>
                                <th class="px-3 py-2 text-left font-medium">Ident</th>
                                <th class="px-3 py-2 text-left font-medium">Modo</th>
                                <th class="px-3 py-2 text-left font-medium">Cantidad / Nueva existencia</th>
                                <th class="px-3 py-2 text-left font-medium">Fecha</th>
                                <th class="px-3 py-2 text-left font-medium">Estado</th>
                                <th class="px-3 py-2 text-right font-medium">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="item in batchItems" :key="item.id">
                                <td class="px-3 py-2">
                                    <div class="font-semibold text-gray-900">{{ item.product.nombre }}</div>
                                    <div class="text-xs text-gray-500">{{ item.product.proveedor_nombre || item.product.proveedor?.nombre || '—' }}</div>
                                </td>
                                <td class="px-3 py-2">#{{ String(item.product.ident).padStart(6, '0') }}</td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex items-center rounded-full px-2 py-[2px] text-xs font-semibold"
                                        :class="item.mode === 'add' ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-700'">
                                        {{ item.mode === 'add' ? 'Entrada' : 'Corrección' }}
                                    </span>
                                </td>
                                <td class="px-3 py-2">
                                    <div v-if="item.mode === 'add'" class="flex items-center gap-2">
                                        <input v-model.number="item.cantidad" type="number" min="0" step="1"
                                            class="w-24 rounded border border-gray-300 px-2 py-1 text-sm focus:border-gray-900 focus:ring-gray-900"
                                            @input="item.status = 'pending'; item.error = ''" />
                                        <span class="text-xs text-gray-500">uds</span>
                                    </div>
                                    <div v-else class="flex items-center gap-2">
                                        <input v-model.number="item.newStock" type="number" min="0" step="1"
                                            class="w-24 rounded border border-gray-300 px-2 py-1 text-sm focus:border-gray-900 focus:ring-gray-900"
                                            @input="item.status = 'pending'; item.error = ''" />
                                        <span class="text-xs text-gray-500">uds</span>
                                    </div>
                                </td>
                                <td class="px-3 py-2">
                                    <template v-if="item.mode === 'add'">
                                        <input v-model="item.fecha" type="date"
                                            class="rounded border border-gray-300 px-2 py-1 text-sm focus:border-gray-900 focus:ring-gray-900"
                                            @input="item.status = 'pending'; item.error = ''" />
                                    </template>
                                    <span v-else class="text-xs text-gray-500">—</span>
                                </td>
                                <td class="px-3 py-2">
                                    <div :class="['text-sm font-medium', batchStatusClass(item.status)]">
                                        {{ statusLabels[item.status] }}
                                    </div>
                                    <div v-if="item.error" class="text-xs text-rose-600 mt-1">
                                        {{ item.error }}
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <button type="button" @click="removeBatchItem(item.id)" :disabled="processingBatch"
                                        class="text-sm text-rose-600 hover:text-rose-700 disabled:opacity-40">
                                        Quitar
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-if="batchHasInvalid" class="text-xs text-amber-600">
                    Verifica que todas las cantidades sean números válidos y mayores o iguales a cero antes de procesar
                    el lote.
                </p>
            </section>
        </div>
    </AppLayout>
</template>
