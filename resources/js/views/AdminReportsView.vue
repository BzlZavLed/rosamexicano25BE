<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AppLayout from '../components/layout/AppLayout.vue';
import {
    getProductosReport,
    getInventarioReport,
    getCajaReport,
    getEntradasReport,
    getCajaProveedoresReport,
    type CajaReportResponse,
    type CajaReportVenta,
    type ProductosReportResponse,
    type ProductoRow,
    type ProductosPagination,
    type InventarioReportResponse,
    type InventarioRow,
    type EntradasReportResponse,
    type CajaProveedoresResponse,
    type CajaProveedorGroup,
} from '../api/reports';

function formatCurrency(value: number | string | null | undefined): string {
    const num = typeof value === 'string' ? Number(value) : value;
    if (!Number.isFinite(num)) return '--';
    return Number(num).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' });
}

type ReportType =
    | 'caja'
    | 'productos'
    | 'inventario'
    | 'entradas'
    | 'caja-condensado';

const options: Array<{ value: ReportType; label: string }> = [
    { value: 'caja', label: 'Caja' },
    { value: 'productos', label: 'Productos' },
    { value: 'inventario', label: 'Inventario' },
    { value: 'entradas', label: 'Entradas' },
    { value: 'caja-condensado', label: 'Caja condensado' }
];

type InventarioSort = 'producto' | 'existencia' | 'proveedor';
type SortDirection = 'asc' | 'desc';

const inventarioSortOptions: Array<{ value: InventarioSort; label: string }> = [
    { value: 'producto', label: 'Producto' },
    { value: 'existencia', label: 'Existencia' },
    { value: 'proveedor', label: 'Proveedor' },
];

const directionOptions: Array<{ value: SortDirection; label: string }> = [
    { value: 'asc', label: 'Ascendente' },
    { value: 'desc', label: 'Descendente' },
];

const selected = ref<ReportType>('caja');
const rangeStart = ref('');
const rangeEnd = ref('');

const cajaLoading = ref(false);
const cajaError = ref('');
const cajaData = ref<CajaReportResponse | null>(null);
const cajaSearch = ref('');
const cajaDisplayLimit = ref(200);

const entradasLoading = ref(false);
const entradasError = ref('');
const entradasData = ref<EntradasReportResponse | null>(null);

const cajaCondensadoLoading = ref(false);
const cajaCondensadoError = ref('');
const cajaCondensadoData = ref<CajaProveedoresResponse | null>(null);
const expandedProveedores = ref<Set<number>>(new Set());


const reportHeader = computed(() => {
    switch (selected.value) {
        case 'caja':
            return 'Reporte de caja';
        case 'productos':
            return 'Reporte de productos';
        case 'inventario':
            return 'Reporte de inventario';
        case 'entradas':
            return 'Reporte de entradas';
        case 'caja-condensado':
            return 'Reporte de caja condensado';
        default:
            return 'Reporte';
    }
});

const tableClasses = {
    wrapper: 'overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm',
    table: 'min-w-full text-xs',
    head: 'bg-gray-50 text-left text-[11px] uppercase tracking-wide text-gray-500',
    body: 'divide-y divide-gray-100',
    row: 'bg-white text-gray-800',
    emptyRow: 'px-3 py-6 text-center text-gray-500',
} as const;

const todayIso = () => new Date().toISOString().slice(0, 10);

function normalizeDateParam(value: string | null | undefined): string {
    const fallback = todayIso();
    if (!value) return fallback;
    const trimmed = value.trim();
    if (!trimmed) return fallback;
    if (/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) {
        const [y, m, d] = trimmed.split('-');
        if (y && m && d) {
            return `${d}/${m}/${y.slice(-2)}`;
        }
        return fallback;
    }
    if (/^\d{2}\/\d{2}\/\d{2,4}$/.test(trimmed)) {
        const parts = trimmed.split('/');
        if (parts[2] && parts[2].length === 2) return trimmed;
        return `${parts[0]}/${parts[1]}/${parts[2] ? parts[2].slice(-2) : ''}`;
    }
    const parsed = new Date(trimmed);
    if (!Number.isNaN(parsed.getTime())) {
        const day = String(parsed.getDate()).padStart(2, '0');
        const month = String(parsed.getMonth() + 1).padStart(2, '0');
        const year = String(parsed.getFullYear()).slice(-2);
        return `${day}/${month}/${year}`;
    }
    return fallback;
}

async function fetchCajaReport(download = false) {
    if (selected.value !== 'caja') return;
    cajaError.value = '';

    if (!rangeStart.value) {
        cajaError.value = 'Selecciona al menos la fecha inicial.';
        return;
    }

    const from = normalizeDateParam(rangeStart.value);
    const to = rangeEnd.value ? normalizeDateParam(rangeEnd.value) : from;

    cajaLoading.value = true;
    try {
        if (download) {
            const result = await getCajaReport({ from_date: from, to_date: to, download });
            if (result instanceof Blob) {
                const filename = `reporte-caja-${from}--${to}.csv`;
                const url = URL.createObjectURL(result);
                const link = document.createElement('a');
                link.href = url;
                link.download = filename;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);
            } else {
                cajaError.value = 'La respuesta del reporte no es válida para descarga.';
            }
        } else {
            const data = await getCajaReport({ from_date: from, to_date: to });
            if (data instanceof Blob) {
                cajaError.value = 'La respuesta del reporte no es válida.';
                cajaData.value = null;
            } else {
                cajaData.value = data;
                cajaDisplayLimit.value = 200;
                cajaSearch.value = '';
                expandedVentaIds.value = new Set();
            }
        }
    } catch (err: any) {
        cajaError.value = err?.response?.data?.message || err?.message || 'No se pudo generar el reporte.';
    } finally {
        cajaLoading.value = false;
    }
}

async function fetchEntradasReport() {
    if (selected.value !== 'entradas') return;
    entradasError.value = '';

    if (!rangeStart.value) {
        entradasError.value = 'Selecciona la fecha inicial.';
        return;
    }

    const from = normalizeDateParam(rangeStart.value);
    const to = rangeEnd.value ? normalizeDateParam(rangeEnd.value) : from;

    entradasLoading.value = true;
    try {
        const data = await getEntradasReport({ from_date: from, to_date: to });
        entradasData.value = data;
    } catch (err: any) {
        entradasError.value = err?.response?.data?.message || err?.message || 'No se pudo cargar el reporte de entradas.';
        entradasData.value = null;
    } finally {
        entradasLoading.value = false;
    }
}

async function fetchCajaCondensadoReport() {
    if (selected.value !== 'caja-condensado') return;
    cajaCondensadoError.value = '';

    if (!rangeStart.value) {
        cajaCondensadoError.value = 'Selecciona la fecha inicial.';
        return;
    }

    const from = normalizeDateParam(rangeStart.value);
    const to = rangeEnd.value ? normalizeDateParam(rangeEnd.value) : undefined;

    cajaCondensadoLoading.value = true;
    try {
        const response = await getCajaProveedoresReport({ from_date: from, to_date: to });
        if (response instanceof Blob) {
            cajaCondensadoError.value = 'La respuesta del reporte no es válida.';
            cajaCondensadoData.value = null;
        } else {
            cajaCondensadoData.value = response as CajaProveedoresResponse;
            expandedProveedores.value = new Set();
        }
    } catch (err: any) {
        cajaCondensadoError.value =
            err?.response?.data?.message || err?.message || 'No se pudo cargar el reporte condensado.';
        cajaCondensadoData.value = null;
    } finally {
        cajaCondensadoLoading.value = false;
    }
}

async function downloadCajaCondensado() {
    if (selected.value !== 'caja-condensado') return;
    cajaCondensadoError.value = '';

    if (!rangeStart.value) {
        cajaCondensadoError.value = 'Selecciona la fecha inicial.';
        return;
    }

    const from = normalizeDateParam(rangeStart.value);
    const to = rangeEnd.value ? normalizeDateParam(rangeEnd.value) : undefined;

    try {
        const blob = await getCajaProveedoresReport({ from_date: from, to_date: to, download: true });
        if (!(blob instanceof Blob)) {
            cajaCondensadoError.value = 'La respuesta del reporte no es válida para descarga.';
            return;
        }
        const filename = `reporte-caja-condensado-${from}--${to ?? from}.csv`;
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    } catch (err: any) {
        cajaCondensadoError.value = err?.response?.data?.message || err?.message || 'No se pudo descargar el reporte.';
    }
}

function toggleProveedor(proveedorId: number) {
    const current = new Set(expandedProveedores.value);
    if (current.has(proveedorId)) current.delete(proveedorId);
    else current.add(proveedorId);
    expandedProveedores.value = current;
}

type CajaFlatRow = {
    ventaId: number;
    fecha: string;
    metodo: string;
    vendedor: string;
    concepto: string;
    subtotal: number;
    descuentoGeneralPercent: number;
    descuentoGeneralAmount: number;
    descuentoLineas: number;
    descuentoTotal: number;
    tarjetaCargo: number;
    totalVenta: number;
    grossSubtotal: number;
    productoId: number;
    productoNombre: string;
    proveedorId: number;
    cantidad: number;
    precioUnitario: number;
    totalLinea: number;
    descuentoProducto: number;
    promotion?: string;
};

function computeVentaSubtotal(venta: CajaReportVenta): number {
    const subtotal = Number(venta.subtotal ?? 0);
    const totalVenta = Number(venta.totalventa ?? 0);
    const descuentoGeneral = Number(
        venta.descuento_general_amount ?? venta.descuento_general ?? 0
    );
    const descuentoLineas = Number(venta.descuento_lineas ?? 0);
    const recargo = Number(venta.tarjeta_cargo ?? 0);

    const computed = totalVenta + descuentoGeneral + descuentoLineas + recargo;

    if (!Number.isFinite(subtotal) || subtotal <= 0) return computed;
    if (subtotal < totalVenta) return computed;
    if (Math.abs(subtotal - computed) > 0.05) return computed;
    return subtotal;
}

const cajaSummary = computed(() => {
    if (!cajaData.value) return null;
    const ventas = cajaData.value.ventas ?? [];
    const totalVentas = ventas.length;
    let totalLineas = 0;
    let totalProductos = 0;
    let sumSubtotal = 0;
    let sumDescGeneral = 0;
    let sumDescLineas = 0;
    let sumRecargo = 0;
    let sumTotalVenta = 0;

    ventas.forEach((venta) => {
        const lineas = venta.lineas ?? [];
        totalLineas += lineas.length;
        totalProductos += lineas.reduce((acc, l) => acc + (l.cant ?? 0), 0);
        sumSubtotal += computeVentaSubtotal(venta);
        sumDescGeneral += Number(
            venta.descuento_general_amount ?? venta.descuento_general ?? 0
        );
        sumDescLineas += Number(venta.descuento_lineas ?? 0);
        sumRecargo += Number(venta.tarjeta_cargo ?? 0);
        sumTotalVenta += Number(venta.totalventa ?? 0);
    });

    return {
        totalVentas,
        totalLineas,
        totalProductos,
        sumSubtotal,
        sumDescGeneral,
        sumDescLineas,
        sumRecargo,
        sumTotalVenta,
    };
});

const cajaFlatRows = computed<CajaFlatRow[]>(() => {
    if (!cajaData.value) return [];
    const rows: CajaFlatRow[] = [];
    (cajaData.value.ventas ?? []).forEach((venta: CajaReportVenta) => {
        const lineas = venta.lineas ?? [];
        const grossSubtotal = computeVentaSubtotal(venta);
        lineas.forEach((linea) => {
            rows.push({
                ventaId: venta.idventa,
                fecha: venta.fecha,
                metodo: venta.metodo,
                vendedor: venta.vendedor,
                concepto: venta.concepto,
                subtotal: Number(venta.subtotal ?? 0),
                descuentoGeneralPercent: Number(venta.descuento_general_percent ?? 0),
                descuentoGeneralAmount: Number(
                    venta.descuento_general_amount ?? venta.descuento_general ?? 0
                ),
                descuentoLineas: Number(venta.descuento_lineas ?? 0),
                descuentoTotal: Number(venta.descuento_total ?? 0),
                tarjetaCargo: Number(venta.tarjeta_cargo ?? 0),
                totalVenta: Number(venta.totalventa ?? 0),
                grossSubtotal,
                productoId: linea.idprod,
                productoNombre: linea.nombre,
                proveedorId: Number(linea.proveedor ?? 0),
                cantidad: Number(linea.cant ?? 0),
                precioUnitario: Number(linea.puni ?? 0),
                totalLinea: Number(linea.total ?? 0),
                descuentoProducto: Number(linea.descuento_producto ?? linea.product_desc ?? 0),
                promotion: linea.promotion,
            });
        });
    });
    return rows;
});

const entradasSummary = computed(() => {
    if (!entradasData.value) return null;
    const rows = entradasData.value.entradas ?? [];
    const totalMovimientos = rows.length;
    const totalUnidades = rows.reduce((acc, row) => acc + Number(row.ingreal ?? 0), 0);
    return {
        totalMovimientos,
        totalUnidades,
    };
});

const cajaCondensadoResumen = computed(() => {
    if (!cajaCondensadoData.value) return null;
    const res = cajaCondensadoData.value.resumen;
    return {
        ventasBrutas: Number(res.ventas_brutas ?? 0),
        descuentos: Number(res.descuentos ?? 0),
        cargosTarjeta: Number(res.cargos_tarjeta ?? 0),
        descuentoGeneral: Number(res.descuento_general ?? 0),
        ganancias: Number(res.ganancias ?? 0),
        totalProveedores: cajaCondensadoData.value.proveedores?.length ?? 0,
    };
});

function providerItemTotals(proveedor: CajaProveedorGroup) {
    return proveedor.items.reduce(
        (acc, item) => {
            acc.cantidad += Number(item.cantidad ?? 0);
            acc.total += Number(item.total ?? 0);
            acc.descProducto += Number(item.descuento_producto ?? 0);
            acc.cargoTarjeta += Number(item.cargo_tarjeta ?? 0);
            acc.descTotal += Number(item.descuento_total ?? 0);
            acc.ganancia += Number(item.ganancia ?? 0);
            return acc;
        },
        {
            cantidad: 0,
            total: 0,
            descProducto: 0,
            cargoTarjeta: 0,
            descTotal: 0,
            ganancia: 0,
        }
    );
}

const filteredCajaRows = computed(() => {
    const search = cajaSearch.value.trim().toLowerCase();
    if (!search) return cajaFlatRows.value;
    return cajaFlatRows.value.filter((row) => {
        return (
            (row.productoNombre?.toLowerCase?.().includes(search) ?? false) ||
            (row.vendedor?.toLowerCase?.().includes(search) ?? false) ||
            (row.metodo?.toLowerCase?.().includes(search) ?? false) ||
            String(row.ventaId).includes(search) ||
            String(row.productoId).includes(search) ||
            String(row.proveedorId).includes(search)
        );
    });
});

function loadMoreCajaRows() {
    cajaDisplayLimit.value += 200;
}

const expandedVentaIds = ref<Set<number>>(new Set());

function toggleVenta(ventaId: number) {
    const current = new Set(expandedVentaIds.value);
    if (current.has(ventaId)) {
        current.delete(ventaId);
    } else {
        current.add(ventaId);
    }
    expandedVentaIds.value = current;
}

const groupedRows = computed(() => {
    const groups = new Map<number, { venta: CajaFlatRow; lineas: CajaFlatRow[] }>();
    filteredCajaRows.value.forEach((row) => {
        const entry = groups.get(row.ventaId);
        if (!entry) {
            groups.set(row.ventaId, { venta: row, lineas: [row] });
        } else {
            entry.lineas.push(row);
        }
    });
    return Array.from(groups.values());
});

const groupedVisibleRows = computed(() => {
    const limit = cajaDisplayLimit.value;
    const rows: { venta: CajaFlatRow; lineas: CajaFlatRow[] }[] = [];
    let count = 0;
    for (const group of groupedRows.value) {
        if (count >= limit) break;
        rows.push(group);
        count += group.lineas.length;
    }
    return rows;
});



// --- Productos (All Products) Report state ---
const prodLoading = ref(false);
const prodQ = ref<string>('');
const prodPage = ref<number>(1);
const prodPerPage = ref<number>(25);

const prodItems = ref<ProductoRow[]>([]);
const prodPagination = ref<ProductosPagination | null>(null);
const prodError = ref<string | null>(null);

async function loadProductos() {
    prodLoading.value = true;
    prodError.value = null;
    try {
        const res: ProductosReportResponse = await getProductosReport({
            q: prodQ.value.trim() || undefined,
            page: prodPage.value,
            per_page: prodPerPage.value,
        });
        prodItems.value = res.data;
        prodPagination.value = res.pagination;
    } catch (e: any) {
        prodError.value = e?.message || 'Error al cargar el reporte de productos.';
    } finally {
        prodLoading.value = false;
    }
}

function prodSubmitSearch() {
    prodPage.value = 1;
    loadProductos();
}

function prodGoFirst() {
    if (!prodPagination.value) return;
    if (prodPagination.value.current_page > 1) {
        prodPage.value = 1;
        loadProductos();
    }
}
function prodGoPrev() {
    if (!prodPagination.value) return;
    if (prodPagination.value.prev_page_url) {
        prodPage.value = prodPagination.value.current_page - 1;
        loadProductos();
    }
}
function prodGoNext() {
    if (!prodPagination.value) return;
    if (prodPagination.value.next_page_url) {
        prodPage.value = prodPagination.value.current_page + 1;
        loadProductos();
    }
}
function prodGoLast() {
    if (!prodPagination.value) return;
    if (prodPagination.value.current_page < prodPagination.value.last_page) {
        prodPage.value = prodPagination.value.last_page;
        loadProductos();
    }
}

// --- Inventario Report state ---
const inventarioLoading = ref(false);
const inventarioQ = ref<string>('');
const inventarioPage = ref<number>(1);
const inventarioPerPage = ref<number>(25);
const inventarioSort = ref<InventarioSort>('producto');
const inventarioDirection = ref<SortDirection>('asc');

const inventarioItems = ref<InventarioRow[]>([]);
const inventarioPagination = ref<ProductosPagination | null>(null);
const inventarioError = ref<string | null>(null);

async function loadInventario() {
    inventarioLoading.value = true;
    inventarioError.value = null;
    try {
        const res: InventarioReportResponse = await getInventarioReport({
            q: inventarioQ.value.trim() || undefined,
            page: inventarioPage.value,
            per_page: inventarioPerPage.value,
            sort: inventarioSort.value,
            direction: inventarioDirection.value,
        });
        inventarioItems.value = res.data;
        inventarioPagination.value = res.pagination;
    } catch (e: any) {
        inventarioError.value = e?.message || 'Error al cargar el reporte de inventario.';
    } finally {
        inventarioLoading.value = false;
    }
}

function inventarioSubmitSearch() {
    inventarioPage.value = 1;
    loadInventario();
}

function inventarioGoFirst() {
    if (!inventarioPagination.value) return;
    if (inventarioPagination.value.current_page > 1) {
        inventarioPage.value = 1;
        loadInventario();
    }
}
function inventarioGoPrev() {
    if (!inventarioPagination.value) return;
    if (inventarioPagination.value.prev_page_url) {
        inventarioPage.value = inventarioPagination.value.current_page - 1;
        loadInventario();
    }
}
function inventarioGoNext() {
    if (!inventarioPagination.value) return;
    if (inventarioPagination.value.next_page_url) {
        inventarioPage.value = inventarioPagination.value.current_page + 1;
        loadInventario();
    }
}
function inventarioGoLast() {
    if (!inventarioPagination.value) return;
    if (inventarioPagination.value.current_page < inventarioPagination.value.last_page) {
        inventarioPage.value = inventarioPagination.value.last_page;
        loadInventario();
    }
}

// When switching between tabular reports, auto-load as needed
watch(
    () => selected.value,
    (val) => {
        if (val === 'productos' && prodItems.value.length === 0 && !prodLoading.value) {
            loadProductos();
        }
        if (val === 'inventario' && inventarioItems.value.length === 0 && !inventarioLoading.value) {
            loadInventario();
        }
        if (val === 'entradas') {
            entradasError.value = '';
            if (!entradasData.value && rangeStart.value) {
                fetchEntradasReport();
            }
        }
        if (val === 'caja-condensado') {
            cajaCondensadoError.value = '';
            if (!cajaCondensadoData.value && rangeStart.value) {
                fetchCajaCondensadoReport();
            }
        }
    },
    { immediate: false }
);

// Reload on per-page change
watch(prodPerPage, () => {
    prodPage.value = 1;
    if (selected.value === 'productos') loadProductos();
});
watch(inventarioPerPage, () => {
    inventarioPage.value = 1;
    if (selected.value === 'inventario') loadInventario();
});

// Refresh when sorting preferences change
watch(
    () => [inventarioSort.value, inventarioDirection.value],
    () => {
        inventarioPage.value = 1;
        if (selected.value === 'inventario') loadInventario();
    }
);

watch(
    () => [rangeStart.value, rangeEnd.value],
    () => {
        if (selected.value === 'entradas') {
            entradasData.value = null;
            entradasError.value = '';
        }
        if (selected.value === 'caja-condensado') {
            cajaCondensadoData.value = null;
            cajaCondensadoError.value = '';
            expandedProveedores.value = new Set();
        }
    }
);






</script>

<template>
    <AppLayout>
        <div class="space-y-6">
            <header class="space-y-1">
                <h1 class="text-xl font-semibold text-gray-900">Reportes</h1>
                <p class="text-sm text-gray-500">
                    Selecciona un tipo de reporte y completa los criterios necesarios.
                </p>
            </header>

            <section class="space-y-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <label class="flex flex-col text-sm text-gray-600">
                        <span class="font-medium text-gray-700">Tipo de reporte</span>
                        <select v-model="selected"
                            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900">
                            <option v-for="option in options" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </label>
                    <label class="flex flex-col text-sm text-gray-600" v-if="['caja', 'entradas','caja-condensado'].includes(selected)">
                        <span class="font-medium text-gray-700">Fecha inicial</span>
                        <input v-model="rangeStart" type="date"
                            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900" />
                    </label>
                    <label class="flex flex-col text-sm text-gray-600" v-if="['caja', 'entradas','caja-condensado'].includes(selected)">
                        <span class="font-medium text-gray-700">Fecha final <span
                                class="text-xs text-gray-400">(opcional)</span></span>
                        <input v-model="rangeEnd" type="date"
                            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900" />
                    </label>
                </div>

                <div class="rounded-2xl border border-dashed border-gray-300 p-6 text-sm text-gray-600">
                    <template v-if="selected === 'caja'">
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button"
                                class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:bg-gray-300"
                                :disabled="cajaLoading" @click="fetchCajaReport()">
                                <span v-if="cajaLoading">Consultando…</span>
                                <span v-else>Consultar reporte</span>
                            </button>
                            <button type="button"
                                class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:text-gray-400"
                                :disabled="cajaLoading" @click="fetchCajaReport(true)">
                                Descargar CSV
                            </button>
                        </div>
                        <p v-if="cajaError"
                            class="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                            {{ cajaError }}
                        </p>
                        <div v-else class="mt-4 space-y-4">
                            <div v-if="cajaLoading" class="text-xs text-gray-500">Cargando datos…</div>
                            <div v-else-if="cajaData" class="space-y-4">
                                <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-gray-500">
                                    <div>
                                        Periodo:
                                        <span class="font-semibold text-gray-900">{{ cajaData.from_date }}</span>
                                        –
                                        <span class="font-semibold text-gray-900">{{ cajaData.to_date }}</span>
                                    </div>
                                    <div v-if="cajaSummary"
                                        class="grid grid-cols-2 gap-3 text-[11px] text-gray-500 sm:grid-cols-5">
                                        <div><span class="block font-semibold text-gray-900">{{ cajaSummary.totalVentas
                                                }}</span><span>Ventas</span></div>
                                        <div><span class="block font-semibold text-gray-900">{{
                                                formatCurrency(cajaSummary.sumSubtotal) }}</span><span>Subtotal</span>
                                        </div>
                                        <div><span class="block font-semibold text-gray-900">{{
                                                formatCurrency(cajaSummary.sumDescGeneral + cajaSummary.sumDescLineas)
                                                }}</span><span>Descuentos</span></div>
                                        <div><span class="block font-semibold text-gray-900">{{
                                                formatCurrency(cajaSummary.sumTotalVenta) }}</span><span>Total
                                                neto</span></div>
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-xs text-gray-500">
                                        Registros: <span class="font-semibold text-gray-900">{{ filteredCajaRows.length
                                            }}</span>
                                    </p>
                                    <input v-model="cajaSearch" type="search"
                                        placeholder="Buscar producto, vendedor, método…"
                                        class="w-full max-w-xs rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900" />
                                </div>

                                <div :class="tableClasses.wrapper">
                                    <table :class="tableClasses.table">
                                        <thead :class="tableClasses.head">
                                            <tr>
                                                <th class="px-3 py-2"></th>
                                                <th class="px-3 py-2">Venta ID</th>
                                                <th class="px-3 py-2">Fecha</th>
                                                <th class="px-3 py-2">Método</th>
                                                <th class="px-3 py-2">Vendedor</th>
                                                <th class="px-3 py-2">Concepto</th>
                                                <th class="px-3 py-2 text-right">Subtotal</th>
                                                <th class="px-3 py-2 text-right">Desc. general</th>
                                                <th class="px-3 py-2 text-right">Desc. líneas*</th>
                                                <th class="px-3 py-2 text-right">Recargo tarjeta*</th>
                                                <th class="px-3 py-2 text-right">Total venta</th>
                                            </tr>
                                        </thead>
                                        <tbody :class="tableClasses.body">
                                            <template v-for="group in groupedVisibleRows" :key="group.venta.ventaId">
                                                <tr :class="tableClasses.row">
                                                    <td class="px-3 py-2">
                                                        <button type="button"
                                                            class="inline-flex h-5 w-5 items-center justify-center rounded border border-gray-300 text-xs hover:bg-gray-50"
                                                            @click="toggleVenta(group.venta.ventaId)">
                                                            {{ expandedVentaIds.has(group.venta.ventaId) ? '-' : '+' }}
                                                        </button>
                                                    </td>
                                                    <td class="px-3 py-2 font-semibold text-gray-900">{{
                                                        group.venta.ventaId }}</td>
                                                    <td class="px-3 py-2">{{ group.venta.fecha }}</td>
                                                    <td class="px-3 py-2 capitalize">{{ group.venta.metodo }}</td>
                                                    <td class="px-3 py-2">{{ group.venta.vendedor }}</td>
                                                    <td class="px-3 py-2">{{ group.venta.concepto }}</td>
                                                    <td class="px-3 py-2 text-right">{{
                                                        formatCurrency(group.venta.grossSubtotal) }}</td>
                                                    <td class="px-3 py-2 text-right">{{
                                                        formatCurrency(group.venta.descuentoGeneralAmount) }}</td>
                                                    <td class="px-3 py-2 text-right">{{
                                                        formatCurrency(group.venta.descuentoLineas) }}</td>
                                                    <td class="px-3 py-2 text-right">{{
                                                        formatCurrency(group.venta.tarjetaCargo) }}</td>
                                                    <td class="px-3 py-2 text-right font-semibold text-gray-900">{{
                                                        formatCurrency(group.venta.totalVenta) }}</td>
                                                </tr>
                                                <template v-if="expandedVentaIds.has(group.venta.ventaId)">
                                                    <tr class="bg-gray-50 text-[11px] text-gray-500">
                                                        <th class="px-3 py-2"></th>
                                                        <th class="px-3 py-2">Prod. ID</th>
                                                        <th class="px-3 py-2" colspan="2">Producto</th>
                                                        <th class="px-3 py-2">Proveedor</th>
                                                        <th class="px-3 py-2 text-right">Cant.</th>
                                                        <th class="px-3 py-2 text-right">P. unitario</th>
                                                        <th class="px-3 py-2 text-right">Total línea</th>
                                                        <th class="px-3 py-2 text-right">Desc. línea</th>
                                                        <th class="px-3 py-2" colspan="2">Promoción</th>
                                                    </tr>
                                                    <tr v-for="linea in group.lineas"
                                                        :key="`${group.venta.ventaId}-${linea.productoId}`"
                                                        class="text-gray-700">
                                                        <td class="px-3 py-2"></td>
                                                        <td class="px-3 py-2 text-gray-900">{{ linea.productoId }}</td>
                                                        <td class="px-3 py-2" colspan="2">{{ linea.productoNombre }}
                                                        </td>
                                                        <td class="px-3 py-2">{{ linea.proveedorId }}</td>
                                                        <td class="px-3 py-2 text-right">{{ linea.cantidad }}</td>
                                                        <td class="px-3 py-2 text-right">{{
                                                            formatCurrency(linea.precioUnitario) }}</td>
                                                        <td class="px-3 py-2 text-right">{{
                                                            formatCurrency(linea.totalLinea) }}</td>
                                                        <td class="px-3 py-2 text-right">{{
                                                            formatCurrency(linea.descuentoProducto) }}</td>
                                                        <td class="px-3 py-2" colspan="2">{{ linea.promotion || '—' }}
                                                        </td>
                                                    </tr>
                                                </template>
                                            </template>
                                        </tbody>
                                        <tfoot v-if="cajaSummary"
                                            class="bg-gray-100 text-[11px] uppercase tracking-wide text-gray-600">
                                            <tr>
                                                <td class="px-3 py-2" colspan="6">Totales</td>
                                                <td class="px-3 py-2 text-right">{{
                                                    formatCurrency(cajaSummary.sumSubtotal) }}</td>
                                                <td class="px-3 py-2 text-right">{{
                                                    formatCurrency(cajaSummary.sumDescGeneral) }}</td>
                                                <td class="px-3 py-2 text-right">{{
                                                    formatCurrency(cajaSummary.sumDescLineas) }}</td>
                                                <td class="px-3 py-2 text-right">{{
                                                    formatCurrency(cajaSummary.sumRecargo) }}</td>
                                                <td class="px-3 py-2 text-right font-semibold text-gray-900">{{
                                                    formatCurrency(cajaSummary.sumTotalVenta) }}</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                                <div v-if="filteredCajaRows.length > cajaDisplayLimit" class="flex justify-center">
                                    <button type="button"
                                        class="rounded-lg border border-gray-300 px-4 py-2 text-xs text-gray-700 hover:bg-gray-50"
                                        @click="loadMoreCajaRows">
                                        Cargar más resultados
                                    </button>
                                </div>
                            </div>
                            <div v-else class="text-xs text-gray-500">
                                Ingresa un rango de fechas y presiona "Consultar" para ver el reporte.
                            </div>
                        </div>
                    </template>
                    <template v-else-if="selected === 'productos'">
                        <div class="space-y-4">
                            <p class="font-medium text-gray-900">{{ reportHeader }}</p>

                            <!-- Controls -->
                            <div class="flex flex-wrap items-end gap-3">
                                <div class="flex-1 min-w-[220px]">
                                    <label class="block text-sm font-medium mb-1">Buscar</label>
                                    <input v-model="prodQ" type="text" placeholder="Buscar por producto o proveedor…"
                                        class="w-full border rounded px-3 py-2" @keyup.enter="prodSubmitSearch" />
                                </div>

                                <div>
                                    <label class="block text-sm font-medium mb-1">Filas por página</label>
                                    <select v-model.number="prodPerPage" class="border rounded px-3 py-2">
                                        <option :value="10">10</option>
                                        <option :value="25">25</option>
                                        <option :value="50">50</option>
                                        <option :value="100">100</option>
                                    </select>
                                </div>

                                <button class="border rounded px-4 py-2" @click="prodSubmitSearch"
                                    :disabled="prodLoading">
                                    Buscar
                                </button>
                            </div>

                            <!-- Alerts/States -->
                            <div v-if="prodError" class="text-red-600">
                                {{ prodError }}
                            </div>

                            <div v-if="prodLoading" class="text-sm text-gray-500">
                                Cargando productos…
                            </div>

                            <!-- Table -->
                            <div v-else>
                                <div :class="tableClasses.wrapper">
                                    <table :class="tableClasses.table">
                                        <thead :class="tableClasses.head">
                                            <tr>
                                                <th class="px-3 py-2">ID</th>
                                                <th class="px-3 py-2">Ident</th>
                                                <th class="px-3 py-2">Nombre</th>
                                                <th class="px-3 py-2 text-right">Precio</th>
                                                <th class="px-3 py-2">Proveedor</th>
                                            </tr>
                                        </thead>

                                        <tbody :class="tableClasses.body">
                                            <tr v-for="p in prodItems" :key="p.id" :class="tableClasses.row">
                                                <td class="px-3 py-2 text-gray-900">{{ p.id }}</td>
                                                <td class="px-3 py-2">{{ p.ident }}</td>
                                                <td class="px-3 py-2">{{ p.nombre }}</td>

                                                <!-- Use your existing formatter if available; fallback shown -->
                                                <td class="px-3 py-2 text-right">
                                                    <span v-if="p.precio !== null">
                                                        {{ typeof formatCurrency === 'function'
                                                            ? formatCurrency(Number(p.precio))
                                                            : Number(p.precio).toLocaleString('es-MX', { style: 'currency',
                                                        currency: 'MXN' })
                                                        }}
                                                    </span>
                                                    <span v-else>—</span>
                                                </td>

                                                <td class="px-3 py-2">
                                                    <template v-if="p.proveedor">
                                                        <div class="font-medium text-gray-900">{{ p.proveedor.nombre }}
                                                        </div>
                                                        <div class="text-[11px] text-gray-500">{{ p.proveedor.ident }}
                                                        </div>
                                                    </template>
                                                    <template v-else>—</template>
                                                </td>
                                            </tr>

                                            <tr v-if="prodItems.length === 0">
                                                <td colspan="5" :class="tableClasses.emptyRow">
                                                    No se encontraron productos.
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Pagination -->
                                <div v-if="prodPagination"
                                    class="mt-3 flex flex-wrap items-center justify-between gap-3">
                                    <div class="text-[11px] uppercase tracking-wide text-gray-600">
                                        Página {{ prodPagination.current_page }} de {{ prodPagination.last_page }} •
                                        Mostrando {{ prodPagination.count }} de {{ prodPagination.total }}
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <button
                                            class="inline-flex items-center justify-center rounded border border-gray-300 px-3 py-1 text-xs hover:bg-gray-50"
                                            @click="prodGoFirst" :disabled="prodPagination.current_page === 1">
                                            Primero
                                        </button>
                                        <button
                                            class="inline-flex items-center justify-center rounded border border-gray-300 px-3 py-1 text-xs hover:bg-gray-50"
                                            @click="prodGoPrev" :disabled="!prodPagination.prev_page_url">
                                            Anterior
                                        </button>
                                        <button
                                            class="inline-flex items-center justify-center rounded border border-gray-300 px-3 py-1 text-xs hover:bg-gray-50"
                                            @click="prodGoNext" :disabled="!prodPagination.next_page_url">
                                            Siguiente
                                        </button>
                                        <button
                                            class="inline-flex items-center justify-center rounded border border-gray-300 px-3 py-1 text-xs hover:bg-gray-50"
                                            @click="prodGoLast"
                                            :disabled="prodPagination.current_page === prodPagination.last_page">
                                            Último
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>

                    <template v-else-if="selected === 'caja-condensado'">
                        <div class="space-y-4">
                            <p class="font-medium text-gray-900">{{ reportHeader }}</p>

                            <div class="flex flex-wrap items-center gap-2 text-sm">
                                <button type="button"
                                    class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:bg-gray-300"
                                    :disabled="cajaCondensadoLoading" @click="fetchCajaCondensadoReport">
                                    <span v-if="cajaCondensadoLoading">Consultando…</span>
                                    <span v-else>Consultar resumen</span>
                                </button>
                                <button type="button"
                                    class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:text-gray-400"
                                    :disabled="cajaCondensadoLoading" @click="downloadCajaCondensado">
                                    Descargar CSV
                                </button>
                                <span class="text-xs text-gray-500">Resumen por proveedor de ventas en el periodo seleccionado.</span>
                            </div>

                            <div v-if="cajaCondensadoError"
                                class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                                {{ cajaCondensadoError }}
                            </div>

                            <div v-else>
                                <div v-if="cajaCondensadoLoading" class="text-xs text-gray-500">Cargando datos…</div>
                                <div v-else-if="cajaCondensadoData" class="space-y-4">
                                    <div class="flex flex-wrap items-start justify-between gap-3 text-xs text-gray-500">
                                        <div class="space-x-1">
                                            <span>Periodo:</span>
                                            <span class="font-semibold text-gray-900">{{ cajaCondensadoData.from_date }}</span>
                                            <span>–</span>
                                            <span class="font-semibold text-gray-900">{{ cajaCondensadoData.to_date }}</span>
                                        </div>
                                        <div v-if="cajaCondensadoResumen" class="flex flex-wrap gap-4 text-[11px] text-gray-500">
                                            <div>
                                                <span class="block font-semibold text-gray-900">{{ formatCurrency(cajaCondensadoResumen.ventasBrutas) }}</span>
                                                <span>Ventas brutas</span>
                                            </div>
                                            <div>
                                                <span class="block font-semibold text-gray-900">{{ formatCurrency(cajaCondensadoResumen.descuentos) }}</span>
                                                <span>Descuentos</span>
                                            </div>
                                            <div>
                                                <span class="block font-semibold text-gray-900">{{ formatCurrency(cajaCondensadoResumen.cargosTarjeta) }}</span>
                                                <span>Cargos tarjeta</span>
                                            </div>
                                            <div>
                                                <span class="block font-semibold text-gray-900">{{ formatCurrency(cajaCondensadoResumen.descuentoGeneral) }}</span>
                                                <span>Desc. general</span>
                                            </div>
                                            <div>
                                                <span class="block font-semibold text-gray-900">{{ formatCurrency(cajaCondensadoResumen.ganancias) }}</span>
                                                <span>Ganancias</span>
                                            </div>
                                            <div>
                                                <span class="block font-semibold text-gray-900">{{ cajaCondensadoResumen.totalProveedores }}</span>
                                                <span>Proveedores</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div :class="tableClasses.wrapper" class="hidden md:block">
                                        <table :class="tableClasses.table">
                                            <thead :class="tableClasses.head">
                                                <tr>
                                                    <th class="px-3 py-2"></th>
                                                    <th class="px-3 py-2">Proveedor</th>
                                                    <th class="px-3 py-2">Ident</th>
                                                    <th class="px-3 py-2 text-right">Ventas brutas</th>
                                                    <th class="px-3 py-2 text-right">Descuentos</th>
                                                    <th class="px-3 py-2 text-right">Cargos tarjeta</th>
                                                    <th class="px-3 py-2 text-right">Ganancia</th>
                                                </tr>
                                            </thead>
                                            <tbody :class="tableClasses.body">
                                                <template v-for="proveedor in cajaCondensadoData.proveedores" :key="proveedor.proveedor_id">
                                                    <tr :class="tableClasses.row">
                                                        <td class="px-3 py-2">
                                                            <button type="button"
                                                                class="inline-flex h-5 w-5 items-center justify-center rounded border border-gray-300 text-xs hover:bg-gray-50"
                                                                @click="toggleProveedor(proveedor.proveedor_id)">
                                                                {{ expandedProveedores.has(proveedor.proveedor_id) ? '-' : '+' }}
                                                            </button>
                                                        </td>
                                                        <td class="px-3 py-2 font-semibold text-gray-900">{{ proveedor.proveedor_nombre }}</td>
                                                        <td class="px-3 py-2">{{ proveedor.proveedor_ident }}</td>
                                                        <td class="px-3 py-2 text-right">{{ formatCurrency(proveedor.ventas_brutas) }}</td>
                                                    <td class="px-3 py-2 text-right">{{ formatCurrency(proveedor.descuentos) }}</td>
                                                    <td class="px-3 py-2 text-right">{{ formatCurrency(proveedor.cargos_tarjeta) }}</td>
                                                    <td class="px-3 py-2 text-right">{{ formatCurrency(proveedor.ganancia_total) }}</td>
                                                </tr>
                                                <tr v-if="expandedProveedores.has(proveedor.proveedor_id) && proveedor.items.length > 0" class="bg-gray-50">
                                                    <td class="px-3 py-0.5"></td>
                                                    <td class="px-3 py-0.5 text-[10px] text-gray-500" colspan="6">
                                                        * <span class="text-rose-600">Desc. total</span> = Desc. producto + Cargo tarjeta
                                                    </td>
                                                </tr>
                                                    <tr v-if="expandedProveedores.has(proveedor.proveedor_id)" class="bg-gray-50 text-[11px] text-gray-600">
                                                        <td class="px-3 py-2"></td>
                                                        <td class="px-3 py-2" colspan="6">
                                                            <div :class="tableClasses.wrapper">
                                                                <table :class="tableClasses.table">
                                                                    <thead :class="tableClasses.head">
                                                                        <tr>
                                                                            <th class="px-3 py-2">Fecha</th>
                                                                            <th class="px-3 py-2">Producto</th>
                                                                            <th class="px-3 py-2">Ident</th>
                                                                            <th class="px-3 py-2 text-right">Cantidad</th>
                                                                            <th class="px-3 py-2 text-right">Precio unitario</th>
                                                                            <th class="px-3 py-2 text-right">Total</th>
                                                                            <th class="px-3 py-2 text-right">Desc. producto</th>
                                                                            <th class="px-3 py-2 text-right">Cargo tarjeta</th>
                                                                            <th class="px-3 py-2 text-right">Desc. total</th>
                                                                            <th class="px-3 py-2 text-right">Ganancia</th>
                                                                            <th class="px-3 py-2">Método</th>
                                                                            <th class="px-3 py-2">Vendedor</th>
                                                                            <th class="px-3 py-2">Promoción</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody :class="tableClasses.body">
                                                                        <tr v-for="item in proveedor.items" :key="item.ventadesg_id">
                                                                            <td class="px-3 py-2">{{ item.fecha }}</td>
                                                                            <td class="px-3 py-2">{{ item.producto_nombre }}</td>
                                                                            <td class="px-3 py-2">{{ item.producto_ident }}</td>
                                                                            <td class="px-3 py-2 text-right">{{ item.cantidad }}</td>
                                                                            <td class="px-3 py-2 text-right">{{ formatCurrency(item.precio_unitario) }}</td>
                                                                            <td class="px-3 py-2 text-right">{{ formatCurrency(item.total) }}</td>
                                                                            <td class="px-3 py-2 text-right">{{ formatCurrency(item.descuento_producto) }}</td>
                                                                            <td class="px-3 py-2 text-right">{{ formatCurrency(item.cargo_tarjeta) }}</td>
                                                                            <td class="px-3 py-2 text-right">
                                                                                <div class="font-semibold text-rose-600">{{ formatCurrency(item.descuento_total) }}</div>
                                                                                <div class="text-[10px] text-gray-500">
                                                                                    {{ formatCurrency(item.descuento_producto) }} + {{ formatCurrency(item.cargo_tarjeta) }}
                                                                                </div>
                                                                            </td>
                                                                            <td class="px-3 py-2 text-right">{{ formatCurrency(item.ganancia) }}</td>
                                                                            <td class="px-3 py-2 capitalize">{{ item.metodo }}</td>
                                                                            <td class="px-3 py-2">{{ item.vendedor }}</td>
                                                                            <td class="px-3 py-2">{{ item.promotion || '—' }}</td>
                                                                        </tr>
                                                                        <tr v-if="proveedor.items.length === 0">
                                                                            <td colspan="13" :class="tableClasses.emptyRow">
                                                                                Sin detalles de ventas para este proveedor.
                                                                            </td>
                                                                        </tr>
                                                                    </tbody>
                                                                    <tfoot v-if="proveedor.items.length > 0" class="bg-gray-100 text-[11px] uppercase tracking-wide text-gray-600">
                                                                        <tr>
                                                                            <td class="px-3 py-2" colspan="3">Totales</td>
                                                                            <td class="px-3 py-2 text-right">{{ providerItemTotals(proveedor).cantidad }}</td>
                                                                            <td class="px-3 py-2"></td>
                                                                            <td class="px-3 py-2 text-right">{{ formatCurrency(providerItemTotals(proveedor).total) }}</td>
                                                                            <td class="px-3 py-2 text-right">{{ formatCurrency(providerItemTotals(proveedor).descProducto) }}</td>
                                                                            <td class="px-3 py-2 text-right">{{ formatCurrency(providerItemTotals(proveedor).cargoTarjeta) }}</td>
                                                                            <td class="px-3 py-2 text-right">{{ formatCurrency(providerItemTotals(proveedor).descTotal) }}</td>
                                                                            <td class="px-3 py-2 text-right">{{ formatCurrency(providerItemTotals(proveedor).ganancia) }}</td>
                                                                            <td class="px-3 py-2" colspan="3"></td>
                                                                        </tr>
                                                                    </tfoot>
                                                                </table>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </template>
                                                <tr v-if="(cajaCondensadoData.proveedores?.length ?? 0) === 0">
                                                    <td colspan="7" :class="tableClasses.emptyRow">
                                                        No se encontraron proveedores en el periodo seleccionado.
                                                    </td>
                                                </tr>
                                            </tbody>
                                            <tfoot v-if="cajaCondensadoResumen" class="bg-gray-100 text-[11px] uppercase tracking-wide text-gray-600">
                                                <tr>
                                                    <td class="px-3 py-2" colspan="3">Totales</td>
                                                    <td class="px-3 py-2 text-right">{{ formatCurrency(cajaCondensadoResumen.ventasBrutas) }}</td>
                                                    <td class="px-3 py-2 text-right">{{ formatCurrency(cajaCondensadoResumen.descuentos) }}</td>
                                                    <td class="px-3 py-2 text-right">{{ formatCurrency(cajaCondensadoResumen.cargosTarjeta) }}</td>
                                                    <td class="px-3 py-2 text-right">{{ formatCurrency(cajaCondensadoResumen.ganancias) }}</td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                    <div class="space-y-3 md:hidden">
                                        <article v-for="proveedor in cajaCondensadoData.proveedores" :key="proveedor.proveedor_id"
                                            class="space-y-3 rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
                                            <div class="flex items-start justify-between gap-2">
                                                <div>
                                                    <h3 class="text-base font-semibold text-gray-900">{{ proveedor.proveedor_nombre }}</h3>
                                                    <p class="text-xs text-gray-500">Ident {{ proveedor.proveedor_ident }}</p>
                                                </div>
                                                <button type="button"
                                                    class="inline-flex items-center justify-center rounded border border-gray-300 px-2 py-1 text-xs hover:bg-gray-50"
                                                    @click="toggleProveedor(proveedor.proveedor_id)">
                                                    {{ expandedProveedores.has(proveedor.proveedor_id) ? 'Ocultar' : 'Ver detalles' }}
                                                </button>
                                            </div>
                                            <div class="grid grid-cols-2 gap-2 text-xs text-gray-600">
                                                <div>
                                                    <span class="block font-semibold text-gray-900">{{ formatCurrency(proveedor.ventas_brutas) }}</span>
                                                    <span>Ventas brutas</span>
                                                </div>
                                                <div>
                                                    <span class="block font-semibold text-gray-900">{{ formatCurrency(proveedor.descuentos) }}</span>
                                                    <span>Descuentos</span>
                                                </div>
                                                <div>
                                                    <span class="block font-semibold text-gray-900">{{ formatCurrency(proveedor.cargos_tarjeta) }}</span>
                                                    <span>Cargos tarjeta</span>
                                                </div>
                                                <div>
                                                    <span class="block font-semibold text-gray-900">{{ formatCurrency(proveedor.ganancia_total) }}</span>
                                                    <span>Ganancia</span>
                                                </div>
                                            </div>
                                            <div v-if="expandedProveedores.has(proveedor.proveedor_id)" class="space-y-2">
                                                <p class="text-[10px] text-gray-500">
                                                    * <span class="text-rose-600 font-semibold">Desc. total</span> = Desc. producto + Cargo tarjeta
                                                </p>
                                                <div class="space-y-2">
                                                    <article v-for="item in proveedor.items" :key="item.ventadesg_id"
                                                        class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-[11px] text-gray-600">
                                                        <div class="flex items-center justify-between">
                                                            <span class="font-semibold text-gray-900">{{ item.producto_nombre }}</span>
                                                            <span>{{ item.fecha }}</span>
                                                        </div>
                                                        <div class="mt-2 grid gap-1">
                                                            <div><span class="font-medium text-gray-700">Ident:</span> {{ item.producto_ident }}</div>
                                                            <div class="flex justify-between">
                                                                <span><span class="font-medium text-gray-700">Cantidad:</span> {{ item.cantidad }}</span>
                                                                <span><span class="font-medium text-gray-700">Total:</span> {{ formatCurrency(item.total) }}</span>
                                                            </div>
                                                            <div class="flex justify-between">
                                                                <span><span class="font-medium text-gray-700">Desc. prod:</span> {{ formatCurrency(item.descuento_producto) }}</span>
                                                                <span><span class="font-medium text-gray-700">Cargo tarjeta:</span> {{ formatCurrency(item.cargo_tarjeta) }}</span>
                                                            </div>
                                                            <div>
                                                                <span class="font-medium text-gray-700">Desc. total:</span>
                                                                <span class="font-semibold text-rose-600">{{ formatCurrency(item.descuento_total) }}</span>
                                                                <span class="text-[10px] text-gray-500 ml-1">({{ formatCurrency(item.descuento_producto) }} + {{ formatCurrency(item.cargo_tarjeta) }})</span>
                                                            </div>
                                                            <div class="flex justify-between">
                                                                <span><span class="font-medium text-gray-700">Ganancia:</span> {{ formatCurrency(item.ganancia) }}</span>
                                                                <span><span class="font-medium text-gray-700">Método:</span> {{ item.metodo }}</span>
                                                            </div>
                                                            <div class="flex justify-between">
                                                                <span><span class="font-medium text-gray-700">Vendedor:</span> {{ item.vendedor }}</span>
                                                                <span><span class="font-medium text-gray-700">Promoción:</span> {{ item.promotion || '—' }}</span>
                                                            </div>
                                                        </div>
                                                    </article>
                                                    <div v-if="proveedor.items.length === 0"
                                                        class="rounded-lg border border-dashed border-gray-200 bg-white px-3 py-4 text-center text-xs text-gray-500">
                                                        Sin detalles de ventas para este proveedor.
                                                    </div>
                                                </div>
                                                <div v-if="proveedor.items.length > 0"
                                                    class="rounded-lg border border-gray-200 bg-white p-3 text-[11px] text-gray-600">
                                                    <div class="flex justify-between">
                                                        <span class="font-semibold text-gray-900">Totales</span>
                                                        <span>{{ providerItemTotals(proveedor).cantidad }} uds</span>
                                                    </div>
                                                    <div class="mt-2 grid grid-cols-2 gap-1">
                                                        <div>Ventas: {{ formatCurrency(providerItemTotals(proveedor).total) }}</div>
                                                        <div>Desc. prod: {{ formatCurrency(providerItemTotals(proveedor).descProducto) }}</div>
                                                        <div>Tarjeta: {{ formatCurrency(providerItemTotals(proveedor).cargoTarjeta) }}</div>
                                                        <div>Desc. total: {{ formatCurrency(providerItemTotals(proveedor).descTotal) }}</div>
                                                        <div>Ganancia: {{ formatCurrency(providerItemTotals(proveedor).ganancia) }}</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </article>
                                        <div v-if="(cajaCondensadoData.proveedores?.length ?? 0) === 0"
                                            class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-4 text-center text-xs text-gray-500">
                                            No se encontraron proveedores en el periodo seleccionado.
                                        </div>
                                    </div>
                                </div>
                                <div v-else class="text-xs text-gray-500">
                                    Selecciona un rango de fechas y presiona «Consultar resumen».
                                </div>
                            </div>
                        </div>
                    </template>

                    <template v-else-if="selected === 'entradas'">
                        <div class="space-y-4">
                            <p class="font-medium text-gray-900">{{ reportHeader }}</p>

                            <div class="flex flex-wrap items-center gap-2 text-sm">
                                <button type="button"
                                    class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:bg-gray-300"
                                    :disabled="entradasLoading" @click="fetchEntradasReport">
                                    <span v-if="entradasLoading">Consultando…</span>
                                    <span v-else>Consultar entradas</span>
                                </button>
                                <span class="text-xs text-gray-500">Utiliza el rango de fechas para obtener los movimientos de entrada.</span>
                            </div>

                            <div v-if="entradasError"
                                class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                                {{ entradasError }}
                            </div>

                            <div v-else>
                                <div v-if="entradasLoading" class="text-xs text-gray-500">Cargando datos…</div>
                                <div v-else-if="entradasData" class="space-y-4">
                                    <div class="flex flex-wrap items-start justify-between gap-3 text-xs text-gray-500">
                                        <div class="space-x-1">
                                            <span>Periodo:</span>
                                            <span class="font-semibold text-gray-900">{{ entradasData.from_date }}</span>
                                            <span>–</span>
                                            <span class="font-semibold text-gray-900">{{ entradasData.to_date }}</span>
                                        </div>
                                        <div v-if="entradasSummary" class="flex flex-wrap gap-4 text-[11px] text-gray-500">
                                            <div>
                                                <span class="block font-semibold text-gray-900">{{ entradasSummary.totalMovimientos }}</span>
                                                <span>Movimientos</span>
                                            </div>
                                            <div>
                                                <span class="block font-semibold text-gray-900">{{ entradasSummary.totalUnidades }}</span>
                                                <span>Unidades</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div :class="tableClasses.wrapper">
                                        <table :class="tableClasses.table">
                                            <thead :class="tableClasses.head">
                                                <tr>
                                                    <th class="px-3 py-2">Fecha</th>
                                                    <th class="px-3 py-2">Producto</th>
                                                    <th class="px-3 py-2">Ident</th>
                                                    <th class="px-3 py-2 text-right">Cantidad</th>
                                                    <th class="px-3 py-2">Proveedor</th>
                                                    <th class="px-3 py-2">Acción</th>
                                                    <th class="px-3 py-2">Usuario</th>
                                                </tr>
                                            </thead>
                                            <tbody :class="tableClasses.body">
                                                <tr v-for="(row, idx) in entradasData.entradas" :key="`${row.prodid}-${row.fecha_iso}-${idx}`"
                                                    :class="tableClasses.row">
                                                    <td class="px-3 py-2">{{ row.fecha }}</td>
                                                    <td class="px-3 py-2">{{ row.prodnombre }}</td>
                                                    <td class="px-3 py-2">{{ row.prodid }}</td>
                                                    <td class="px-3 py-2 text-right">{{ row.ingreal }}</td>
                                                    <td class="px-3 py-2">{{ row.proveedor_nombre || '—' }}</td>
                                                    <td class="px-3 py-2 uppercase">{{ row.accion }}</td>
                                                    <td class="px-3 py-2">{{ row.usuario || '—' }}</td>
                                                </tr>
                                                <tr v-if="entradasData.entradas.length === 0">
                                                    <td colspan="7" :class="tableClasses.emptyRow">
                                                        No se registraron entradas en el periodo seleccionado.
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div v-else class="text-xs text-gray-500">
                                    Selecciona un rango de fechas y presiona «Consultar entradas».
                                </div>
                            </div>
                        </div>
                    </template>


                    <template v-else-if="selected === 'inventario'">
                        <div class="space-y-4">
                            <p class="font-medium text-gray-900">{{ reportHeader }}</p>

                            <!-- Controls -->
                            <div class="flex flex-wrap items-end gap-3">
                                <div class="flex-1 min-w-[220px]">
                                    <label class="mb-1 block text-sm font-medium">Buscar</label>
                                    <input v-model="inventarioQ" type="text"
                                        placeholder="Buscar por producto o proveedor…"
                                        class="w-full rounded border px-3 py-2"
                                        @keyup.enter="inventarioSubmitSearch" />
                                </div>

                                <div>
                                    <label class="mb-1 block text-sm font-medium">Filas por página</label>
                                    <select v-model.number="inventarioPerPage" class="rounded border px-3 py-2">
                                        <option :value="10">10</option>
                                        <option :value="25">25</option>
                                        <option :value="50">50</option>
                                        <option :value="100">100</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="mb-1 block text-sm font-medium">Ordenar por</label>
                                    <select v-model="inventarioSort" class="rounded border px-3 py-2">
                                        <option v-for="opt in inventarioSortOptions" :key="opt.value"
                                            :value="opt.value">
                                            {{ opt.label }}
                                        </option>
                                    </select>
                                </div>

                                <div>
                                    <label class="mb-1 block text-sm font-medium">Dirección</label>
                                    <select v-model="inventarioDirection" class="rounded border px-3 py-2">
                                        <option v-for="opt in directionOptions" :key="opt.value" :value="opt.value">
                                            {{ opt.label }}
                                        </option>
                                    </select>
                                </div>

                                <button class="rounded border px-4 py-2" @click="inventarioSubmitSearch"
                                    :disabled="inventarioLoading">
                                    Buscar
                                </button>
                            </div>

                            <!-- Alerts/States -->
                            <div v-if="inventarioError" class="text-red-600">
                                {{ inventarioError }}
                            </div>

                            <div v-if="inventarioLoading" class="text-sm text-gray-500">
                                Cargando inventario…
                            </div>

                            <!-- Table -->
                            <div v-else>
                                <div :class="tableClasses.wrapper">
                                    <table :class="tableClasses.table">
                                        <thead :class="tableClasses.head">
                                            <tr>
                                                <th class="px-3 py-2">Inventario ID</th>
                                                <th class="px-3 py-2">Ident</th>
                                                <th class="px-3 py-2">Producto</th>
                                                <th class="px-3 py-2 text-right">Precio</th>
                                                <th class="px-3 py-2 text-right">Existencia</th>
                                                <th class="px-3 py-2 text-right">Costo total</th>
                                                <th class="px-3 py-2">Proveedor</th>
                                            </tr>
                                        </thead>
                                        <tbody :class="tableClasses.body">
                                            <tr v-for="item in inventarioItems" :key="item.inventario_id"
                                                :class="tableClasses.row">
                                                <td class="px-3 py-2 text-gray-900">{{ item.inventario_id }}</td>
                                                <td class="px-3 py-2">{{ item.producto_ident }}</td>
                                                <td class="px-3 py-2">{{ item.producto_nombre }}</td>
                                                <td class="px-3 py-2 text-right">
                                                    <span v-if="item.precio !== null">
                                                        {{ formatCurrency(item.precio) }}
                                                    </span>
                                                    <span v-else>—</span>
                                                </td>
                                                <td class="px-3 py-2 text-right">
                                                    {{ item.existencia }}
                                                </td>
                                                <td class="px-3 py-2 text-right">
                                                    {{ formatCurrency(item.costo_inventario) }}
                                                </td>
                                                <td class="px-3 py-2">
                                                    <template v-if="item.proveedor">
                                                        <div class="font-medium text-gray-900">{{ item.proveedor.nombre }}
                                                        </div>
                                                        <div class="text-[11px] text-gray-500">{{ item.proveedor.ident }}
                                                        </div>
                                                    </template>
                                                    <template v-else>—</template>
                                                </td>
                                            </tr>

                                            <tr v-if="inventarioItems.length === 0">
                                                <td colspan="7" :class="tableClasses.emptyRow">
                                                    No se encontraron registros.
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div v-if="inventarioPagination"
                                    class="mt-3 flex flex-wrap items-center justify-between gap-3">
                                    <div class="text-[11px] uppercase tracking-wide text-gray-600">
                                        Página {{ inventarioPagination.current_page }} de {{ inventarioPagination.last_page
                                        }} • Mostrando {{ inventarioPagination.count }} de {{
                                        inventarioPagination.total }}
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <button
                                            class="inline-flex items-center justify-center rounded border border-gray-300 px-3 py-1 text-xs hover:bg-gray-50"
                                            @click="inventarioGoFirst"
                                            :disabled="inventarioPagination.current_page === 1">
                                            Primero
                                        </button>
                                        <button
                                            class="inline-flex items-center justify-center rounded border border-gray-300 px-3 py-1 text-xs hover:bg-gray-50"
                                            @click="inventarioGoPrev"
                                            :disabled="!inventarioPagination.prev_page_url">
                                            Anterior
                                        </button>
                                        <button
                                            class="inline-flex items-center justify-center rounded border border-gray-300 px-3 py-1 text-xs hover:bg-gray-50"
                                            @click="inventarioGoNext"
                                            :disabled="!inventarioPagination.next_page_url">
                                            Siguiente
                                        </button>
                                        <button
                                            class="inline-flex items-center justify-center rounded border border-gray-300 px-3 py-1 text-xs hover:bg-gray-50"
                                            @click="inventarioGoLast"
                                            :disabled="inventarioPagination.current_page === inventarioPagination.last_page">
                                            Último
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
