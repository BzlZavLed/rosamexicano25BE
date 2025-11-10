<script setup lang="ts">
import { computed, ref, watch, onBeforeUnmount } from 'vue';
import AppLayout from '../components/layout/AppLayout.vue';
import {
    getProductosReport,
    getInventarioReport,
    getCajaReport,
    getEntradasReport,
    getCajaProveedoresReport,
    getEgresosCajaReport,
    getMensualidadReport,
    type CajaReportResponse,
    type CajaReportVenta,
    type EgresosCajaReportResponse,
    type MensualidadReportResponse,
    type MensualidadReportItem,
    type ProductosReportResponse,
    type ProductoRow,
    type ProductosPagination,
    type InventarioReportResponse,
    type InventarioRow,
    type EntradasReportResponse,
    type CajaProveedoresResponse,
    type CajaProveedorGroup,
    type CajaProveedorItem,
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
    | 'caja-condensado'
    | 'caja-egresos'
    | 'mensualidad';

type MensualidadSortableColumn =
    | 'proveedor'
    | 'concepto'
    | 'importe'
    | 'cantidad_pago'
    | 'restante'
    | 'status'
    | 'fecha_cobro'
    | 'payment_date';
type ProveedorModalSort =
    | 'fecha'
    | 'producto'
    | 'ident'
    | 'cantidad'
    | 'precio'
    | 'total'
    | 'desc_producto'
    | 'cargo_tarjeta'
    | 'desc_total'
    | 'ganancia'
    | 'metodo'
    | 'vendedor'
    | 'promocion';

const options: Array<{ value: ReportType; label: string }> = [
    { value: 'caja', label: 'Caja' },
    { value: 'productos', label: 'Productos' },
    { value: 'inventario', label: 'Inventario' },
    { value: 'entradas', label: 'Entradas' },
    { value: 'caja-condensado', label: 'Caja condensado' },
    { value: 'caja-egresos', label: 'Egresos de caja' },
    { value: 'mensualidad', label: 'Mensualidad' },
];

type InventarioSort = 'producto' | 'existencia' | 'proveedor';
type SortDirection = 'asc' | 'desc';
type MensualidadStatusFilter = 'todos' | 'pendiente' | 'pagado';

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
const cajaCondensadoView = ref<'cards' | 'table'>('table');
const proveedorModalOpen = ref(false);
const proveedorModalData = ref<CajaProveedorGroup | null>(null);
const proveedorModalSort = ref<ProveedorModalSort>('fecha');
const proveedorModalDirection = ref<SortDirection>('asc');
const proveedorModalSearch = ref('');

const egresosLoading = ref(false);
const egresosError = ref('');
const egresosData = ref<EgresosCajaReportResponse | null>(null);

const mensualidadMonth = ref('');
const mensualidadStatus = ref<MensualidadStatusFilter>('todos');
const mensualidadLoading = ref(false);
const mensualidadError = ref('');
const mensualidadData = ref<MensualidadReportResponse | null>(null);
const mensualidadSearch = ref('');
const mensualidadSort = ref<MensualidadSortableColumn>('proveedor');
const mensualidadSortDirection = ref<SortDirection>('asc');

mensualidadMonth.value = new Date().toISOString().slice(0, 7);
const mensualidadStatusMap: Record<string, string> = {
    pending: 'Pendiente',
    paid: 'Pagado',
};

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
        case 'caja-egresos':
            return 'Reporte de egresos de caja';
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

async function fetchEgresosCajaReport() {
    if (selected.value !== 'caja-egresos') return;
    egresosError.value = '';

    if (!rangeStart.value) {
        egresosError.value = 'Selecciona la fecha inicial.';
        return;
    }

    const from = normalizeDateParam(rangeStart.value);
    const to = rangeEnd.value ? normalizeDateParam(rangeEnd.value) : from;

    egresosLoading.value = true;
    try {
        const response = await getEgresosCajaReport({ from_date: from, to_date: to });
        if (response instanceof Blob) {
            egresosError.value = 'La respuesta del reporte no es válida.';
            egresosData.value = null;
        } else {
            egresosData.value = response;
        }
    } catch (err: any) {
        egresosError.value = err?.response?.data?.message || err?.message || 'No se pudo cargar el reporte de egresos.';
        egresosData.value = null;
    } finally {
        egresosLoading.value = false;
    }
}

async function downloadEgresosCajaReport() {
    if (selected.value !== 'caja-egresos') return;
    egresosError.value = '';

    if (!rangeStart.value) {
        egresosError.value = 'Selecciona la fecha inicial.';
        return;
    }

    const from = normalizeDateParam(rangeStart.value);
    const to = rangeEnd.value ? normalizeDateParam(rangeEnd.value) : from;

    try {
        const blob = await getEgresosCajaReport({ from_date: from, to_date: to, download: true });
        if (!(blob instanceof Blob)) {
            egresosError.value = 'La respuesta del reporte no es válida para descarga.';
            return;
        }
        const filename = `reporte-egresos-caja-${from}--${to}.csv`;
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    } catch (err: any) {
        egresosError.value = err?.response?.data?.message || err?.message || 'No se pudo descargar el reporte.';
    }
}

function normalizeMesCobro(value: string): string | null {
    if (!value) return null;
    const trimmed = value.trim();
    if (/^\d{4}-\d{2}$/.test(trimmed)) {
        return trimmed;
    }
    const parsed = new Date(trimmed);
    if (Number.isNaN(parsed.getTime())) return null;
    return `${parsed.getFullYear()}-${String(parsed.getMonth() + 1).padStart(2, '0')}`;
}

async function fetchMensualidadReport(download = false) {
    if (selected.value !== 'mensualidad') return;
    mensualidadError.value = '';

    if (!mensualidadMonth.value) {
        mensualidadError.value = 'Selecciona el mes de cobro.';
        return;
    }

    const mes = normalizeMesCobro(mensualidadMonth.value);
    if (!mes) {
        mensualidadError.value = 'Mes inválido, usa el formato YYYY-MM.';
        return;
    }

    const statusParam =
        mensualidadStatus.value === 'todos'
            ? 'all'
            : mensualidadStatus.value === 'pendiente'
                ? 'pending'
                : 'paid';

    if (download) {
        try {
            const blob = await getMensualidadReport({
                mes_cobro: mes,
                status: statusParam,
                download: true,
            });
            if (!(blob instanceof Blob)) {
                mensualidadError.value = 'La respuesta del reporte no es válida para descarga.';
                return;
            }
            const filename = `reporte-mensualidad-${mes}.csv`;
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        } catch (err: any) {
            mensualidadError.value =
                err?.response?.data?.message || err?.message || 'No se pudo descargar el reporte de mensualidad.';
        }
        return;
    }

    mensualidadLoading.value = true;
    try {
        const data = await getMensualidadReport({
            mes_cobro: mes,
            status: statusParam,
        });
        if (data instanceof Blob) {
            mensualidadError.value = 'La respuesta del reporte no es válida.';
            mensualidadData.value = null;
        } else {
            mensualidadData.value = data;
            mensualidadSearch.value = '';
        }
    } catch (err: any) {
        mensualidadError.value =
            err?.response?.data?.message || err?.message || 'No se pudo cargar el reporte de mensualidad.';
        mensualidadData.value = null;
    } finally {
        mensualidadLoading.value = false;
    }
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

const egresosSummary = computed(() => {
    if (!egresosData.value) return null;
    const resumen = egresosData.value.summary ?? {
        ingresos_total: 0,
        egresos_total: 0,
        saldo: 0,
    };
    return {
        movimientos: egresosData.value.egresos?.length ?? 0,
        ingresos: Number(resumen.ingresos_total ?? 0),
        egresos: Number(resumen.egresos_total ?? 0),
        saldo: Number(resumen.saldo ?? 0),
    };
});

const mensualidadSummary = computed(() => {
    if (!mensualidadData.value) return null;
    const resumen = mensualidadData.value.summary;
    return {
        totalCobros: Number(resumen?.total_cobros ?? 0),
        importeTotal: Number(resumen?.importe_total ?? 0),
        pagadoTotal: Number(resumen?.pagado_total ?? 0),
        restanteTotal: Number(resumen?.restante_total ?? 0),
        pagosCompletos: Number(resumen?.pagos_completos ?? 0),
    };
});

const filteredMensualidadItems = computed<MensualidadReportItem[]>(() => {
    if (!mensualidadData.value) return [];
    const search = mensualidadSearch.value.trim().toLowerCase();
    if (!search) return mensualidadData.value.items ?? [];
    return mensualidadData.value.items.filter((item) => {
        const proveedor = item.proveedor?.nombre?.toLowerCase() ?? '';
        const concepto = item.concepto?.toLowerCase?.() ?? '';
        return proveedor.includes(search) || concepto.includes(search);
    });
});

const proveedorModalTotals = computed(() => {
    if (!proveedorModalData.value) {
        return {
            cantidad: 0,
            total: 0,
            descProducto: 0,
            cargoTarjeta: 0,
            descTotal: 0,
            ganancia: 0,
        };
    }
    return providerItemTotals(proveedorModalData.value);
});

const sortedMensualidadItems = computed(() => {
    const items = [...filteredMensualidadItems.value];
    const column = mensualidadSort.value;
    const direction = mensualidadSortDirection.value === 'asc' ? 1 : -1;
    items.sort((a, b) => {
        const getValue = (item: MensualidadReportItem) => {
            switch (column) {
                case 'proveedor':
                    return (item.proveedor?.nombre ?? '').toLowerCase();
                case 'concepto':
                    return (item.concepto ?? '').toLowerCase();
                case 'importe':
                    return Number(item.importe ?? 0);
                case 'cantidad_pago':
                    return Number(item.cantidad_pago ?? 0);
                case 'restante':
                    return Number(item.restante ?? 0);
                case 'status':
                    return (item.status ?? '').toLowerCase();
                case 'fecha_cobro':
                    return item.fecha_cobro ?? '';
                case 'payment_date':
                    return item.payment_date ?? '';
                default:
                    return '';
            }
        };

        const valueA = getValue(a);
        const valueB = getValue(b);

        if (typeof valueA === 'number' && typeof valueB === 'number') {
            return (valueA - valueB) * direction;
        }

        return valueA > valueB ? direction : valueA < valueB ? -direction : 0;
    });
    return items;
});

function displayMensualidadStatus(status?: string | null) {
    if (!status) return '--';
    return mensualidadStatusMap[status] ?? status;
}

function toggleMensualidadSort(column: MensualidadSortableColumn) {
    if (mensualidadSort.value === column) {
        mensualidadSortDirection.value =
            mensualidadSortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        mensualidadSort.value = column;
        mensualidadSortDirection.value = 'asc';
    }
}

function mensualidadSortIcon(column: MensualidadSortableColumn) {
    if (mensualidadSort.value !== column) return '';
    return mensualidadSortDirection.value === 'asc' ? '▲' : '▼';
}

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

const proveedorModalFilteredItems = computed(() => {
    if (!proveedorModalData.value) return [];
    const search = proveedorModalSearch.value.trim().toLowerCase();
    const items = proveedorModalData.value.items ?? [];
    if (!search) return items;
    return items.filter((item) => {
        return (
            item.producto_nombre?.toLowerCase().includes(search) ||
            item.producto_ident?.toLowerCase().includes(search) ||
            item.metodo?.toLowerCase().includes(search) ||
            item.vendedor?.toLowerCase().includes(search)
        );
    });
});

const proveedorModalSortedItems = computed(() => {
    const column = proveedorModalSort.value;
    const dir = proveedorModalDirection.value === 'asc' ? 1 : -1;
    const items = [...proveedorModalFilteredItems.value];
    const valueOf = (item: CajaProveedorItem) => {
        switch (column) {
            case 'fecha':
                return item.fecha ?? '';
            case 'producto':
                return item.producto_nombre ?? '';
            case 'ident':
                return item.producto_ident ?? '';
            case 'cantidad':
                return Number(item.cantidad ?? 0);
            case 'precio':
                return Number(item.precio_unitario ?? 0);
            case 'total':
                return Number(item.total ?? 0);
            case 'desc_producto':
                return Number(item.descuento_producto ?? 0);
            case 'cargo_tarjeta':
                return Number(item.cargo_tarjeta ?? 0);
            case 'desc_total':
                return Number(item.descuento_total ?? 0);
            case 'ganancia':
                return Number(item.ganancia ?? 0);
            case 'metodo':
                return item.metodo ?? '';
            case 'vendedor':
                return item.vendedor ?? '';
            case 'promocion':
                return item.promotion ?? '';
            default:
                return '';
        }
    };

    items.sort((a, b) => {
        const va = valueOf(a);
        const vb = valueOf(b);
        if (typeof va === 'number' && typeof vb === 'number') {
            return (va - vb) * dir;
        }
        return va > vb ? dir : va < vb ? -dir : 0;
    });
    return items;
});

function openProveedorModal(proveedor: CajaProveedorGroup) {
    proveedorModalData.value = proveedor;
    proveedorModalOpen.value = true;
    proveedorModalSearch.value = '';
    proveedorModalSort.value = 'fecha';
    proveedorModalDirection.value = 'asc';
}

function closeProveedorModal() {
    proveedorModalOpen.value = false;
    proveedorModalData.value = null;
}

function toggleProveedorModalSort(column: ProveedorModalSort) {
    if (proveedorModalSort.value === column) {
        proveedorModalDirection.value = proveedorModalDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        proveedorModalSort.value = column;
        proveedorModalDirection.value = 'asc';
    }
}

function proveedorModalSortIcon(column: ProveedorModalSort) {
    if (proveedorModalSort.value !== column) return '';
    return proveedorModalDirection.value === 'asc' ? '▲' : '▼';
}

function downloadProveedorModalCsv() {
    if (!proveedorModalData.value) return;
    const header = [
        'Fecha',
        'Producto',
        'Ident',
        'Cantidad',
        'Precio unitario',
        'Total',
        'Desc. producto',
        'Cargo tarjeta',
        'Desc. total',
        'Ganancia',
        'Método',
        'Vendedor',
        'Promoción',
    ];
    const rows = proveedorModalSortedItems.value.map((item) => [
        item.fecha ?? '',
        item.producto_nombre ?? '',
        item.producto_ident ?? '',
        item.cantidad ?? '',
        item.precio_unitario ?? '',
        item.total ?? '',
        item.descuento_producto ?? '',
        item.cargo_tarjeta ?? '',
        item.descuento_total ?? '',
        item.ganancia ?? '',
        item.metodo ?? '',
        item.vendedor ?? '',
        item.promotion ?? '',
    ]);
    const csv = [header, ...rows]
        .map((line) => line.map((value) => `"${String(value).replace(/"/g, '""')}"`).join(','))
        .join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const filename = `caja-condensado-${proveedorModalData.value.proveedor_ident}-movimientos.csv`;
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(link.href);
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
const prodPerPage = ref<number>(50);
const prodSort = ref<'nombre' | 'proveedor' | 'precio'>('nombre');
const prodDirection = ref<'asc' | 'desc'>('asc');

const prodItems = ref<ProductoRow[]>([]);
const prodPagination = ref<ProductosPagination | null>(null);
const prodError = ref<string | null>(null);
let prodSearchTimer: ReturnType<typeof setTimeout> | null = null;

async function loadProductos() {
    prodLoading.value = true;
    prodError.value = null;
    try {
        const res: ProductosReportResponse = await getProductosReport({
            q: prodQ.value.trim() || undefined,
            page: prodPage.value,
            per_page: prodPerPage.value,
            sort: prodSort.value,
            direction: prodDirection.value,
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

function toggleProdSort(column: 'nombre' | 'precio' | 'proveedor') {
    if (prodSort.value === column) {
        prodDirection.value = prodDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        prodSort.value = column;
        prodDirection.value = 'asc';
    }
    prodPage.value = 1;
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
const inventarioPerPage = ref<number>(50);
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

function toggleInventarioSort(column: InventarioSort) {
    if (inventarioSort.value === column) {
        inventarioDirection.value = inventarioDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        inventarioSort.value = column;
        inventarioDirection.value = 'asc';
    }
    inventarioPage.value = 1;
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
        if (val === 'caja-egresos') {
            egresosError.value = '';
            if (!egresosData.value && rangeStart.value) {
                fetchEgresosCajaReport();
            }
        }
        if (val === 'mensualidad') {
            mensualidadError.value = '';
            if (!mensualidadData.value && mensualidadMonth.value) {
                fetchMensualidadReport();
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
watch(
    () => [prodSort.value, prodDirection.value],
    () => {
        prodPage.value = 1;
        if (selected.value === 'productos') loadProductos();
    }
);
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
            closeProveedorModal();
        }
        if (selected.value === 'caja-egresos') {
            egresosData.value = null;
            egresosError.value = '';
        }
    }
);

watch(
    () => [mensualidadMonth.value, mensualidadStatus.value],
    () => {
        if (selected.value === 'mensualidad') {
            mensualidadData.value = null;
            mensualidadError.value = '';
        }
    }
);

watch(
    () => cajaCondensadoData.value,
    () => {
        if (!cajaCondensadoData.value) {
            closeProveedorModal();
        }
    }
);

watch(prodQ, () => {
    if (prodSearchTimer) clearTimeout(prodSearchTimer);
    prodSearchTimer = setTimeout(() => {
        prodPage.value = 1;
        if (selected.value === 'productos') loadProductos();
    }, 300);
});

onBeforeUnmount(() => {
    if (prodSearchTimer) clearTimeout(prodSearchTimer);
});






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
                    <label class="flex flex-col text-sm text-gray-600" v-if="['caja', 'entradas','caja-condensado','caja-egresos'].includes(selected)">
                        <span class="font-medium text-gray-700">Fecha inicial</span>
                        <input v-model="rangeStart" type="date"
                            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900" />
                    </label>
                    <label class="flex flex-col text-sm text-gray-600" v-if="['caja', 'entradas','caja-condensado','caja-egresos'].includes(selected)">
                        <span class="font-medium text-gray-700">Fecha final <span
                                class="text-xs text-gray-400">(opcional)</span></span>
                        <input v-model="rangeEnd" type="date"
                            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900" />
                    </label>
                    <label class="flex flex-col text-sm text-gray-600" v-if="selected === 'mensualidad'">
                        <span class="font-medium text-gray-700">Mes de cobro</span>
                        <input v-model="mensualidadMonth" type="month"
                            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900" />
                    </label>
                    <label class="flex flex-col text-sm text-gray-600" v-if="selected === 'mensualidad'">
                        <span class="font-medium text-gray-700">Estado</span>
                        <select v-model="mensualidadStatus"
                            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900">
                            <option value="todos">Todos</option>
                            <option value="pendiente">Pendiente</option>
                            <option value="pagado">Pagado</option>
                        </select>
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
                    <template v-else-if="selected === 'caja-egresos'">
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button"
                                class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:bg-gray-300"
                                :disabled="egresosLoading" @click="fetchEgresosCajaReport()">
                                <span v-if="egresosLoading">Consultando…</span>
                                <span v-else>Consultar reporte</span>
                            </button>
                            <button type="button"
                                class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:text-gray-400"
                                :disabled="egresosLoading" @click="downloadEgresosCajaReport()">
                                Descargar CSV
                            </button>
                        </div>
                        <p v-if="egresosError"
                            class="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                            {{ egresosError }}
                        </p>
                        <div v-else class="mt-4 space-y-4">
                            <div v-if="egresosLoading" class="text-xs text-gray-500">Cargando datos…</div>
                            <div v-else-if="egresosData" class="space-y-4">
                                <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-gray-500">
                                    <div>
                                        Periodo:
                                        <span class="font-semibold text-gray-900">{{ egresosData.from_date }}</span>
                                        –
                                        <span class="font-semibold text-gray-900">{{ egresosData.to_date }}</span>
                                    </div>
                                    <div v-if="egresosSummary"
                                        class="grid grid-cols-2 gap-3 text-[11px] text-gray-500 sm:grid-cols-4">
                                        <div>
                                            <span class="block font-semibold text-gray-900">{{ egresosSummary.movimientos }}</span>
                                            <span>Movimientos</span>
                                        </div>
                                        <div>
                                            <span class="block font-semibold text-gray-900">{{ formatCurrency(egresosSummary.ingresos) }}</span>
                                            <span>Ingresos</span>
                                        </div>
                                        <div>
                                            <span class="block font-semibold text-gray-900">{{ formatCurrency(egresosSummary.egresos) }}</span>
                                            <span>Egresos</span>
                                        </div>
                                        <div>
                                            <span class="block font-semibold text-gray-900">{{ formatCurrency(egresosSummary.saldo) }}</span>
                                            <span>Saldo</span>
                                        </div>
                                    </div>
                                </div>
                                <p class="text-[11px] text-gray-500">
                                    *Ingresos corresponde a la suma de todas las ventas (ie = 1) dentro del periodo seleccionado.
                                </p>

                                <div :class="tableClasses.wrapper">
                                    <table :class="tableClasses.table">
                                        <thead :class="tableClasses.head">
                                            <tr>
                                                <th class="px-3 py-2">Venta ID</th>
                                                <th class="px-3 py-2">Fecha</th>
                                                <th class="px-3 py-2">Método</th>
                                                <th class="px-3 py-2">Vendedor</th>
                                                <th class="px-3 py-2">Concepto</th>
                                                <th class="px-3 py-2 text-right">Monto</th>
                                            </tr>
                                        </thead>
                                        <tbody :class="tableClasses.body">
                                            <tr v-if="egresosData.egresos.length === 0">
                                                <td class="px-3 py-6 text-center text-gray-500" colspan="6">
                                                    No hay movimientos con ie = 0 para el periodo seleccionado.
                                                </td>
                                            </tr>
                                            <tr v-for="mov in egresosData.egresos" :key="mov.idventa" :class="tableClasses.row">
                                                <td class="px-3 py-2 font-semibold text-gray-900">{{ mov.idventa }}</td>
                                                <td class="px-3 py-2">{{ mov.fecha }}</td>
                                                <td class="px-3 py-2 capitalize">{{ mov.metodo }}</td>
                                                <td class="px-3 py-2">{{ mov.vendedor }}</td>
                                                <td class="px-3 py-2">{{ mov.concepto }}</td>
                                                <td class="px-3 py-2 text-right">{{ formatCurrency(mov.totalventa) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <p v-else class="text-xs text-gray-500">Consulta el reporte para ver los movimientos.</p>
                        </div>
                    </template>
                    <template v-else-if="selected === 'mensualidad'">
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button"
                                class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:bg-gray-300"
                                :disabled="mensualidadLoading" @click="fetchMensualidadReport()">
                                <span v-if="mensualidadLoading">Consultando…</span>
                                <span v-else>Consultar reporte</span>
                            </button>
                            <button type="button"
                                class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:text-gray-400"
                                :disabled="mensualidadLoading" @click="fetchMensualidadReport(true)">
                                Descargar CSV
                            </button>
                        </div>
                        <p v-if="mensualidadError"
                            class="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                            {{ mensualidadError }}
                        </p>
                        <div v-else class="mt-4 space-y-4">
                            <div v-if="mensualidadLoading" class="text-xs text-gray-500">Cargando datos…</div>
                            <div v-else-if="mensualidadData" class="space-y-4">
                                <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-gray-500">
                                    <div>
                                        Mes:
                                        <span class="font-semibold text-gray-900">{{ mensualidadData.mes_cobro }}</span>
                                    </div>
                                    <div v-if="mensualidadSummary"
                                        class="grid grid-cols-2 gap-3 text-[11px] text-gray-500 sm:grid-cols-4">
                                        <div>
                                            <span class="block font-semibold text-gray-900">{{ mensualidadSummary.totalCobros }}</span>
                                            <span>Cobros</span>
                                        </div>
                                        <div>
                                            <span class="block font-semibold text-gray-900">{{ formatCurrency(mensualidadSummary.importeTotal) }}</span>
                                            <span>Importe total</span>
                                        </div>
                                        <div>
                                            <span class="block font-semibold text-gray-900">{{ formatCurrency(mensualidadSummary.pagadoTotal) }}</span>
                                            <span>Pagado</span>
                                        </div>
                                        <div>
                                            <span class="block font-semibold text-gray-900">{{ formatCurrency(mensualidadSummary.restanteTotal) }}</span>
                                            <span>Restante</span>
                                        </div>
                                    </div>
                                </div>
                                <p class="text-[11px] text-gray-500">
                                    *Pagos completos registrados: {{ mensualidadSummary?.pagosCompletos ?? 0 }}.
                                </p>

                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-xs text-gray-500">
                                        Registros:
                                        <span class="font-semibold text-gray-900">{{ filteredMensualidadItems.length }}</span>
                                    </p>
                                    <input v-model="mensualidadSearch" type="search"
                                        placeholder="Buscar por proveedor o concepto…"
                                        class="w-full max-w-xs rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900" />
                                </div>

                                <div :class="tableClasses.wrapper">
                                    <table :class="tableClasses.table">
                                        <thead :class="tableClasses.head">
                                            <tr>
                                                <th class="px-3 py-2">
                                                    <button type="button" class="flex items-center gap-1 font-semibold"
                                                        @click="toggleMensualidadSort('proveedor')">
                                                        Proveedor
                                                        <span class="text-[10px]">{{ mensualidadSortIcon('proveedor') }}</span>
                                                    </button>
                                                </th>
                                                <th class="px-3 py-2">
                                                    <button type="button" class="flex items-center gap-1 font-semibold"
                                                        @click="toggleMensualidadSort('concepto')">
                                                        Concepto
                                                        <span class="text-[10px]">{{ mensualidadSortIcon('concepto') }}</span>
                                                    </button>
                                                </th>
                                                <th class="px-3 py-2 text-right">
                                                    <button type="button" class="flex w-full items-center justify-end gap-1 font-semibold"
                                                        @click="toggleMensualidadSort('importe')">
                                                        Importe
                                                        <span class="text-[10px]">{{ mensualidadSortIcon('importe') }}</span>
                                                    </button>
                                                </th>
                                                <th class="px-3 py-2 text-right">
                                                    <button type="button" class="flex w-full items-center justify-end gap-1 font-semibold"
                                                        @click="toggleMensualidadSort('cantidad_pago')">
                                                        Pagado
                                                        <span class="text-[10px]">{{ mensualidadSortIcon('cantidad_pago') }}</span>
                                                    </button>
                                                </th>
                                                <th class="px-3 py-2 text-right">
                                                    <button type="button" class="flex w-full items-center justify-end gap-1 font-semibold"
                                                        @click="toggleMensualidadSort('restante')">
                                                        Restante
                                                        <span class="text-[10px]">{{ mensualidadSortIcon('restante') }}</span>
                                                    </button>
                                                </th>
                                                <th class="px-3 py-2">
                                                    <button type="button" class="flex items-center gap-1 font-semibold"
                                                        @click="toggleMensualidadSort('status')">
                                                        Estado
                                                        <span class="text-[10px]">{{ mensualidadSortIcon('status') }}</span>
                                                    </button>
                                                </th>
                                                <th class="px-3 py-2">
                                                    <button type="button" class="flex items-center gap-1 font-semibold"
                                                        @click="toggleMensualidadSort('fecha_cobro')">
                                                        Fecha cobro
                                                        <span class="text-[10px]">{{ mensualidadSortIcon('fecha_cobro') }}</span>
                                                    </button>
                                                </th>
                                                <th class="px-3 py-2">
                                                    <button type="button" class="flex items-center gap-1 font-semibold"
                                                        @click="toggleMensualidadSort('payment_date')">
                                                        Fecha pago
                                                        <span class="text-[10px]">{{ mensualidadSortIcon('payment_date') }}</span>
                                                    </button>
                                                </th>
                                                <th class="px-3 py-2">Cobro PDF</th>
                                                <th class="px-3 py-2">Recibo PDF</th>
                                            </tr>
                                        </thead>
                                        <tbody :class="tableClasses.body">
                                            <tr v-if="filteredMensualidadItems.length === 0">
                                                <td class="px-3 py-6 text-center text-gray-500" colspan="10">
                                                    No hay registros de mensualidad para los criterios seleccionados.
                                                </td>
                                            </tr>
                                            <tr v-for="item in sortedMensualidadItems" :key="item.id" :class="tableClasses.row">
                                                <td class="px-3 py-2">
                                                    <div class="font-semibold text-gray-900">{{ item.proveedor?.nombre ?? 'Sin proveedor' }}</div>
                                                    <div class="text-[11px] text-gray-500">{{ item.proveedor?.email ?? 'Sin correo' }}</div>
                                                </td>
                                                <td class="px-3 py-2">
                                                    <div class="text-gray-900">{{ item.concepto }}</div>
                                                    <p v-if="item.nota" class="text-[11px] text-gray-500">{{ item.nota }}</p>
                                                </td>
                                                <td class="px-3 py-2 text-right">{{ formatCurrency(item.importe) }}</td>
                                                <td class="px-3 py-2 text-right">{{ formatCurrency(item.cantidad_pago) }}</td>
                                                <td class="px-3 py-2 text-right">{{ formatCurrency(item.restante) }}</td>
                                                <td class="px-3 py-2">
                                                    <span :class="item.pago_completo ? 'text-emerald-600 font-semibold' : 'text-gray-800'">
                                                        {{ displayMensualidadStatus(item.status) }}
                                                    </span>
                                                </td>
                                                <td class="px-3 py-2">{{ item.fecha_cobro ?? '--' }}</td>
                                                <td class="px-3 py-2">{{ item.payment_date ?? '--' }}</td>
                                                <td class="px-3 py-2">
                                                    <a v-if="item.cobro_path" :href="item.cobro_path" target="_blank" rel="noopener"
                                                        class="text-xs text-gray-900 underline">
                                                        Ver cobro
                                                    </a>
                                                    <span v-else class="text-xs text-gray-500">--</span>
                                                </td>
                                                <td class="px-3 py-2">
                                                    <a v-if="item.receipt_path" :href="item.receipt_path" target="_blank" rel="noopener"
                                                        class="text-xs text-gray-900 underline">
                                                        Ver recibo
                                                    </a>
                                                    <span v-else class="text-xs text-gray-500">--</span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <p v-else class="text-xs text-gray-500">Consulta el reporte para ver los cobros del mes seleccionado.</p>
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
                                                <th class="px-3 py-2 cursor-pointer select-none" @click="toggleProdSort('nombre')">
                                                    Nombre
                                                    <span class="ml-1" v-if="prodSort === 'nombre'">
                                                        {{ prodDirection === 'asc' ? '▲' : '▼' }}
                                                    </span>
                                                </th>
                                                <th class="px-3 py-2 text-right cursor-pointer select-none" @click="toggleProdSort('precio')">
                                                    Precio
                                                    <span class="ml-1" v-if="prodSort === 'precio'">
                                                        {{ prodDirection === 'asc' ? '▲' : '▼' }}
                                                    </span>
                                                </th>
                                                <th class="px-3 py-2 cursor-pointer select-none" @click="toggleProdSort('proveedor')">
                                                    Proveedor
                                                    <span class="ml-1" v-if="prodSort === 'proveedor'">
                                                        {{ prodDirection === 'asc' ? '▲' : '▼' }}
                                                    </span>
                                                </th>
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
                                <div class="flex items-center gap-1 rounded-full border border-gray-200 bg-gray-100 p-1 text-xs">
                                    <button type="button"
                                        class="rounded-full px-3 py-1 font-medium transition"
                                        :class="cajaCondensadoView === 'cards' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500'"
                                        @click="cajaCondensadoView = 'cards'">
                                        Tarjetas
                                    </button>
                                    <button type="button"
                                        class="rounded-full px-3 py-1 font-medium transition"
                                        :class="cajaCondensadoView === 'table' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500'"
                                        @click="cajaCondensadoView = 'table'">
                                        Tabla
                                    </button>
                                </div>
                                <span class="text-xs text-gray-500">Resumen por proveedor de ventas en el periodo seleccionado.</span>
                            </div>

                            <div v-if="cajaCondensadoError"
                                class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                                {{ cajaCondensadoError }}
                            </div>

                            <div v-else>
                                <div v-if="cajaCondensadoLoading" class="text-xs text-gray-500">Cargando datos…</div>
                                <div v-else-if="cajaCondensadoData" class="space-y-4">
                                    <div class="flex flex-wrap items-start justify-between gap-3 text-xs text-gray-500" style="border: 1px solid #eee; border-radius: 8px; padding: 8px;">
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

                                    <template v-if="cajaCondensadoView === 'cards'">
                                        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3" style="border: 1px solid #eee; border-radius: 8px; padding: 8px;">
                                            <article v-for="proveedor in cajaCondensadoData.proveedores" :key="proveedor.proveedor_id"
                                                class="space-y-3 rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
                                                <div class="flex items-start justify-between gap-2">
                                                    <div>
                                                        <h3 class="text-base font-semibold text-gray-900">{{ proveedor.proveedor_nombre }}</h3>
                                                        <p class="text-xs text-gray-500">Ident {{ proveedor.proveedor_ident }}</p>
                                                    </div>
                                                    <button type="button"
                                                        class="inline-flex items-center justify-center rounded border border-gray-300 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50"
                                                        @click="openProveedorModal(proveedor)">
                                                        Movimientos
                                                    </button>
                                                </div>
                                                <dl class="grid grid-cols-2 gap-2 text-[11px] text-gray-600">
                                                    <div>
                                                        <dt class="text-gray-500">Ventas brutas</dt>
                                                        <dd class="font-semibold text-gray-900">{{ formatCurrency(proveedor.ventas_brutas) }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt class="text-gray-500">Descuentos</dt>
                                                        <dd class="font-semibold text-gray-900">{{ formatCurrency(proveedor.descuentos) }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt class="text-gray-500">Cargos tarjeta</dt>
                                                        <dd class="font-semibold text-gray-900">{{ formatCurrency(proveedor.cargos_tarjeta) }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt class="text-gray-500">Ganancia</dt>
                                                        <dd class="font-semibold text-gray-900">{{ formatCurrency(proveedor.ganancia_total) }}</dd>
                                                    </div>
                                                </dl>
                                                <div class="rounded-lg border border-dashed border-gray-200 bg-gray-50 px-3 py-2 text-[11px] text-gray-600">
                                                    <div class="flex justify-between">
                                                        <span>Unidades</span>
                                                        <span class="font-semibold text-gray-900">{{ providerItemTotals(proveedor).cantidad }}</span>
                                                    </div>
                                                    <div class="flex justify-between">
                                                        <span>Ventas</span>
                                                        <span class="font-semibold text-gray-900">{{ formatCurrency(providerItemTotals(proveedor).total) }}</span>
                                                    </div>
                                                    <div class="flex justify-between">
                                                        <span>Desc. prod</span>
                                                        <span class="font-semibold text-gray-900">{{ formatCurrency(providerItemTotals(proveedor).descProducto) }}</span>
                                                    </div>
                                                    <div class="flex justify-between">
                                                        <span>Cargo tarjeta</span>
                                                        <span class="font-semibold text-gray-900">{{ formatCurrency(providerItemTotals(proveedor).cargoTarjeta) }}</span>
                                                    </div>
                                                    <div class="flex justify-between">
                                                        <span>Desc. total</span>
                                                        <span class="font-semibold text-gray-900">{{ formatCurrency(providerItemTotals(proveedor).descTotal) }}</span>
                                                    </div>
                                                    <div class="flex justify-between">
                                                        <span>Ganancia</span>
                                                        <span class="font-semibold text-gray-900">{{ formatCurrency(providerItemTotals(proveedor).ganancia) }}</span>
                                                    </div>
                                                </div>
                                            </article>
                                            <div v-if="(cajaCondensadoData.proveedores?.length ?? 0) === 0"
                                                class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-4 text-center text-xs text-gray-500 md:col-span-2 lg:col-span-3">
                                                No se encontraron proveedores en el periodo seleccionado.
                                            </div>
                                        </div>
                                    </template>
                                    <template v-else>
                                        <div :class="tableClasses.wrapper">
                                            <table :class="tableClasses.table">
                                                <thead :class="tableClasses.head">
                                                    <tr>
                                                        <th class="px-3 py-2">Proveedor</th>
                                                        <th class="px-3 py-2">Ident</th>
                                                        <th class="px-3 py-2 text-right">Ventas brutas</th>
                                                        <th class="px-3 py-2 text-right">Descuentos</th>
                                                        <th class="px-3 py-2 text-right">Cargos tarjeta</th>
                                                        <th class="px-3 py-2 text-right">Ganancia</th>
                                                        <th class="px-3 py-2 text-right">Movimientos</th>
                                                    </tr>
                                                </thead>
                                                <tbody :class="tableClasses.body">
                                                    <tr v-for="proveedor in cajaCondensadoData.proveedores" :key="proveedor.proveedor_id" :class="tableClasses.row">
                                                        <td class="px-3 py-2 font-semibold text-gray-900">{{ proveedor.proveedor_nombre }}</td>
                                                        <td class="px-3 py-2">{{ proveedor.proveedor_ident }}</td>
                                                        <td class="px-3 py-2 text-right">{{ formatCurrency(proveedor.ventas_brutas) }}</td>
                                                        <td class="px-3 py-2 text-right">{{ formatCurrency(proveedor.descuentos) }}</td>
                                                        <td class="px-3 py-2 text-right">{{ formatCurrency(proveedor.cargos_tarjeta) }}</td>
                                                        <td class="px-3 py-2 text-right">{{ formatCurrency(proveedor.ganancia_total) }}</td>
                                                        <td class="px-3 py-2 text-right">
                                                            <button type="button"
                                                                class="inline-flex items-center justify-center rounded border border-gray-300 px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50"
                                                                @click="openProveedorModal(proveedor)">
                                                                Ver movimientos
                                                            </button>
                                                        </td>
                                                    </tr>
                                                    <tr v-if="(cajaCondensadoData.proveedores?.length ?? 0) === 0">
                                                        <td :class="tableClasses.emptyRow" colspan="7">
                                                            No se encontraron proveedores en el periodo seleccionado.
                                                        </td>
                                                    </tr>
                                                </tbody>
                                                <tfoot v-if="cajaCondensadoResumen" class="bg-gray-100 text-[11px] uppercase tracking-wide text-gray-600">
                                                    <tr>
                                                        <td class="px-3 py-2" colspan="2">Totales</td>
                                                        <td class="px-3 py-2 text-right">{{ formatCurrency(cajaCondensadoResumen.ventasBrutas) }}</td>
                                                        <td class="px-3 py-2 text-right">{{ formatCurrency(cajaCondensadoResumen.descuentos) }}</td>
                                                        <td class="px-3 py-2 text-right">{{ formatCurrency(cajaCondensadoResumen.cargosTarjeta) }}</td>
                                                        <td class="px-3 py-2 text-right">{{ formatCurrency(cajaCondensadoResumen.ganancias) }}</td>
                                                        <td class="px-3 py-2"></td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </template>
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
                                                <th
                                                    class="px-3 py-2 cursor-pointer select-none"
                                                    @click="toggleInventarioSort('producto')"
                                                >
                                                    Producto
                                                    <span v-if="inventarioSort === 'producto'" class="ml-1">
                                                        {{ inventarioDirection === 'asc' ? '▲' : '▼' }}
                                                    </span>
                                                </th>
                                                <th class="px-3 py-2 text-right">Precio</th>
                                                <th
                                                    class="px-3 py-2 text-right cursor-pointer select-none"
                                                    @click="toggleInventarioSort('existencia')"
                                                >
                                                    Existencia
                                                    <span v-if="inventarioSort === 'existencia'" class="ml-1">
                                                        {{ inventarioDirection === 'asc' ? '▲' : '▼' }}
                                                    </span>
                                                </th>
                                                <th class="px-3 py-2 text-right">Costo total</th>
                                                <th
                                                    class="px-3 py-2 cursor-pointer select-none"
                                                    @click="toggleInventarioSort('proveedor')"
                                                >
                                                    Proveedor
                                                    <span v-if="inventarioSort === 'proveedor'" class="ml-1">
                                                        {{ inventarioDirection === 'asc' ? '▲' : '▼' }}
                                                    </span>
                                                </th>
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
        <transition name="fade">
            <div v-if="proveedorModalOpen && proveedorModalData" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="closeProveedorModal"></div>
                <div class="relative z-10 w-full max-w-5xl max-h-[90vh] overflow-hidden rounded-2xl bg-white shadow-xl flex flex-col" role="dialog" aria-modal="true">
                    <header class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4">
                        <div>
                            <p class="text-sm uppercase tracking-wide text-gray-500">Movimientos por proveedor</p>
                            <h3 class="text-lg font-semibold text-gray-900">
                                {{ proveedorModalData?.proveedor_nombre }}
                            </h3>
                            <p class="text-xs text-gray-500">Ident {{ proveedorModalData?.proveedor_ident }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button"
                                class="inline-flex items-center justify-center rounded border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50"
                                @click="downloadProveedorModalCsv">
                                Descargar CSV
                            </button>
                            <button type="button"
                                class="inline-flex items-center justify-center rounded border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50"
                                @click="closeProveedorModal">
                                Cerrar
                            </button>
                        </div>
                    </header>
                    <div class="flex-1 overflow-hidden px-5 py-4 flex flex-col gap-4">
                        <div class="grid gap-3 text-xs text-gray-600 sm:grid-cols-5">
                            <div>
                                <span class="block font-semibold text-gray-900">{{ proveedorModalTotals.cantidad }}</span>
                                <span>Unidades</span>
                            </div>
                            <div>
                                <span class="block font-semibold text-gray-900">{{ formatCurrency(proveedorModalTotals.total) }}</span>
                                <span>Ventas</span>
                            </div>
                            <div>
                                <span class="block font-semibold text-gray-900">{{ formatCurrency(proveedorModalTotals.descProducto) }}</span>
                                <span>Desc. producto</span>
                            </div>
                            <div>
                                <span class="block font-semibold text-gray-900">{{ formatCurrency(proveedorModalTotals.cargoTarjeta) }}</span>
                                <span>Cargo tarjeta</span>
                            </div>
                            <div>
                                <span class="block font-semibold text-gray-900">{{ formatCurrency(proveedorModalTotals.ganancia) }}</span>
                                <span>Ganancia</span>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <input v-model="proveedorModalSearch" type="search"
                                class="w-full max-w-xs rounded border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                                placeholder="Buscar producto, vendedor o método…" />
                            <p class="text-xs text-gray-500">
                                Movimientos:
                                <span class="font-semibold text-gray-900">{{ proveedorModalFilteredItems.length }}</span>
                            </p>
                        </div>
                        <div class="flex-1 overflow-auto">
                            <div :class="tableClasses.wrapper">
                                <table :class="tableClasses.table">
                                    <thead :class="tableClasses.head">
                                        <tr>
                                            <th class="px-3 py-2">
                                                <button type="button" class="flex items-center gap-1 font-semibold" @click="toggleProveedorModalSort('fecha')">
                                                    Fecha
                                                    <span class="text-[10px]">{{ proveedorModalSortIcon('fecha') }}</span>
                                                </button>
                                            </th>
                                            <th class="px-3 py-2">
                                                <button type="button" class="flex items-center gap-1 font-semibold" @click="toggleProveedorModalSort('producto')">
                                                    Producto
                                                    <span class="text-[10px]">{{ proveedorModalSortIcon('producto') }}</span>
                                                </button>
                                            </th>
                                            <th class="px-3 py-2">
                                                <button type="button" class="flex items-center gap-1 font-semibold" @click="toggleProveedorModalSort('ident')">
                                                    Ident
                                                    <span class="text-[10px]">{{ proveedorModalSortIcon('ident') }}</span>
                                                </button>
                                            </th>
                                            <th class="px-3 py-2 text-right">
                                                <button type="button" class="flex w-full items-center justify-end gap-1 font-semibold" @click="toggleProveedorModalSort('cantidad')">
                                                    Cantidad
                                                    <span class="text-[10px]">{{ proveedorModalSortIcon('cantidad') }}</span>
                                                </button>
                                            </th>
                                            <th class="px-3 py-2 text-right">
                                                <button type="button" class="flex w-full items-center justify-end gap-1 font-semibold" @click="toggleProveedorModalSort('precio')">
                                                    Precio unitario
                                                    <span class="text-[10px]">{{ proveedorModalSortIcon('precio') }}</span>
                                                </button>
                                            </th>
                                            <th class="px-3 py-2 text-right">
                                                <button type="button" class="flex w-full items-center justify-end gap-1 font-semibold" @click="toggleProveedorModalSort('total')">
                                                    Total
                                                    <span class="text-[10px]">{{ proveedorModalSortIcon('total') }}</span>
                                                </button>
                                            </th>
                                            <th class="px-3 py-2 text-right">
                                                <button type="button" class="flex w-full items-center justify-end gap-1 font-semibold" @click="toggleProveedorModalSort('desc_producto')">
                                                    Desc. producto
                                                    <span class="text-[10px]">{{ proveedorModalSortIcon('desc_producto') }}</span>
                                                </button>
                                            </th>
                                            <th class="px-3 py-2 text-right">
                                                <button type="button" class="flex w-full items-center justify-end gap-1 font-semibold" @click="toggleProveedorModalSort('cargo_tarjeta')">
                                                    Cargo tarjeta
                                                    <span class="text-[10px]">{{ proveedorModalSortIcon('cargo_tarjeta') }}</span>
                                                </button>
                                            </th>
                                            <th class="px-3 py-2 text-right">
                                                <button type="button" class="flex w-full items-center justify-end gap-1 font-semibold" @click="toggleProveedorModalSort('desc_total')">
                                                    Desc. total*
                                                    <span class="text-[10px]">{{ proveedorModalSortIcon('desc_total') }}</span>
                                                </button>
                                            </th>
                                            <th class="px-3 py-2 text-right">
                                                <button type="button" class="flex w-full items-center justify-end gap-1 font-semibold" @click="toggleProveedorModalSort('ganancia')">
                                                    Ganancia
                                                    <span class="text-[10px]">{{ proveedorModalSortIcon('ganancia') }}</span>
                                                </button>
                                            </th>
                                            <th class="px-3 py-2">
                                                <button type="button" class="flex items-center gap-1 font-semibold" @click="toggleProveedorModalSort('metodo')">
                                                    Método
                                                    <span class="text-[10px]">{{ proveedorModalSortIcon('metodo') }}</span>
                                                </button>
                                            </th>
                                            <th class="px-3 py-2">
                                                <button type="button" class="flex items-center gap-1 font-semibold" @click="toggleProveedorModalSort('vendedor')">
                                                    Vendedor
                                                    <span class="text-[10px]">{{ proveedorModalSortIcon('vendedor') }}</span>
                                                </button>
                                            </th>
                                            <th class="px-3 py-2">
                                                <button type="button" class="flex items-center gap-1 font-semibold" @click="toggleProveedorModalSort('promocion')">
                                                    Promoción
                                                    <span class="text-[10px]">{{ proveedorModalSortIcon('promocion') }}</span>
                                                </button>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody :class="tableClasses.body">
                                        <tr v-if="proveedorModalSortedItems.length === 0">
                                            <td :class="tableClasses.emptyRow" colspan="13">
                                                No hay movimientos para este proveedor.
                                            </td>
                                        </tr>
                                        <tr v-for="item in proveedorModalSortedItems" :key="item.ventadesg_id" :class="tableClasses.row">
                                            <td class="px-3 py-2">{{ item.fecha }}</td>
                                            <td class="px-3 py-2">{{ item.producto_nombre }}</td>
                                            <td class="px-3 py-2">{{ item.producto_ident }}</td>
                                            <td class="px-3 py-2 text-right">{{ item.cantidad }}</td>
                                            <td class="px-3 py-2 text-right">{{ formatCurrency(item.precio_unitario) }}</td>
                                            <td class="px-3 py-2 text-right">{{ formatCurrency(item.total) }}</td>
                                            <td class="px-3 py-2 text-right">{{ formatCurrency(item.descuento_producto) }}</td>
                                            <td class="px-3 py-2 text-right">{{ formatCurrency(item.cargo_tarjeta) }}</td>
                                            <td class="px-3 py-2 text-right">{{ formatCurrency(item.descuento_total) }}</td>
                                            <td class="px-3 py-2 text-right">{{ formatCurrency(item.ganancia) }}</td>
                                            <td class="px-3 py-2 capitalize">{{ item.metodo }}</td>
                                            <td class="px-3 py-2">{{ item.vendedor }}</td>
                                            <td class="px-3 py-2">{{ item.promotion ?? 'normal' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p class="text-[10px] text-gray-500 mt-2">* Desc. total = Desc. producto + Cargo tarjeta.</p>
                        </div>
                    </div>
                </div>
            </div>
        </transition>
    </AppLayout>
</template>
