<script setup lang="ts">
import { ref, reactive, watch, onMounted, computed } from 'vue';
import AppLayout from '../components/layout/AppLayout.vue';
import {
    listProductos,
    exportProductos,
    createProducto,
    updateProducto,
    deleteProducto,
    listProveedores,
    setStock,
    bulkUploadCSV,
    type Producto,
    type Proveedor,
    type ListProductosParams,
} from '../api/products';
import http from '../api/http';

import JsBarcode from 'jsbarcode';
import { jsPDF } from 'jspdf';

/* ---------- Label printing controls ---------- */
const gridPreset = ref<'5x5' | '6x6' | '7x7'>('6x6');
const includePrice = ref(false); // 👈 bind your checkbox to this
const copies = ref(1);           // pages to print

// 👈 nice currency formatting for the price text
function formatMoney(n?: number, currency = 'MXN', locale = 'es-MX') {
    if (typeof n !== 'number') return '';
    try { return new Intl.NumberFormat(locale, { style: 'currency', currency }).format(n); }
    catch { return `$${n.toFixed(2)}`; }
}

function displayMoney(value?: number | string | null) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return '$0.00';
    return formatMoney(numeric);
}

/** Page setup: Letter portrait (change to A4 if you prefer) */
const PAGE = { format: 'letter' as const, w: 215.9, h: 279.4 }; // mm
/** Gutters & margins (mm) — tweak to your printer/sheet */
const gutterX = 3, gutterY = 3, margin = 8;

/** Parse "6x6" -> {rows:6, cols:6} */
function parseGrid(preset: '5x5' | '6x6' | '7x7') {
    const [c, r] = preset.split('x').map(Number);
    return { cols: c, rows: r };
}

/** Compute label width/height (mm) based on grid + page */
function computeLabelBox(rows: number, cols: number) {
    const usableW = PAGE.w - margin * 2;
    const usableH = PAGE.h - margin * 2;
    const w = (usableW - gutterX * (cols - 1)) / cols;
    const h = (usableH - gutterY * (rows - 1)) / rows;
    return { w, h };
}

/** Create an offscreen barcode PNG data URL */
function barcodePngDataURL(value: string) {
    const canvas = document.createElement('canvas');
    JsBarcode(canvas, value, {
        format: 'CODE128',
        displayValue: false,
        margin: 0,
        width: 1.2,
        height: 24,
    });
    return canvas.toDataURL('image/png');
}

/** Draw one label at (x,y) with given box size */
function drawLabel(
    doc: jsPDF,
    x: number,
    y: number,
    boxW: number,
    boxH: number,
    productName: string,
    identStr: string,
    price?: number | string,    // allow string
    showPrice?: boolean
) {
    const pad = 2;
    const bcH = Math.max(12, boxH - 12);
    const bcW = boxW - pad * 2;

    const url = barcodePngDataURL(identStr);
    doc.addImage(url, 'PNG', x + pad, y + pad, bcW, Math.min(bcH, 20), undefined, 'FAST');

    // Text
    const name = productName ? (productName.length > 28 ? productName.slice(0, 28) + '…' : productName) : 'Producto';
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(7);

    const textTop = y + pad + Math.min(bcH, 20) + 2;
    doc.setTextColor(0, 0, 0);
    doc.text(name, x + pad, textTop);
    doc.text('#' + identStr, x + pad, textTop + 4);

    // --- PRICE ---
    const priceNum = typeof price === 'number' ? price : Number(price);
    if (showPrice && Number.isFinite(priceNum)) {
        doc.setFont('helvetica', 'bold');      // a bit stronger
        doc.setTextColor(228, 0, 124);         // brand color #E4007C
        const priceStr = formatMoney(priceNum) || `$${priceNum.toFixed(2)}`;

        // keep inside label box even if small
        const priceY = Math.min(textTop + 8, y + boxH - pad - 1);
        doc.text(priceStr, x + boxW - pad, priceY, { align: 'right' as const });

        // restore
        doc.setFont('helvetica', 'normal');
        doc.setTextColor(0, 0, 0);
    }

    // optional trim box (comment if you don’t want borders)
     doc.setDrawColor(235); doc.rect(x, y, boxW, boxH);
}
/** Print multiple pages. Each page is a full grid. */
function printLabels() {
    if (!form.ident) { error.value = 'Falta identificador interno'; return; }

    const pagesToPrint = Math.max(1, Number(copies.value || 1)); // pages, not labels
    const { rows = 1, cols = 1 } = parseGrid(gridPreset.value) ?? {};
    const box = computeLabelBox(rows, cols);

    const doc = new jsPDF({ unit: 'mm', format: PAGE.format });

    for (let pageIdx = 0; pageIdx < pagesToPrint; pageIdx++) {
        if (pageIdx > 0) doc.addPage(PAGE.format, 'portrait');

        for (let r = 0; r < rows; r++) {
            for (let c = 0; c < cols; c++) {
                const x = margin + c * (box.w + gutterX);
                const y = margin + r * (box.h + gutterY);
                drawLabel(
                    doc,
                    x, y,
                    box.w, box.h,
                    form.nombre,
                    String(form.ident).padStart(6, '0'),
                    Number(form.precio),         // <-- ensure number
                    includePrice.value // 👈 checkbox controls price text
                );
            }
        }
    }

    const perPage = rows * cols;
    doc.save(`etiquetas_${String(form.ident).padStart(6, '0')}_${gridPreset.value}_${pagesToPrint}p_${perPage}pp.pdf`);
}

/* ---------- state ---------- */
const loading = ref(false);
const saving = ref(false);
const message = ref('');
const error = ref('');

const q = ref('');
const existenciaFilter = ref<'all' | 'with' | 'without'>('all');
const existenciaOptions = [
    { value: 'all', label: 'Todas las existencias' },
    { value: 'with', label: 'Con existencia' },
    { value: 'without', label: 'Sin existencia' },
] as const;
const sortField = ref<'nombre' | 'proveedor' | 'existencia'>('nombre');
const sortOptions = [
    { value: 'nombre', label: 'Nombre' },
    { value: 'proveedor', label: 'Proveedor' },
    { value: 'existencia', label: 'Existencia' },
] as const;
const sortDirection = ref<'asc' | 'desc'>('asc');
const directionOptions = [
    { value: 'asc', label: 'Ascendente' },
    { value: 'desc', label: 'Descendente' },
] as const;
const productos = ref<Producto[]>([]);
const selectedId = ref<number | null>(null);
const proveedores = ref<Proveedor[]>([]);
const pagination = reactive({ page: 1, perPage: 20, lastPage: 1, total: 0 });
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

type FormT = {
    id?: number | null;
    ident: number | null;
    nombre: string;
    descripcion: string;
    proveedorid: number | null;
    precio: number | null;
    fecha: string; // YYYY-MM-DD
    cantidadInicial: number | null; // for inventory
};
const form = reactive<FormT>({
    id: null,
    ident: null,
    nombre: '',
    descripcion: '',
    proveedorid: null,
    precio: null,
    fecha: new Date().toISOString().slice(0, 10),
    cantidadInicial: null,
});

const barcodeRef = ref<SVGSVGElement | null>(null);
function renderBarcode() {
    if (!barcodeRef.value || !form.ident) return;
    JsBarcode(barcodeRef.value, String(form.ident), {
        format: 'CODE128',
        width: 1.6,
        height: 60,
        displayValue: true,
        fontSize: 12,
        margin: 0
    });
}

/* ---------- helpers ---------- */
function randIdent(): number {
    return Math.floor(100000 + Math.random() * 900000);
}
function resetForm() {
    form.id = null;
    form.ident = randIdent();
    form.nombre = '';
    form.descripcion = '';
    form.proveedorid = null;
    form.precio = null;
    form.fecha = new Date().toISOString().slice(0, 10);
    form.cantidadInicial = null;
    selectedId.value = null;
    message.value = '';
    error.value = '';
}

function buildListParams(overrides: Partial<ListProductosParams> = {}): ListProductosParams {
    const params: ListProductosParams = {
        page: pagination.page,
        per_page: pagination.perPage,
        ...overrides,
    };
    if (q.value) params.search = q.value;
    if (existenciaFilter.value !== 'all') params.has_inventory = existenciaFilter.value;
    if (sortField.value) params.sort = sortField.value;
    if (sortDirection.value) params.direction = sortDirection.value;
    return params;
}

async function loadList() {
    loading.value = true;
    error.value = '';
    try {
        const params = buildListParams();
        const resp = await listProductos(params);
        const rows = Array.isArray(resp?.data) ? resp.data : (Array.isArray(resp) ? resp : []);
        productos.value = rows;

        const meta = resp?.meta ?? null;
        const total = meta?.total ?? resp?.total ?? rows.length;
        const lastPage = meta?.last_page ?? meta?.lastPage ?? (total ? Math.ceil(total / pagination.perPage) : 1);

        pagination.total = total;
        pagination.lastPage = Math.max(1, lastPage || 1);

        if (pagination.page > pagination.lastPage) {
            pagination.page = pagination.lastPage;
        }
    } catch (e: any) {
        error.value = e?.response?.data?.message || 'Error listando productos';
    } finally {
        loading.value = false;
    }
}
async function loadProveedores() {
    try {
        const resp = await listProveedores();
        if (Array.isArray(resp)) {
            proveedores.value = resp;
        } else if (resp && Array.isArray((resp as { data?: unknown }).data)) {
            proveedores.value = (resp as { data: Proveedor[] }).data;
        } else {
            proveedores.value = [];
        }
    } catch { /* ignore */ }
}
async function selectRow(p: Producto) {
    selectedId.value = p.id;
    form.id = p.id;
    form.ident = p.ident;
    form.nombre = p.nombre;
    form.descripcion = p.descripcion || '';
    form.proveedorid = p.proveedorid;
    form.precio = p.precio;
    form.fecha = p.fecha || new Date().toISOString().slice(0, 10);
    form.cantidadInicial = null;
    message.value = ''; error.value = '';
}
async function submitCreateOrUpdate() {
    error.value = ''; message.value = '';
    if (!form.ident) form.ident = randIdent();
    if (!form.nombre || !form.proveedorid || !form.precio) {
        error.value = 'Nombre, Proveedor y Precio son obligatorios';
        console.log(form);
        return;
    }

    saving.value = true;
    try {
        let saved: Producto;
        if (form.id) {
            saved = await updateProducto(form.id, {
                ident: form.ident!, nombre: form.nombre, descripcion: form.descripcion,
                proveedorid: form.proveedorid!, precio: form.precio!, fecha: form.fecha
            });
            message.value = 'Producto actualizado';
        } else {
            saved = await createProducto({
                ident: form.ident!, nombre: form.nombre, descripcion: form.descripcion,
                proveedorid: form.proveedorid!, precio: form.precio!, fecha: form.fecha
            });
            message.value = 'Producto creado';
            form.id = saved.id;
            selectedId.value = saved.id;
            await loadList();
        }

        if (form.cantidadInicial != null && form.proveedorid && form.ident) {
            await setStock(form.ident, Number(form.cantidadInicial), Number(form.proveedorid));
        }

        await loadList();
    } catch (e: any) {
        if (e?.response?.status === 422) {
            const errs = e.response.data?.errors || {};
            error.value = Object.values(errs).flat().join(' & ');
        } else {
            error.value = e?.response?.data?.message || 'Error guardando';
        }
    } finally {
        saving.value = false;
    }
}
async function remove() {
    if (!form.id) return;
    if (!confirm('¿Eliminar producto?')) return;
    saving.value = true; error.value = ''; message.value = '';
    try {
        await deleteProducto(form.id);
        message.value = 'Producto eliminado';
        resetForm();
        await loadList();
    } catch (e: any) {
        error.value = e?.response?.data?.message || 'No se pudo eliminar';
    } finally {
        saving.value = false;
    }
}

/* ---------- CSV Upload ---------- */
const csvFile = ref<File | null>(null);
const downloadingTemplate = ref(false);
const downloadingCSV = ref(false);
async function uploadCSV() {
    if (!csvFile.value) return;
    saving.value = true; error.value = ''; message.value = '';
    try {
        await bulkUploadCSV(csvFile.value);
        message.value = 'Carga masiva enviada';
        await loadList();
    } catch (e: any) {
        error.value = e?.response?.data?.message || 'Error en carga masiva';
    } finally {
        saving.value = false; csvFile.value = null;
    }
}

async function downloadTemplate() {
    downloadingTemplate.value = true;
    error.value = '';
    try {
        const res = await http.get('/productos/bulk-template', { responseType: 'blob' });
        const blob = new Blob([res.data], { type: res.headers['content-type'] || 'text/csv' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        const disposition = res.headers['content-disposition'];
        let filename = 'productos_template.csv';
        if (disposition) {
            const match = disposition.match(/filename="?([^"]+)"?/);
            if (match?.[1]) filename = match[1];
        }
        link.href = url;
        link.setAttribute('download', filename);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
        message.value = 'Plantilla descargada';
    } catch (e: any) {
        error.value = e?.response?.data?.message || 'No se pudo descargar la plantilla';
    } finally {
        downloadingTemplate.value = false;
    }
}

async function downloadProductosCSV() {
    downloadingCSV.value = true;
    error.value = '';
    try {
        const response = await exportProductos(buildListParams());
        const contentType = response.headers['content-type'] || 'text/csv';
        const blob = new Blob([response.data], { type: contentType });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        const disposition = response.headers['content-disposition'];
        let filename = 'productos_export.csv';
        if (typeof disposition === 'string') {
            const match = disposition.match(/filename="?([^"]+)"?/i);
            if (match?.[1]) filename = match[1];
        }
        link.href = url;
        link.setAttribute('download', filename);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
        message.value = 'Exportación de productos generada';
    } catch (e: any) {
        error.value = e?.response?.data?.message || 'No se pudo exportar los productos.';
    } finally {
        downloadingCSV.value = false;
    }
}

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

/* ---------- barcode preview (svg) ---------- */

watch(q, () => {
    pagination.page = 1;
    loadList();
});
watch(existenciaFilter, () => {
    pagination.page = 1;
    loadList();
});
watch(sortField, () => {
    pagination.page = 1;
    loadList();
});
watch(sortDirection, () => {
    pagination.page = 1;
    loadList();
});
watch(() => pagination.perPage, (newVal, oldVal) => {
    if (newVal === oldVal) return;
    pagination.page = 1;
    loadList();
});
watch(() => pagination.page, (newVal, oldVal) => {
    if (newVal === oldVal) return;
    loadList();
});
watch(() => form.ident, () => { renderBarcode(); });

onMounted(async () => {
    resetForm();
    await loadProveedores();
    await loadList();
    renderBarcode();
});
</script>

<template>
    <AppLayout>
        <div class="space-y-8">
        <div class="bg-white border border-gray-200 rounded-xl shadow p-6">
            <h2 class="text-2xl font-semibold mb-6">Crear producto</h2>

            <!-- Alerts -->
            <div v-if="message"
                class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-700 px-4 py-2 text-sm">
                {{ message }}
            </div>
            <div v-if="error" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 text-rose-700 px-4 py-2 text-sm">
                {{ error }}
            </div>

            <!-- ===== FORM (full width) ===== -->
            <div class="space-y-5">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Identificador + barcode -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Identificador interno</label>
                        <div class="flex gap-2">
                            <input v-model.number="form.ident" type="number" inputmode="numeric"
                                class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2"
                                placeholder="6 dígitos" />
                            <button type="button" @click="form.ident = randIdent()"
                                class="shrink-0 rounded-lg border px-3 py-2 text-sm hover:bg-gray-50">
                                Generar
                            </button>
                        </div>
                        <div class="mt-3 border rounded-lg p-3 bg-white flex items-center justify-center">
                            <svg ref="barcodeRef"></svg>
                        </div>
                    </div>

                    <!-- Proveedor -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Proveedor</label>
                        <select v-model.number="form.proveedorid"
                            class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2">
                            <option :value="null" disabled>Selecciona proveedor</option>
                            <option v-for="p in proveedores" :key="p.ident" :value="p.ident">{{ p.nombre }}</option>
                        </select>
                    </div>

                    <!-- Nombre -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nombre de producto</label>
                        <input v-model="form.nombre" type="text"
                            class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2"
                            placeholder="Nombre del producto" />
                    </div>

                    <!-- Fecha -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Fecha de creación</label>
                        <input v-model="form.fecha" type="date"
                            class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2" />
                    </div>

                    <!-- Precio -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Precio de venta</label>
                        <input v-model.number="form.precio" type="number" step="0.01" min="0"
                            class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2"
                            placeholder="Precio unitario" />
                    </div>

                    <!-- Cantidad inicial -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Cantidad inicial</label>
                        <input v-model.number="form.cantidadInicial" type="number" step="1" min="0"
                            class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2"
                            placeholder="Cantidad inicial" />
                    </div>
                </div>

                <!-- Descripción -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Descripción</label>
                    <input v-model="form.descripcion" type="text"
                        class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2"
                        placeholder="Descripción del producto" />
                </div>

                <!-- Opciones etiqueta (mock for now) -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <!-- Grid preset -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Distribución por hoja</label>
                        <select v-model="gridPreset"
                            class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2">
                            <option value="5x5">5 × 5 (25 etiquetas/hoja)</option>
                            <option value="6x6">6 × 6 (36 etiquetas/hoja)</option>
                            <option value="7x7">7 × 7 (49 etiquetas/hoja)</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">
                            Hoja Carta. Calculamos el tamaño de la etiqueta según filas/columnas.
                        </p>
                    </div>

                    <!-- Incluir precio -->
                    <div class="flex items-center">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input v-model="includePrice" type="checkbox"
                                class="rounded border-gray-300 text-gray-900 focus:ring-gray-900">
                            Incluir precio
                        </label>
                    </div>

                    <!-- Copias -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Copias</label>
                        <input v-model.number="copies" type="number" min="1"
                            class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2"
                            placeholder="Cantidad de etiquetas" />
                        <p class="text-xs text-gray-500 mt-1">
                            Número de <b>páginas</b> a imprimir. Cada página imprime {{ gridPreset.split('x')[0] }}×{{
                                gridPreset.split('x')[1] }}
                            etiquetas ({{ Number(gridPreset.split('x')[0]) * Number(gridPreset.split('x')[1]) }} por
                            página).
                        </p>
                    </div>
                </div>
                <!-- Botón imprimir -->
                <div class="mt-3">
                    <button type="button" @click="printLabels"
                        class="rounded-lg bg-gray-100 px-4 py-2 text-sm hover:bg-gray-50">
                        Imprimir etiquetas (PDF)
                    </button>
                </div>
            </div>

            <!-- ===== ACTIONS ===== -->
            <div class="mt-6 flex flex-wrap gap-2">
                <button :disabled="saving" @click="submitCreateOrUpdate"
                    class="rounded-lg bg-[#E4007C] hover:bg-[#cc006f] text-white px-4 py-2 text-sm disabled:opacity-60">
                    {{ form.id ? 'Actualizar producto' : 'Crear producto' }}
                </button>
                <button :disabled="!form.id || saving" @click="remove"
                    class="rounded-lg bg-rose-500 hover:bg-rose-600 text-white px-4 py-2 text-sm disabled:opacity-60">
                    Eliminar producto
                </button>
            </div>

            <!-- ===== SEARCH / TABLE (moved below the form) ===== -->
            <div class="mt-10 space-y-4">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Buscar / Consultar</label>
                        <input v-model="q" type="text" placeholder="Nombre, descripción, código…"
                            class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Existencia</label>
                        <select v-model="existenciaFilter"
                            class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2">
                            <option v-for="opt in existenciaOptions" :key="opt.value" :value="opt.value">
                                {{ opt.label }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Ordenar por</label>
                        <select v-model="sortField"
                            class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2">
                            <option v-for="opt in sortOptions" :key="opt.value" :value="opt.value">
                                {{ opt.label }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Dirección</label>
                        <select v-model="sortDirection"
                            class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2">
                            <option v-for="opt in directionOptions" :key="opt.value" :value="opt.value">
                                {{ opt.label }}
                            </option>
                        </select>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="button" @click="downloadProductosCSV"
                        :disabled="downloadingCSV || loading"
                        class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60">
                        <svg v-if="downloadingCSV" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                            class="h-4 w-4 animate-spin text-gray-500" aria-hidden="true">
                            <path fill="currentColor"
                                d="M12 2a1 1 0 0 1 1 1v2.05a1 1 0 0 1-2 0V3a1 1 0 0 1 1-1Zm6.36 3.64a1 1 0 0 1 0 1.41l-1.45 1.45a1 1 0 1 1-1.41-1.41l1.45-1.45a1 1 0 0 1 1.41 0ZM21 11a1 1 0 1 1 0 2h-2.05a1 1 0 0 1 0-2H21ZM8.5 6.09a1 1 0 0 1-1.41 1.41L5.64 6.05A1 1 0 1 1 7.05 4.64L8.5 6.09ZM7 11a1 1 0 0 1 1 1H7Zm1 0a1 1 0 0 1 2 0H8Zm2 0a1 1 0 0 1 2 0h-2Zm2 0a1 1 0 0 1 2 0h-2Zm2 0a1 1 0 0 1 2 0h-2Z" />
                        </svg>
                        <span>{{ downloadingCSV ? 'Generando CSV…' : 'Exportar CSV' }}</span>
                    </button>
                </div>

                <div class="space-y-3 md:hidden">
                    <div
                        v-if="loading"
                        class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-4 text-sm text-gray-600"
                    >
                        Cargando…
                    </div>
                    <div
                        v-else-if="!productos.length"
                        class="rounded-lg border border-gray-200 bg-white px-4 py-4 text-sm text-gray-500"
                    >
                        Sin resultados
                    </div>
                    <template v-else>
                        <article
                            v-for="p in productos"
                            :key="p.id"
                            class="space-y-2 rounded-lg border border-gray-200 bg-white p-4 text-sm shadow-sm"
                            :class="selectedId === p.id ? 'ring-2 ring-gray-300' : ''"
                            @click="selectRow(p)"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p class="text-xs uppercase text-gray-500">Identificador</p>
                                    <p class="font-semibold text-gray-900">{{ p.ident }}</p>
                                </div>
                                <div class="text-right text-xs text-gray-500">
                                    ID interno: <span class="font-medium text-gray-800">{{ p.id }}</span>
                                </div>
                            </div>
                            <div>
                                <p class="text-xs uppercase text-gray-500">Nombre</p>
                                <p class="font-medium text-gray-900">{{ p.nombre }}</p>
                            </div>
                            <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-gray-600">
                                <span>
                                    Proveedor:
                                    <template v-if="p.proveedor?.nombre">
                                        <b class="text-gray-800">{{ p.proveedor.nombre }}</b>
                                    </template>
                                    <template v-else>
                                        <b class="text-rose-600">No proveedor definido</b>
                                    </template>
                                </span>
                                <span>Precio: <b class="text-gray-900">{{ displayMoney(p.precio) }}</b></span>
                                <span>Existencia: <b class="text-gray-900">{{ p.existencia ?? 0 }}</b></span>
                            </div>
                        </article>
                    </template>
                </div>
                <div class="max-h-96 overflow-auto border border-gray-200 rounded-lg hidden md:block">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-500">
                            <tr>
                                <th class="text-left font-medium px-3 py-2">ID</th>
                                <th class="text-left font-medium px-3 py-2">Ident</th>
                                <th class="text-left font-medium px-3 py-2">Nombre</th>
                                <th class="text-left font-medium px-3 py-2">Proveedor</th>
                                <th class="text-right font-medium px-3 py-2">Existencia</th>
                                <th class="text-right font-medium px-3 py-2">Precio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in productos" :key="p.id" @click="selectRow(p)"
                                :class="['cursor-pointer hover:bg-gray-50', selectedId === p.id ? 'bg-gray-100' : '']">
                                <td class="px-3 py-2">{{ p.id }}</td>
                                <td class="px-3 py-2">{{ p.ident }}</td>
                                <td class="px-3 py-2">{{ p.nombre }}</td>
                                <td class="px-3 py-2">
                                    <span v-if="p.proveedor?.nombre">{{ p.proveedor.nombre }}</span>
                                    <span v-else class="text-rose-600 font-medium">No proveedor definido</span>
                                </td>
                                <td class="px-3 py-2 text-right">{{ p.existencia ?? 0 }}</td>
                                <td class="px-3 py-2 text-right">{{ displayMoney(p.precio) }}</td>
                            </tr>
                            <tr v-if="!loading && productos.length === 0">
                                <td colspan="6" class="px-3 py-3 text-center text-gray-500">Sin resultados</td>
                            </tr>
                            <tr v-if="loading">
                                <td colspan="6" class="px-3 py-3 text-center text-gray-500">Cargando…</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    class="flex flex-col gap-3 py-3 text-sm text-gray-600 md:flex-row md:items-center md:justify-between">
                    <div class="flex flex-wrap items-center gap-2">
                        <span>Filas por página:</span>
                        <select v-model.number="pagination.perPage"
                            class="rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-gray-900 focus:ring-gray-900">
                            <option v-for="option in [10, 20, 50, 100]" :key="option" :value="option">{{ option }}</option>
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
            </div>

            <!-- ===== CSV UPLOAD ===== -->
            <div class="mt-8 space-y-3">
                <label class="block text-sm font-medium text-gray-700">Subir archivo de productos (CSV)</label>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <span class="text-xs text-gray-500">
                        Descarga la plantilla, captura tus productos y súbela como CSV.
                    </span>
                    <button type="button" @click="downloadTemplate" :disabled="downloadingTemplate"
                        class="inline-flex items-center justify-center rounded-lg border px-2.5 py-2 text-sm hover:bg-gray-50 disabled:opacity-60 transition"
                        aria-label="Descargar plantilla CSV">
                        <svg v-if="!downloadingTemplate" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                            class="h-4 w-4 text-gray-700">
                            <path fill="currentColor"
                                d="M10 2a.75.75 0 0 1 .75.75v8.69l2.47-2.47a.75.75 0 0 1 1.06 1.06l-3.75 3.75a.75.75 0 0 1-1.06 0L6.72 10.03a.75.75 0 1 1 1.06-1.06l2.47 2.47V2.75A.75.75 0 0 1 10 2ZM4.5 12.5a.75.75 0 0 1 .75.75v1.5c0 .69.56 1.25 1.25 1.25h7c.69 0 1.25-.56 1.25-1.25v-1.5a.75.75 0 0 1 1.5 0v1.5A2.75 2.75 0 0 1 13.5 17h-7A2.75 2.75 0 0 1 3.75 14.75v-1.5a.75.75 0 0 1 .75-.75Z" />
                        </svg>
                        <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                            class="h-4 w-4 animate-spin text-gray-500" aria-hidden="true">
                            <path fill="currentColor"
                                d="M12 2a1 1 0 0 1 1 1v2.05a1 1 0 0 1-2 0V3a1 1 0 0 1 1-1Zm6.36 3.64a1 1 0 0 1 0 1.41l-1.45 1.45a1 1 0 1 1-1.41-1.41l1.45-1.45a1 1 0 0 1 1.41 0ZM21 11a1 1 0 1 1 0 2h-2.05a1 1 0 0 1 0-2H21ZM8.5 6.09a1 1 0 0 1-1.41 1.41L5.64 6.05A1 1 0 1 1 7.05 4.64L8.5 6.09ZM7 11a1 1 0 0 1 1 1H7Zm1 0a1 1 0 0 1 2 0H8Zm2 0a1 1 0 0 1 2 0h-2Zm2 0a1 1 0 0 1 2 0h-2Zm2 0a1 1 0 0 1 2 0h-2Z" />
                        </svg>
                        <span class="sr-only">{{ downloadingTemplate ? 'Descargando plantilla CSV' : 'Descargar plantilla CSV' }}</span>
                    </button>
                </div>
                <input type="file" accept=".csv"
                    @change="e => csvFile = (e.target as HTMLInputElement).files?.[0] || null"
                    class="block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border file:border-gray-300 file:bg-white file:px-3 file:py-2 file:text-sm hover:file:bg-gray-50" />
                <button :disabled="!csvFile || saving" @click="uploadCSV"
                    class="rounded-lg border px-3 py-2 text-sm hover:bg-gray-50 disabled:opacity-60">
                    Subir archivo
                </button>
            </div>
        </div>
        </div>
    </AppLayout>
</template>
