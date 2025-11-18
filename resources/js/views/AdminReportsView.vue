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
    getFlujoCajaReport,
    getRestockForecastReport,
    updateRestockPreference,
    getMensualidadReport,
    type CajaReportResponse,
    type CajaReportVenta,
    type CajaReportLine,
    type EgresosCajaReportResponse,
    type EgresoCajaMovimiento,
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
    type FlujoCajaResponse,
    type FlujoCajaRow,
    type RestockForecastResponse,
    type RestockForecastItem,
    type RestockHorizon,
} from '../api/reports';

function formatCurrency(value: number | string | null | undefined): string {
    const num = typeof value === 'string' ? Number(value) : value;
    if (!Number.isFinite(num)) return '--';
    return Number(num).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' });
}

function formatCajaFecha(value?: string | null): string {
    if (!value) return '';
    const [datePart] = value.split(' ');
    return datePart || value;
}

function providerDiscountTooltip(linea: CajaReportLine): string {
    if (linea.provider_discount_type === 'porcentaje') {
        const qty = Number(linea.quantity ?? 0);
        const unit = Number(linea.unit_price ?? 0);
        const amount = unit * qty * 0.2;
        return `${formatCurrency(unit)} × ${qty} × 0.20 = ${formatCurrency(amount)}`;
    }
    if (linea.provider_discount_type === 'consigna') {
        const qty = Number(linea.quantity ?? 0);
        const unit = Number(linea.unit_price ?? 0);
        const provider = Number(linea.provider_price ?? 0);
        const amount = unit * qty - provider * qty;
        return `(${formatCurrency(unit)} × ${qty}) − (${formatCurrency(provider)} × ${qty}) = ${formatCurrency(amount)}`;
    }
    return 'Sin descuento proveedor';
}

function providerPaymentTooltip(linea: CajaReportLine): string {
    const qty = Number(linea.quantity ?? 0);
    const providerPrice = Number(linea.provider_price ?? 0);
    const card = Number(linea.credit_card_discount ?? 0);
    const manual = Number(linea.manual_discount_amount ?? 0);
    const total = providerPrice * qty;
    const afterManual = total - manual;
    const parts = [
        `(${formatCurrency(providerPrice)} × ${qty})`,
        manual > 0 ? `− ${formatCurrency(manual)} (manual)` : null,
        card > 0 ? `− ${formatCurrency(card)} (tarjeta)` : null,
    ].filter(Boolean);
    return `${parts.join(' ')} = ${formatCurrency(afterManual - card)}`;
}


type ReportType =
    | 'caja'
    | 'productos'
    | 'inventario'
    | 'entradas'
    | 'caja-condensado'
    | 'caja-egresos'
    | 'flujo-caja'
    | 'restock'
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
type ProveedorTipoFilter = 'todos' | 'normal' | 'consigna' | 'porcentaje';
type EgresosSortColumn = 'fecha' | 'descripcion' | 'monto' | 'id' | 'creado_por';
type FlujoSortColumn =
    | 'fecha'
    | 'saldo_inicial'
    | 'efectivo'
    | 'transferencia'
    | 'tarjeta'
    | 'ingresos_total'
    | 'egresos'
    | 'saldo_cierre';
type ProveedorModalSort =
    | 'fecha'
    | 'producto'
    | 'ident'
    | 'venta'
    | 'cantidad'
    | 'precio'
    | 'provider_price'
    | 'total'
    | 'provider_discount'
    | 'manual'
    | 'card_fee'
    | 'real'
    | 'metodo'
    | 'vendedor'
    | 'promocion';
type CajaCondensadoSortColumn =
    | 'proveedor'
    | 'ident'
    | 'ventas'
    | 'tipo_descuento'
    | 'manual_descuento'
    | 'card_fee'
    | 'real';

const options: Array<{ value: ReportType; label: string }> = [
    { value: 'caja', label: 'Caja' },
    { value: 'productos', label: 'Productos' },
    { value: 'inventario', label: 'Inventario' },
    { value: 'entradas', label: 'Entradas' },
    { value: 'caja-condensado', label: 'Caja condensado' },
    { value: 'caja-egresos', label: 'Egresos de caja' },
    { value: 'flujo-caja', label: 'Flujo de caja' },
    { value: 'restock', label: 'Alertas de restock' },
    { value: 'mensualidad', label: 'Mensualidad' },
];

type RestockHorizonOption = RestockHorizon;
const restockHorizonOptions: Array<{ value: RestockHorizonOption; label: string }> = [
    { value: '2w', label: 'Próximas 2 semanas' },
    { value: '4w', label: 'Próximas 4 semanas' },
    { value: '6w', label: 'Próximas 6 semanas' },
];

type InventarioSort = 'producto' | 'existencia' | 'proveedor';
type SortDirection = 'asc' | 'desc';
type MensualidadStatusFilter = 'todos' | 'pendiente' | 'pagado';
type CajaSortColumn = 'fecha' | 'metodo' | 'vendedor' | 'total' | 'id';
const cajaSortLabels: Record<CajaSortColumn, string> = {
    fecha: 'Fecha',
    metodo: 'Método de pago',
    vendedor: 'Vendedor',
    total: 'Total venta',
    id: 'ID de venta',
};

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
const showCajaWidgets = ref(false);
const cajaMetodoFilter = ref('');
const cajaVendedorFilter = ref('');
const cajaSortColumn = ref<CajaSortColumn>('fecha');
const cajaSortDirection = ref<SortDirection>('desc');

const entradasLoading = ref(false);
const entradasError = ref('');
const entradasData = ref<EntradasReportResponse | null>(null);

const cajaCondensadoLoading = ref(false);
const cajaCondensadoError = ref('');
const cajaCondensadoData = ref<CajaProveedoresResponse | null>(null);
const cajaCondensadoView = ref<'cards' | 'table'>('table');
const cajaCondensadoTipoFilter = ref<ProveedorTipoFilter>('todos');
const cajaCondensadoTipoOptions: Array<{ value: ProveedorTipoFilter; label: string }> = [
    { value: 'todos', label: 'Todos los tipos' },
    { value: 'normal', label: 'Normal' },
    { value: 'consigna', label: 'Consigna' },
    { value: 'porcentaje', label: 'Porcentaje' },
];
const cajaCondensadoSortColumn = ref<CajaCondensadoSortColumn>('ventas');
const cajaCondensadoSortDirection = ref<SortDirection>('desc');
const proveedorModalOpen = ref(false);
const proveedorModalData = ref<CajaProveedorGroup | null>(null);
const proveedorModalSort = ref<ProveedorModalSort>('fecha');
const proveedorModalDirection = ref<SortDirection>('asc');
const proveedorModalSearch = ref('');

const egresosLoading = ref(false);
const egresosError = ref('');
const egresosData = ref<EgresosCajaReportResponse | null>(null);
const egresosSearch = ref('');
const egresosSortColumn = ref<EgresosSortColumn>('fecha');
const egresosSortDirection = ref<SortDirection>('desc');

const flujoLoading = ref(false);
const flujoError = ref('');
const flujoData = ref<FlujoCajaResponse | null>(null);
const flujoSearch = ref('');
const flujoSortColumn = ref<FlujoSortColumn>('fecha');
const flujoSortDirection = ref<SortDirection>('asc');

const restockLoading = ref(false);
const restockError = ref('');
const restockData = ref<RestockForecastResponse | null>(null);
const restockSearch = ref('');
const restockSort = ref<'provider' | 'producto' | 'avg' | 'stock' | 'suggested' | 'cover'>('suggested');
const restockSortDirection = ref<SortDirection>('desc');
const restockHorizon = ref<RestockHorizonOption>('2w');
const restockSavingPref = ref(false);

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
        case 'flujo-caja':
            return 'Reporte de flujo de caja';
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
                cajaMetodoFilter.value = '';
                cajaVendedorFilter.value = '';
                cajaSortColumn.value = 'fecha';
                cajaSortDirection.value = 'desc';
                expandedVentaIds.value = new Set();
                showCajaWidgets.value = false;
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

async function fetchFlujoCajaReport() {
    if (selected.value !== 'flujo-caja') return;
    flujoError.value = '';

    if (!rangeStart.value) {
        flujoError.value = 'Selecciona la fecha inicial.';
        return;
    }

    const from = normalizeDateParam(rangeStart.value);
    const to = rangeEnd.value ? normalizeDateParam(rangeEnd.value) : undefined;

    flujoLoading.value = true;
    try {
        const response = await getFlujoCajaReport({ from_date: from, to_date: to });
        if (response instanceof Blob) {
            flujoError.value = 'La respuesta del reporte no es válida.';
            flujoData.value = null;
        } else {
            flujoData.value = response as FlujoCajaResponse;
        }
    } catch (err: any) {
        flujoError.value = err?.response?.data?.message || err?.message || 'No se pudo cargar el flujo de caja.';
        flujoData.value = null;
    } finally {
        flujoLoading.value = false;
    }
}

async function downloadFlujoCajaReport() {
    if (selected.value !== 'flujo-caja') return;
    flujoError.value = '';

    if (!rangeStart.value) {
        flujoError.value = 'Selecciona la fecha inicial.';
        return;
    }

    const from = normalizeDateParam(rangeStart.value);
    const to = rangeEnd.value ? normalizeDateParam(rangeEnd.value) : undefined;

    try {
        const blob = await getFlujoCajaReport({ from_date: from, to_date: to, download: true });
        if (!(blob instanceof Blob)) {
            flujoError.value = 'La respuesta del reporte no es válida para descarga.';
            return;
        }
        const filename = `reporte-flujo-caja-${from}--${to ?? from}.csv`;
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    } catch (err: any) {
        flujoError.value = err?.response?.data?.message || err?.message || 'No se pudo descargar el reporte.';
    }
}

async function fetchRestockForecast() {
    if (selected.value !== 'restock') return;
    restockError.value = '';

    const forecastDate = rangeStart.value || undefined;

    restockLoading.value = true;
    try {
        const response = await getRestockForecastReport({ forecast_date: forecastDate, horizon: restockHorizon.value });
        restockData.value = response as RestockForecastResponse;
        restockHorizon.value = response.horizon;
    } catch (err: any) {
        restockError.value = err?.response?.data?.message || err?.message || 'No se pudo cargar el pronóstico.';
        restockData.value = null;
    } finally {
        restockLoading.value = false;
    }
}

async function saveRestockPreference(horizon: RestockHorizonOption) {
    restockSavingPref.value = true;
    try {
        await updateRestockPreference(horizon);
    } catch (err) {
        // ignore errors silently for now
    } finally {
        restockSavingPref.value = false;
    }
}

async function changeRestockHorizon(value: RestockHorizonOption) {
    restockHorizon.value = value;
    await saveRestockPreference(value);
    await fetchRestockForecast();
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

const cajaSummary = computed(() => {
    const summary = cajaData.value?.summary;
    if (!summary) return null;
    const methods = Array.isArray(summary.metodos)
        ? summary.metodos
              .map((item) => ({
                  metodo: item.metodo ?? '',
                  total: Number(item.total ?? 0),
                  count: Number(item.count ?? 0),
              }))
              .filter((item) => item.metodo)
        : [];

    return {
        totalVentas: Number(summary.ventas_total ?? cajaData.value?.ventas?.length ?? 0),
        totalVenta: Number(summary.total_totalventa ?? 0),
        totalRecibido: Number(summary.total_recibido ?? 0),
        totalCambio: Number(summary.total_cambio ?? 0),
        metodos: methods.sort((a, b) => b.total - a.total),
    };
});

const providerBadgeStyles: Record<'normal' | 'consigna' | 'porcentaje', { icon: string; label: string; className: string }> = {
    normal: { icon: '🛒', label: 'Normal', className: 'bg-gray-100 text-gray-700' },
    consigna: { icon: '📦', label: 'Consigna', className: 'bg-amber-100 text-amber-700' },
    porcentaje: { icon: '％', label: 'Porcentaje', className: 'bg-indigo-100 text-indigo-700' },
};

function providerBadgeInfo(
    type: 'normal' | 'consigna' | 'porcentaje' = 'normal',
    percent?: number | null
) {
    const base = providerBadgeStyles[type] ?? providerBadgeStyles.normal;
    const label =
        type === 'porcentaje' && percent != null
            ? `${base.label} (${percent}%)`
            : base.label;
    return { ...base, label };
}

function normalizeProveedorTipo(value?: string | null): 'normal' | 'consigna' | 'porcentaje' {
    if (value === 'consigna' || value === 'porcentaje') return value;
    return 'normal';
}

function providerCondensadoBadge(proveedor: { proveedor_tipo?: string | null; proveedor_porcentaje?: number | null }) {
    return providerBadgeInfo(normalizeProveedorTipo(proveedor.proveedor_tipo), proveedor.proveedor_porcentaje);
}

function formatProviderEarningTooltip(item: CajaProveedorItem): string {
    const qty = Number(item.cantidad ?? 0);
    const providerUnit = Number(item.provider_price ?? 0);
    const providerTotal = providerUnit * qty;
    const manual = Number(item.manual_discount ?? 0);
    const card = Number(item.card_fee ?? 0);
    const real = Number(item.real_earning ?? item.expected_earning ?? 0);
    return `(p. proveedor) ${formatCurrency(providerUnit)} × ${qty} = ${formatCurrency(providerTotal)} − ${formatCurrency(manual)} (desc. manual) − ${formatCurrency(card)} (desc.tarjeta) = ${formatCurrency(real)}`;
}

const cajaMetodoOptions = computed(() => {
    const metodos = new Set<string>();
    (cajaData.value?.ventas ?? []).forEach((venta) => {
        if (venta.metodo) metodos.add(venta.metodo);
    });
    (cajaSummary.value?.metodos ?? []).forEach((item) => {
        if (item.metodo) metodos.add(item.metodo);
    });
    return Array.from(metodos).sort((a, b) => a.localeCompare(b));
});

const cajaVendedorOptions = computed(() => {
    const vendedores = new Set<string>();
    (cajaData.value?.ventas ?? []).forEach((venta) => {
        if (venta.vendedor) vendedores.add(venta.vendedor);
    });
    return Array.from(vendedores).sort((a, b) => a.localeCompare(b));
});

const filteredCajaVentas = computed<CajaReportVenta[]>(() => {
    const ventas = [...(cajaData.value?.ventas ?? [])];
    const search = cajaSearch.value.trim().toLowerCase();
    const metodoFilter = cajaMetodoFilter.value;
    const vendedorFilter = cajaVendedorFilter.value;

    let result = ventas.filter((venta) => {
        if (metodoFilter && venta.metodo !== metodoFilter) return false;
        if (vendedorFilter && venta.vendedor !== vendedorFilter) return false;
        if (!search) return true;

        const matchesVenta =
            String(venta.idventa).includes(search) ||
            (venta.metodo?.toLowerCase?.().includes(search) ?? false) ||
            (venta.vendedor?.toLowerCase?.().includes(search) ?? false);

        if (matchesVenta) return true;

        return (venta.lineas ?? []).some((linea) => {
            return (
                linea.nombre?.toLowerCase()?.includes(search) ||
                String(linea.producto_id ?? '').includes(search) ||
                (linea.provider?.nombre?.toLowerCase()?.includes(search) ?? false) ||
                (linea.provider?.tipo?.toLowerCase()?.includes(search) ?? false)
            );
        });
    });

    const direction = cajaSortDirection.value === 'asc' ? 1 : -1;
    const column = cajaSortColumn.value;

    result.sort((a, b) => {
        switch (column) {
            case 'metodo':
                return ((a.metodo ?? '').localeCompare(b.metodo ?? '')) * direction;
            case 'vendedor':
                return ((a.vendedor ?? '').localeCompare(b.vendedor ?? '')) * direction;
            case 'total':
                return (Number(a.totalventa ?? 0) - Number(b.totalventa ?? 0)) * direction;
            case 'id':
                return (Number(a.idventa ?? 0) - Number(b.idventa ?? 0)) * direction;
            case 'fecha':
            default:
                const aDate = a.fecha ? Date.parse(a.fecha) : NaN;
                const bDate = b.fecha ? Date.parse(b.fecha) : NaN;
                const safeADate = Number.isNaN(aDate) ? 0 : aDate;
                const safeBDate = Number.isNaN(bDate) ? 0 : bDate;
                return (safeADate - safeBDate) * direction;
        }
    });

    return result;
});

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

const visibleCajaVentas = computed(() => {
    const limit = cajaDisplayLimit.value;
    return filteredCajaVentas.value.slice(0, limit);
});

function loadMoreCajaVentas() {
    cajaDisplayLimit.value += 10;
}

function setCajaSort(column: CajaSortColumn) {
    if (cajaSortColumn.value === column) {
        cajaSortDirection.value = cajaSortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        cajaSortColumn.value = column;
        cajaSortDirection.value = column === 'fecha' ? 'desc' : 'asc';
    }
}

function resetCajaFilters() {
    cajaSearch.value = '';
    cajaMetodoFilter.value = '';
    cajaVendedorFilter.value = '';
    cajaSortColumn.value = 'fecha';
    cajaSortDirection.value = 'desc';
}

function toggleCajaSortDirection() {
    cajaSortDirection.value = cajaSortDirection.value === 'asc' ? 'desc' : 'asc';
}

function toggleEgresosSort(column: EgresosSortColumn) {
    if (egresosSortColumn.value === column) {
        egresosSortDirection.value = egresosSortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        egresosSortColumn.value = column;
        egresosSortDirection.value = column === 'descripcion' ? 'asc' : 'desc';
    }
}

function egresosSortIcon(column: EgresosSortColumn) {
    if (egresosSortColumn.value !== column) return '';
    return egresosSortDirection.value === 'asc' ? '▲' : '▼';
}

function toggleFlujoSort(column: FlujoSortColumn) {
    if (flujoSortColumn.value === column) {
        flujoSortDirection.value = flujoSortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        flujoSortColumn.value = column;
        flujoSortDirection.value = column === 'fecha' ? 'asc' : 'desc';
    }
}

function flujoSortIcon(column: FlujoSortColumn) {
    if (flujoSortColumn.value !== column) return '';
    return flujoSortDirection.value === 'asc' ? '▲' : '▼';
}

function toggleRestockSort(column: 'provider' | 'producto' | 'avg' | 'stock' | 'suggested' | 'cover') {
    if (restockSort.value === column) {
        restockSortDirection.value = restockSortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        restockSort.value = column;
        restockSortDirection.value = column === 'provider' || column === 'producto' ? 'asc' : 'desc';
    }
}

function restockSortIcon(column: 'provider' | 'producto' | 'avg' | 'stock' | 'suggested' | 'cover') {
    if (restockSort.value !== column) return '';
    return restockSortDirection.value === 'asc' ? '▲' : '▼';
}

function toggleCajaCondensadoSort(column: CajaCondensadoSortColumn) {
    if (cajaCondensadoSortColumn.value === column) {
        cajaCondensadoSortDirection.value =
            cajaCondensadoSortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        cajaCondensadoSortColumn.value = column;
        cajaCondensadoSortDirection.value =
            column === 'proveedor' || column === 'ident' ? 'asc' : 'desc';
    }
}

function cajaCondensadoSortIcon(column: CajaCondensadoSortColumn) {
    if (cajaCondensadoSortColumn.value !== column) return '';
    return cajaCondensadoSortDirection.value === 'asc' ? '▲' : '▼';
}

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

const filteredCajaCondensadoProviders = computed(() => {
    if (!cajaCondensadoData.value) return [] as CajaProveedorGroup[];
    const providers = cajaCondensadoData.value.proveedores ?? [];
    const filter = cajaCondensadoTipoFilter.value;
    if (filter === 'todos') return providers;
    return providers.filter((prov) => normalizeProveedorTipo(prov.proveedor_tipo) === filter);
});

const sortedCajaCondensadoProviders = computed(() => {
    const providers = [...filteredCajaCondensadoProviders.value];
    const column = cajaCondensadoSortColumn.value;
    const dir = cajaCondensadoSortDirection.value === 'asc' ? 1 : -1;
    const valueOf = (prov: CajaProveedorGroup) => {
        switch (column) {
            case 'proveedor':
                return prov.proveedor_nombre?.toLowerCase() ?? '';
            case 'ident':
                return prov.proveedor_ident?.toLowerCase() ?? '';
            case 'ventas':
                return Number(prov.total_vendido ?? 0);
            case 'tipo_descuento':
                return Number(prov.tipo_descuento_total ?? 0);
            case 'manual_descuento':
                return Number(prov.manual_discount_total ?? 0);
            case 'card_fee':
                return Number(prov.card_fee_total ?? 0);
            case 'real':
                return Number(prov.real_earning ?? prov.expected_earning ?? 0);
            default:
                return 0;
        }
    };
    providers.sort((a, b) => {
        const valueA = valueOf(a);
        const valueB = valueOf(b);
        if (typeof valueA === 'number' && typeof valueB === 'number') {
            return (valueA - valueB) * dir;
        }
        return valueA > valueB ? dir : valueA < valueB ? -dir : 0;
    });
    return providers;
});

const cajaCondensadoResumen = computed(() => {
    if (!cajaCondensadoData.value) return null;
    if (cajaCondensadoTipoFilter.value === 'todos') {
        const res = cajaCondensadoData.value.resumen;
        const ventas = Number(res.ventas_brutas ?? 0);
        const descuentos = Number(res.descuentos ?? 0);
        const manual = Number(res.manual_descuentos ?? cajaCondensadoData.value.manual_descuentos_total ?? 0);
        const cargos = Number(res.cargos_tarjeta ?? 0);
        const ganancias = Number(res.ganancias ?? 0);
        return {
            totalVendido: ventas,
            ventasBrutas: ventas,
            descuentoTipo: descuentos,
            descuentoManual: manual,
            descuentos,
            cargosTarjeta: cargos,
            ganancias,
            totalProveedores: cajaCondensadoData.value.proveedores?.length ?? 0,
        };
    }

    const providers = filteredCajaCondensadoProviders.value;
    const totals = providers.reduce(
        (acc, prov) => {
            acc.totalVendido += Number(prov.total_vendido ?? 0);
            acc.descuentoTipo += Number(prov.tipo_descuento_total ?? 0);
            acc.descuentoManual += Number(prov.manual_discount_total ?? 0);
            acc.cargosTarjeta += Number(prov.card_fee_total ?? 0);
            acc.ganancias += Number(prov.real_earning ?? prov.expected_earning ?? 0);
            return acc;
        },
        {
            totalVendido: 0,
            descuentoTipo: 0,
            descuentoManual: 0,
            cargosTarjeta: 0,
            ganancias: 0,
        }
    );

    return {
        ...totals,
        ventasBrutas: totals.totalVendido,
        descuentos: totals.descuentoTipo,
        totalProveedores: providers.length,
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
const egresosRows = computed(() => egresosData.value?.egresos ?? []);
const filteredEgresos = computed(() => {
    const search = egresosSearch.value.trim().toLowerCase();
    let rows = [...egresosRows.value];
    if (search) {
        rows = rows.filter((row) => {
            return (
                row.descripcion?.toLowerCase().includes(search) ||
                row.creado_por?.toLowerCase().includes(search) ||
                row.fecha?.toLowerCase().includes(search) ||
                String(row.id ?? '').includes(search) ||
                String(row.monto ?? '').includes(search)
            );
        });
    }
    const dir = egresosSortDirection.value === 'asc' ? 1 : -1;
    rows.sort((a, b) => {
        const valueOf = (row: EgresoCajaMovimiento) => {
            switch (egresosSortColumn.value) {
                case 'id':
                    return Number(row.id ?? 0);
                case 'fecha':
                    return row.fecha ?? '';
                case 'descripcion':
                    return row.descripcion?.toLowerCase() ?? '';
                case 'creado_por':
                    return row.creado_por?.toLowerCase() ?? '';
                case 'monto':
                    return Number(row.monto ?? 0);
                default:
                    return '';
            }
        };
        const va = valueOf(a);
        const vb = valueOf(b);
        if (typeof va === 'number' && typeof vb === 'number') {
            return (va - vb) * dir;
        }
        return va > vb ? dir : va < vb ? -dir : 0;
    });
    return rows;
});

const flujoResumen = computed(() => flujoData.value?.resumen ?? null);
const flujoItems = computed(() => flujoData.value?.items ?? []);
const filteredFlujoItems = computed(() => {
    const search = flujoSearch.value.trim().toLowerCase();
    let rows = [...flujoItems.value];
    if (search) {
        rows = rows.filter((row) => {
            return (
                row.fecha?.toLowerCase().includes(search) ||
                String(row.saldo_inicial ?? '').includes(search) ||
                String(row.ingresos_total ?? '').includes(search)
            );
        });
    }
    const dir = flujoSortDirection.value === 'asc' ? 1 : -1;
    const valueOf = (row: FlujoCajaRow) => {
        switch (flujoSortColumn.value) {
            case 'fecha':
                return row.fecha ?? '';
            case 'saldo_inicial':
                return Number(row.saldo_inicial ?? 0);
            case 'efectivo':
                return Number(row.efectivo ?? 0);
            case 'transferencia':
                return Number(row.transferencia ?? 0);
            case 'tarjeta':
                return Number(row.tarjeta ?? 0);
            case 'ingresos_total':
                return Number(row.ingresos_total ?? 0);
            case 'egresos':
                return Number(row.egresos ?? 0);
            case 'saldo_cierre':
                return Number(row.saldo_cierre ?? 0);
            default:
                return 0;
        }
    };
    rows.sort((a, b) => {
        const va = valueOf(a);
        const vb = valueOf(b);
        if (typeof va === 'number' && typeof vb === 'number') {
            return (va - vb) * dir;
        }
        return va > vb ? dir : va < vb ? -dir : 0;
    });
    return rows;
});

const restockSummary = computed(() => restockData.value?.summary ?? null);
const restockItems = computed(() => restockData.value?.items ?? []);
const filteredRestockItems = computed(() => {
    const search = restockSearch.value.trim().toLowerCase();
    const items = [...restockItems.value];
    const dir = restockSortDirection.value === 'asc' ? 1 : -1;

    const filtered = search
        ? items.filter((item) => {
              return (
                  item.provider_name?.toLowerCase().includes(search) ||
                  item.provider_ident.toLowerCase().includes(search) ||
                  item.producto_nombre?.toLowerCase().includes(search) ||
                  item.producto_ident.toLowerCase().includes(search)
              );
          })
        : items;

    const valueOf = (item: RestockForecastItem) => {
        switch (restockSort.value) {
            case 'provider':
                return item.provider_name?.toLowerCase() ?? item.provider_ident.toLowerCase();
            case 'producto':
                return item.producto_nombre?.toLowerCase() ?? item.producto_ident.toLowerCase();
            case 'avg':
                return Number(item.avg_daily_sales ?? 0);
            case 'stock':
                return Number(item.inventory_on_hand ?? 0);
            case 'cover':
                return item.days_of_cover ?? 0;
            case 'suggested':
            default:
                return Number(item.suggested_order_qty ?? 0);
        }
    };

    filtered.sort((a, b) => {
        const va = valueOf(a);
        const vb = valueOf(b);
        if (typeof va === 'number' && typeof vb === 'number') {
            return (va - vb) * dir;
        }
        return va > vb ? dir : va < vb ? -dir : 0;
    });

    return filtered;
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
            tipoDescuento: 0,
            manual: 0,
            cardFee: 0,
            real: 0,
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
            acc.tipoDescuento += Number(item.provider_discount ?? 0);
            acc.manual += Number(item.manual_discount ?? 0);
            acc.cardFee += Number(item.card_fee ?? 0);
            acc.real += Number(item.real_earning ?? item.expected_earning ?? 0);
            return acc;
        },
        {
            cantidad: 0,
            total: 0,
            tipoDescuento: 0,
            manual: 0,
            cardFee: 0,
            real: 0,
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
            case 'venta':
                return Number(item.idventa ?? item.venta_id ?? 0);
            case 'cantidad':
                return Number(item.cantidad ?? 0);
            case 'precio':
                return Number(item.precio_unitario ?? 0);
            case 'provider_price':
                return Number(item.provider_price ?? 0);
            case 'total':
                return Number(item.total ?? 0);
            case 'provider_discount':
                return Number(item.provider_discount ?? 0);
            case 'manual':
                return Number(item.manual_discount ?? 0);
            case 'card_fee':
                return Number(item.card_fee ?? 0);
            case 'real':
                return Number(item.real_earning ?? item.expected_earning ?? 0);
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
        'ID venta',
        'Cantidad',
        'Precio unitario',
        'Precio proveedor',
        'Total',
        'Desc. proveedor',
        'Desc. manual',
        'Cargo tarjeta',
        'Ganancia real',
        'Tipo proveedor',
        'Método',
        'Vendedor',
        'Promoción',
    ];
    const rows = proveedorModalSortedItems.value.map((item) => [
        item.fecha ?? '',
        item.producto_nombre ?? '',
        item.producto_ident ?? '',
        item.idventa ?? item.venta_id ?? '',
        item.cantidad ?? '',
        item.precio_unitario ?? '',
        item.provider_price ?? '',
        item.total ?? '',
        item.provider_discount ?? '',
        item.manual_discount ?? '',
        item.card_fee ?? '',
        item.real_earning ?? item.expected_earning ?? '',
        item.proveedor_tipo ?? '',
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
        if (val === 'flujo-caja') {
            flujoError.value = '';
            if (!flujoData.value && rangeStart.value) {
                fetchFlujoCajaReport();
            }
        }
        if (val === 'restock') {
            restockError.value = '';
            if (!restockData.value) {
                fetchRestockForecast();
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
        if (selected.value === 'flujo-caja') {
            flujoData.value = null;
            flujoError.value = '';
        }
        if (selected.value === 'restock') {
            restockData.value = null;
            restockError.value = '';
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

watch(
    () => egresosData.value,
    () => {
        egresosSearch.value = '';
        egresosSortColumn.value = 'fecha';
        egresosSortDirection.value = 'desc';
    }
);

watch(
    () => flujoData.value,
    () => {
        flujoSearch.value = '';
        flujoSortColumn.value = 'fecha';
        flujoSortDirection.value = 'asc';
    }
);

watch(
    () => restockData.value,
    () => {
        restockSearch.value = '';
        restockSort.value = 'suggested';
        restockSortDirection.value = 'desc';
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
                    <label class="flex flex-col text-sm text-gray-600" v-if="['caja', 'entradas','caja-condensado','caja-egresos','flujo-caja'].includes(selected)">
                        <span class="font-medium text-gray-700">Fecha inicial</span>
                        <input v-model="rangeStart" type="date"
                            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900" />
                    </label>
                    <label class="flex flex-col text-sm text-gray-600" v-if="['caja', 'entradas','caja-condensado','caja-egresos','flujo-caja'].includes(selected)">
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
                <div v-else-if="cajaData" class="space-y-6">
                    <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-gray-500">
                        <div>
                            Periodo:
                            <span class="font-semibold text-gray-900">{{ cajaData.from_date }}</span>
                            –
                            <span class="font-semibold text-gray-900">{{ cajaData.to_date }}</span>
                        </div>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white/70 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <p class="text-sm font-semibold text-gray-900">Widgets de ventas</p>
                                <p class="text-xs text-gray-500">Resumen rápido del periodo seleccionado.</p>
                            </div>
                            <button type="button"
                                class="inline-flex items-center justify-center rounded-full border border-gray-300 px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50"
                                @click="showCajaWidgets = !showCajaWidgets">
                                {{ showCajaWidgets ? 'Ocultar widgets' : 'Mostrar widgets' }}
                            </button>
                        </div>
                        <transition name="fade">
                            <div v-if="showCajaWidgets" class="mt-4 space-y-4">
                                <div v-if="cajaSummary" class="grid gap-4 text-sm text-gray-600 sm:grid-cols-2 lg:grid-cols-4">
                                    <div class="rounded-lg border border-gray-200 bg-white/80 p-3">
                                        <p class="text-xs uppercase tracking-wide text-gray-500">Ventas</p>
                                        <p class="mt-2 text-2xl font-semibold text-gray-900">{{ cajaSummary.totalVentas }}</p>
                                    </div>
                                    <div class="rounded-lg border border-gray-200 bg-white/80 p-3">
                                        <p class="text-xs uppercase tracking-wide text-gray-500">Total vendido</p>
                                        <p class="mt-2 text-2xl font-semibold text-gray-900">{{ formatCurrency(cajaSummary.totalVenta) }}</p>
                                    </div>
                                    <div class="rounded-lg border border-gray-200 bg-white/80 p-3">
                                        <p class="text-xs uppercase tracking-wide text-gray-500">Total recibido</p>
                                        <p class="mt-2 text-2xl font-semibold text-gray-900">{{ formatCurrency(cajaSummary.totalRecibido) }}</p>
                                    </div>
                                    <div class="rounded-lg border border-gray-200 bg-white/80 p-3">
                                        <p class="text-xs uppercase tracking-wide text-gray-500">Cambio entregado</p>
                                        <p class="mt-2 text-2xl font-semibold text-gray-900">{{ formatCurrency(cajaSummary.totalCambio) }}</p>
                                    </div>
                                </div>
                                <div v-if="cajaSummary?.metodos?.length" class="rounded-xl border border-gray-200 bg-white/70 p-4">
                                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Ingresos por método</h4>
                                    <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                        <div v-for="metodo in cajaSummary.metodos" :key="metodo.metodo"
                                            class="rounded-lg border border-gray-100 bg-white px-3 py-2 text-xs text-gray-600">
                                            <p class="font-semibold text-gray-900">{{ metodo.metodo?.toUpperCase?.() ?? metodo.metodo }}</p>
                                            <p class="text-[11px] text-gray-500">{{ metodo.count }} ventas</p>
                                            <p class="mt-1 text-lg font-semibold text-gray-900">{{ formatCurrency(metodo.total) }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </transition>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white/70 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
                            <p>
                                Ventas encontradas:
                                <span class="font-semibold text-gray-900">{{ filteredCajaVentas.length }}</span>
                            </p>
                            <div class="flex flex-wrap items-center gap-2">
                                <span>
                                    Ordenado por
                                    <span class="font-semibold text-gray-900">{{ cajaSortLabels[cajaSortColumn] }}</span>
                                    ({{ cajaSortDirection === 'asc' ? 'asc' : 'desc' }})
                                </span>
                                <button type="button"
                                    class="rounded-full border border-gray-300 px-3 py-1 text-[11px] text-gray-600 hover:bg-gray-50"
                                    @click="toggleCajaSortDirection">
                                    Cambiar dirección
                                </button>
                                <button type="button"
                                    class="rounded-full border border-gray-300 px-3 py-1 text-[11px] text-gray-600 hover:bg-gray-50"
                                    @click="resetCajaFilters">
                                    Limpiar filtros
                                </button>
                            </div>
                        </div>
                        <div class="mt-3 grid gap-3 md:grid-cols-2 lg:grid-cols-4">
                            <label class="text-xs text-gray-500">
                                <span class="mb-1 block font-medium text-gray-700">Buscar</span>
                                <input v-model="cajaSearch" type="search"
                                    placeholder="Producto, vendedor, método…"
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900" />
                            </label>
                            <label class="text-xs text-gray-500">
                                <span class="mb-1 block font-medium text-gray-700">Método de pago</span>
                                <select v-model="cajaMetodoFilter"
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 focus:border-gray-900 focus:ring-gray-900">
                                    <option value="">Todos</option>
                                    <option v-for="metodo in cajaMetodoOptions" :key="metodo" :value="metodo">
                                        {{ metodo?.toUpperCase?.() ?? metodo }}
                                    </option>
                                </select>
                            </label>
                            <label class="text-xs text-gray-500">
                                <span class="mb-1 block font-medium text-gray-700">Vendedor</span>
                                <select v-model="cajaVendedorFilter"
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 focus:border-gray-900 focus:ring-gray-900">
                                    <option value="">Todos</option>
                                    <option v-for="vendedor in cajaVendedorOptions" :key="vendedor" :value="vendedor">
                                        {{ vendedor }}
                                    </option>
                                </select>
                            </label>
                            <label class="text-xs text-gray-500">
                                <span class="mb-1 block font-medium text-gray-700">Ordenar por</span>
                                <select :value="cajaSortColumn"
                                    @change="setCajaSort(($event.target as HTMLSelectElement).value as CajaSortColumn)"
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 focus:border-gray-900 focus:ring-gray-900">
                                    <option value="fecha">Fecha</option>
                                    <option value="id">ID de venta</option>
                                    <option value="metodo">Método de pago</option>
                                    <option value="vendedor">Vendedor</option>
                                    <option value="total">Total venta</option>
                                </select>
                            </label>
                        </div>
                    </div>

                    <div :class="tableClasses.wrapper">
                        <table :class="tableClasses.table">
                            <thead :class="tableClasses.head">
                                <tr>
                                    <th class="px-3 py-2">
                                        <button type="button" class="flex items-center gap-1" @click="setCajaSort('id')">
                                            Venta
                                            <span v-if="cajaSortColumn === 'id'">{{ cajaSortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        </button>
                                    </th>
                                    <th class="px-3 py-2">
                                        <button type="button" class="flex items-center gap-1" @click="setCajaSort('fecha')">
                                            Fecha y hora
                                            <span v-if="cajaSortColumn === 'fecha'">{{ cajaSortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        </button>
                                    </th>
                                    <th class="px-3 py-2">
                                        <button type="button" class="flex items-center gap-1" @click="setCajaSort('metodo')">
                                            Método
                                            <span v-if="cajaSortColumn === 'metodo'">{{ cajaSortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        </button>
                                    </th>
                                    <th class="px-3 py-2">
                                        <button type="button" class="flex items-center gap-1" @click="setCajaSort('vendedor')">
                                            Vendedor
                                            <span v-if="cajaSortColumn === 'vendedor'">{{ cajaSortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        </button>
                                    </th>
                                    <th class="px-3 py-2 text-right">
                                        <button type="button" class="flex w-full items-center justify-end gap-1" @click="setCajaSort('total')">
                                            Total
                                            <span v-if="cajaSortColumn === 'total'">{{ cajaSortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        </button>
                                    </th>
                                    <th class="px-3 py-2 text-right">Recibido</th>
                                    <th class="px-3 py-2 text-right">Cambio</th>
                                    <th class="px-3 py-2 text-right">Detalle</th>
                                </tr>
                            </thead>
                            <tbody :class="tableClasses.body">
                                <tr v-if="visibleCajaVentas.length === 0">
                                    <td colspan="8" class="px-4 py-6 text-center text-sm text-gray-500">
                                        No hay ventas registradas para el periodo seleccionado.
                                    </td>
                                </tr>
                                <template v-for="venta in visibleCajaVentas" :key="venta.idventa">
                                    <tr :class="tableClasses.row">
                                        <td class="px-3 py-2 font-semibold text-gray-900">#{{ venta.idventa }}</td>
                                        <td class="px-3 py-2 text-sm text-gray-600">{{ formatCajaFecha(venta.fecha) }} · {{ venta.hora }}</td>
                                        <td class="px-3 py-2 capitalize">{{ venta.metodo }}</td>
                                        <td class="px-3 py-2 text-sm text-gray-600">{{ venta.vendedor ?? '—' }}</td>
                                        <td class="px-3 py-2 text-right font-semibold text-gray-900">{{ formatCurrency(venta.totalventa ?? 0) }}</td>
                                        <td class="px-3 py-2 text-right text-sm text-gray-600">{{ formatCurrency(venta.total_recibido ?? 0) }}</td>
                                        <td class="px-3 py-2 text-right text-sm text-gray-600">{{ formatCurrency(venta.cambio ?? 0) }}</td>
                                        <td class="px-3 py-2 text-right">
                                            <button type="button"
                                                class="inline-flex items-center gap-1 rounded-full border border-gray-300 px-3 py-1 text-xs text-gray-700 hover:bg-gray-50"
                                                @click="toggleVenta(venta.idventa)">
                                                {{ expandedVentaIds.has(venta.idventa) ? 'Ocultar' : 'Ver desglose' }}
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="expandedVentaIds.has(venta.idventa)" :class="tableClasses.row">
                                        <td colspan="8" class="bg-gray-50/80 px-4 py-3">
                                            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white/80">
                                                <table class="min-w-full divide-y divide-gray-100 text-xs text-gray-600">
                                                    <thead class="bg-gray-50 text-[11px] uppercase text-gray-500">
                                                        <tr>
                                                            <th class="px-2 py-1 text-left">Producto</th>
                                                            <th class="px-2 py-1 text-left">Proveedor</th>
                                                            <th class="px-2 py-1 text-right">Cant.</th>
                                                            <th class="px-2 py-1 text-right">Precio público</th>
                                                            <th class="px-2 py-1 text-right">Precio proveedor</th>
                                                            <th class="px-2 py-1 text-right">Promo</th>
                                                            <th class="px-2 py-1 text-right">Descuento manual</th>
                                                            <th class="px-2 py-1 text-right">Tarjeta</th>
                                                            <th class="px-2 py-1 text-right">Desc. proveedor</th>
                                                            <th class="px-2 py-1 text-right">Pago proveedor</th>
                                                            <th class="px-2 py-1 text-right">Ganancia admin</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-gray-100">
                                                        <tr v-for="linea in venta.lineas" :key="linea.producto_id + '-' + linea.nombre">
                                                            <td class="px-2 py-1">
                                                                <div class="font-medium text-gray-900">{{ linea.nombre }}</div>
                                                                <p class="text-[11px] text-gray-500">ID: {{ linea.producto_id }}</p>
                                                                <p v-if="linea.free_quantity" class="text-[11px] text-emerald-600">+{{ linea.free_quantity }} gratis</p>
                                                            </td>
                                                            <td class="px-2 py-1">
                                                                <div class="font-medium text-gray-900">{{ linea.provider?.nombre ?? '—' }}</div>
                                                                <div v-if="linea.provider?.tipo"
                                                                    class="mt-1 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px]"
                                                                    :class="providerBadgeInfo(linea.provider?.tipo as any, linea.provider?.porcentaje).className">
                                                                    <span>{{ providerBadgeInfo(linea.provider?.tipo as any, linea.provider?.porcentaje).label }}</span>
                                                                </div>
                                                            </td>
                                                            <td class="px-2 py-1 text-right">{{ linea.quantity }}</td>
                                                            <td class="px-2 py-1 text-right">{{ formatCurrency(linea.unit_price) }}</td>
                                                            <td class="px-2 py-1 text-right">{{ formatCurrency(linea.provider_price ?? 0) }}</td>
                                                            <td class="px-2 py-1 text-right">{{ formatCurrency(linea.promotion_discount_amount) }}</td>
                                                            <td class="px-2 py-1 text-right">{{ formatCurrency(linea.manual_discount_amount) }}</td>
                                                            <td class="px-2 py-1 text-right">{{ formatCurrency(linea.credit_card_discount) }}</td>
                                                            <td class="px-2 py-1 text-right">
                                                                <div class="flex items-center justify-end gap-1">
                                                                    <span v-if="linea.provider_discount_type !== 'normal'">
                                                                        {{ formatCurrency(linea.provider_discount_amount) }}
                                                                    </span>
                                                                    <span v-else>—</span>
                                                                    <span v-if="linea.provider_discount_type !== 'normal'"
                                                                        class="relative inline-flex cursor-help text-[10px] text-gray-500 group">
                                                                        Fórmula
                                                                    <span
                                                                        class="pointer-events-none absolute bottom-full right-0 z-10 mb-1 hidden whitespace-nowrap rounded-md bg-gray-900 px-2 py-1 text-[10px] text-white group-hover:block">
                                                                            {{ providerDiscountTooltip(linea) }}
                                                                    </span>
                                                                </span>
                                                            </div>
                                                            </td>
                                                            <td class="px-2 py-1 text-right">
                                                                <div class="flex items-center justify-end gap-1">
                                                                    <span class="font-semibold text-sky-700">{{ formatCurrency(linea.provider_payment) }}</span>
                                                                    <span class="relative inline-flex cursor-help text-[10px] text-gray-500 group">
                                                                        Fórmula
                                                                    <span
                                                                        class="pointer-events-none absolute bottom-full right-0 z-10 mb-1 hidden whitespace-nowrap rounded-md bg-gray-900 px-2 py-1 text-[10px] text-white group-hover:block">
                                                                            {{ providerPaymentTooltip(linea) }}
                                                                    </span>
                                                                </span>
                                                                </div>
                                                            </td>
                                                            <td class="px-2 py-1 text-right font-semibold text-emerald-700">{{ formatCurrency(linea.admin_earnings) }}</td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="visibleCajaVentas.length < filteredCajaVentas.length" class="flex justify-center">
                        <button type="button"
                            class="rounded-full border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
                            @click="loadMoreCajaVentas">
                            Mostrar más ventas
                        </button>
                    </div>
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
                                    *Ingresos corresponde a la suma de todas las ventas registradas dentro del periodo seleccionado.
                                </p>

                                <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-gray-500">
                                    <p>
                                        Movimientos encontrados:
                                        <span class="font-semibold text-gray-900">{{ filteredEgresos.length }}</span>
                                    </p>
                                    <label class="flex items-center gap-2">
                                        <span class="font-medium text-gray-700">Buscar</span>
                                        <input v-model="egresosSearch" type="search"
                                            class="w-48 rounded border border-gray-300 px-3 py-1 text-xs focus:border-gray-900 focus:ring-gray-900"
                                            placeholder="Descripción, usuario, fecha…" />
                                    </label>
                                </div>

                                <div :class="tableClasses.wrapper">
                                    <table :class="tableClasses.table">
                                        <thead :class="tableClasses.head">
                                            <tr>
                                                <th class="px-3 py-2">
                                                    <button type="button" class="flex items-center gap-1 font-semibold"
                                                        @click="toggleEgresosSort('id')">
                                                        Consecutivo
                                                        <span class="text-[10px]">{{ egresosSortIcon('id') }}</span>
                                                    </button>
                                                </th>
                                                <th class="px-3 py-2">
                                                    <button type="button" class="flex items-center gap-1 font-semibold"
                                                        @click="toggleEgresosSort('fecha')">
                                                        Fecha
                                                        <span class="text-[10px]">{{ egresosSortIcon('fecha') }}</span>
                                                    </button>
                                                </th>
                                                <th class="px-3 py-2">
                                                    <button type="button" class="flex items-center gap-1 font-semibold"
                                                        @click="toggleEgresosSort('descripcion')">
                                                        Descripción
                                                        <span class="text-[10px]">{{ egresosSortIcon('descripcion') }}</span>
                                                    </button>
                                                </th>
                                                <th class="px-3 py-2">
                                                    <button type="button" class="flex items-center gap-1 font-semibold"
                                                        @click="toggleEgresosSort('creado_por')">
                                                        Registró
                                                        <span class="text-[10px]">{{ egresosSortIcon('creado_por') }}</span>
                                                    </button>
                                                </th>
                                                <th class="px-3 py-2 text-right">
                                                    <button type="button"
                                                        class="flex w-full items-center justify-end gap-1 font-semibold"
                                                        @click="toggleEgresosSort('monto')">
                                                        Monto
                                                        <span class="text-[10px]">{{ egresosSortIcon('monto') }}</span>
                                                    </button>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody :class="tableClasses.body">
                                            <tr v-if="filteredEgresos.length === 0">
                                                <td class="px-3 py-6 text-center text-gray-500" colspan="5">
                                                    No se encontraron egresos que coincidan con tu búsqueda.
                                                </td>
                                            </tr>
                                            <tr v-for="mov in filteredEgresos" :key="mov.id" :class="tableClasses.row">
                                                <td class="px-3 py-2 font-semibold text-gray-900">#{{ mov.id }}</td>
                                                <td class="px-3 py-2">{{ mov.fecha }}</td>
                                                <td class="px-3 py-2">
                                                    <p class="font-medium text-gray-900">{{ mov.descripcion }}</p>
                                                </td>
                                                <td class="px-3 py-2">{{ mov.creado_por || '—' }}</td>
                                                <td class="px-3 py-2 text-right font-semibold text-rose-600">
                                                    {{ formatCurrency(mov.monto) }}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <p v-else class="text-xs text-gray-500">Consulta el reporte para ver los movimientos.</p>
                        </div>
                    </template>
                    <template v-else-if="selected === 'flujo-caja'">
                        <div class="space-y-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button"
                                    class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:bg-gray-300"
                                    :disabled="flujoLoading" @click="fetchFlujoCajaReport">
                                    <span v-if="flujoLoading">Consultando…</span>
                                    <span v-else>Consultar flujo</span>
                                </button>
                                <button type="button"
                                    class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:text-gray-400"
                                    :disabled="flujoLoading" @click="downloadFlujoCajaReport">
                                    Descargar CSV
                                </button>
                                <span class="text-xs text-gray-500">Resumen diario del movimiento de caja.</span>
                            </div>

                            <p v-if="flujoError"
                                class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                                {{ flujoError }}
                            </p>

                            <div v-else class="space-y-4">
                                <div v-if="flujoLoading" class="text-xs text-gray-500">Cargando datos…</div>
                                <div v-else-if="flujoData" class="space-y-4">
                                    <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-gray-500">
                                        <div>
                                            Periodo:
                                            <span class="font-semibold text-gray-900">{{ flujoData.from_date }}</span>
                                            –
                                            <span class="font-semibold text-gray-900">{{ flujoData.to_date }}</span>
                                        </div>
                                        <div v-if="flujoResumen"
                                            class="grid grid-cols-2 gap-3 text-[11px] text-gray-500 md:grid-cols-4 lg:grid-cols-5">
                                            <div>
                                                <span class="block font-semibold text-gray-900">{{ flujoResumen.dias }}</span>
                                                <span>Días</span>
                                            </div>
                                            <div>
                                                <span class="block font-semibold text-gray-900">{{ formatCurrency(flujoResumen.ingresos_total) }}</span>
                                                <span>Ingresos</span>
                                            </div>
                                            <div>
                                                <span class="block font-semibold text-gray-900">{{ formatCurrency(flujoResumen.egresos) }}</span>
                                                <span>Egresos</span>
                                            </div>
                                            <div>
                                                <span class="block font-semibold text-gray-900">{{ formatCurrency(flujoResumen.saldo_cierre) }}</span>
                                                <span>Saldo cierre</span>
                                            </div>
                                            <div>
                                                <span class="block font-semibold text-gray-900">{{ formatCurrency(flujoResumen.saldo_inicial) }}</span>
                                                <span>Saldo inicial acumulado</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-gray-500">
                                        <p>
                                            Registros encontrados:
                                            <span class="font-semibold text-gray-900">{{ filteredFlujoItems.length }}</span>
                                        </p>
                                        <label class="flex items-center gap-2">
                                            <span class="font-medium text-gray-700">Buscar</span>
                                            <input v-model="flujoSearch" type="search"
                                                class="w-48 rounded border border-gray-300 px-3 py-1 text-xs focus:border-gray-900 focus:ring-gray-900"
                                                placeholder="Fecha o monto…" />
                                        </label>
                                    </div>

                                    <div :class="tableClasses.wrapper">
                                        <table :class="tableClasses.table">
                                            <thead :class="tableClasses.head">
                                                <tr>
                                                    <th class="px-3 py-2">
                                                        <button type="button" class="flex items-center gap-1 font-semibold"
                                                            @click="toggleFlujoSort('fecha')">
                                                            Fecha
                                                            <span class="text-[10px]">{{ flujoSortIcon('fecha') }}</span>
                                                        </button>
                                                    </th>
                                                    <th class="px-3 py-2 text-right">
                                                        <button type="button"
                                                            class="flex w-full items-center justify-end gap-1 font-semibold"
                                                            @click="toggleFlujoSort('saldo_inicial')">
                                                            Saldo inicial
                                                            <span class="text-[10px]">{{ flujoSortIcon('saldo_inicial') }}</span>
                                                        </button>
                                                    </th>
                                                    <th class="px-3 py-2 text-right">
                                                        <button type="button"
                                                            class="flex w-full items-center justify-end gap-1 font-semibold"
                                                            @click="toggleFlujoSort('efectivo')">
                                                            Efectivo
                                                            <span class="text-[10px]">{{ flujoSortIcon('efectivo') }}</span>
                                                        </button>
                                                    </th>
                                                    <th class="px-3 py-2 text-right">
                                                        <button type="button"
                                                            class="flex w-full items-center justify-end gap-1 font-semibold"
                                                            @click="toggleFlujoSort('transferencia')">
                                                            Transferencia
                                                            <span class="text-[10px]">{{ flujoSortIcon('transferencia') }}</span>
                                                        </button>
                                                    </th>
                                                    <th class="px-3 py-2 text-right">
                                                        <button type="button"
                                                            class="flex w-full items-center justify-end gap-1 font-semibold"
                                                            @click="toggleFlujoSort('tarjeta')">
                                                            Tarjeta
                                                            <span class="text-[10px]">{{ flujoSortIcon('tarjeta') }}</span>
                                                        </button>
                                                    </th>
                                                    <th class="px-3 py-2 text-right">
                                                        <button type="button"
                                                            class="flex w-full items-center justify-end gap-1 font-semibold"
                                                            @click="toggleFlujoSort('ingresos_total')">
                                                            Ingresos
                                                            <span class="text-[10px]">{{ flujoSortIcon('ingresos_total') }}</span>
                                                        </button>
                                                    </th>
                                                    <th class="px-3 py-2 text-right">
                                                        <button type="button"
                                                            class="flex w-full items-center justify-end gap-1 font-semibold"
                                                            @click="toggleFlujoSort('egresos')">
                                                            Egresos
                                                            <span class="text-[10px]">{{ flujoSortIcon('egresos') }}</span>
                                                        </button>
                                                    </th>
                                                    <th class="px-3 py-2 text-right">
                                                        <button type="button"
                                                            class="flex w-full items-center justify-end gap-1 font-semibold"
                                                            @click="toggleFlujoSort('saldo_cierre')">
                                                            Saldo cierre
                                                            <span class="text-[10px]">{{ flujoSortIcon('saldo_cierre') }}</span>
                                                        </button>
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody :class="tableClasses.body">
                                                <tr v-if="filteredFlujoItems.length === 0">
                                                    <td class="px-3 py-6 text-center text-gray-500" colspan="8">
                                                        No hay registros para el periodo seleccionado.
                                                    </td>
                                                </tr>
                                                <tr v-for="row in filteredFlujoItems" :key="row.fecha" :class="tableClasses.row">
                                                    <td class="px-3 py-2 font-semibold text-gray-900">{{ row.fecha }}</td>
                                                    <td class="px-3 py-2 text-right">{{ formatCurrency(row.saldo_inicial) }}</td>
                                                    <td class="px-3 py-2 text-right">{{ formatCurrency(row.efectivo) }}</td>
                                                    <td class="px-3 py-2 text-right">{{ formatCurrency(row.transferencia) }}</td>
                                                    <td class="px-3 py-2 text-right">{{ formatCurrency(row.tarjeta) }}</td>
                                                    <td class="px-3 py-2 text-right font-semibold text-emerald-700">{{ formatCurrency(row.ingresos_total) }}</td>
                                                    <td class="px-3 py-2 text-right font-semibold text-rose-600">{{ formatCurrency(row.egresos) }}</td>
                                                    <td class="px-3 py-2 text-right">
                                                        <template v-if="Number(row.saldo_cierre ?? 0) !== 0">
                                                            <div class="flex items-center justify-end gap-1">
                                                                <span class="font-semibold text-gray-900">{{ formatCurrency(row.saldo_cierre) }}</span>
                                                                <span
                                                                    class="relative inline-flex h-4 w-4 cursor-help items-center justify-center rounded-full border border-gray-300 text-[10px] text-gray-500 group"
                                                                    aria-label="Efectivo al cierre del día">
                                                                    i
                                                                    <span
                                                                        class="pointer-events-none absolute bottom-full right-0 z-10 mb-1 hidden whitespace-nowrap rounded-md bg-gray-900 px-2 py-1 text-[10px] text-white group-hover:block">
                                                                        Efectivo al cierre del día
                                                                    </span>
                                                                </span>
                                                            </div>
                                                        </template>
                                                        <span v-else
                                                            class="inline-flex items-center justify-end gap-1 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] text-amber-800">
                                                            Cierra caja del día para calcular
                                                        </span>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <p v-else class="text-xs text-gray-500">Consulta el reporte para ver los resúmenes diarios.</p>
                            </div>
                        </div>
                    </template>
                    <template v-else-if="selected === 'restock'">
                        <div class="space-y-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button"
                                    class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:bg-gray-300"
                                    :disabled="restockLoading" @click="fetchRestockForecast">
                                    <span v-if="restockLoading">Consultando…</span>
                                    <span v-else>Consultar pronóstico</span>
                                </button>
                                <span class="text-xs text-gray-500">Pronóstico basado en ventas recientes para sugerir restock por proveedor.</span>
                                <label class="flex items-center gap-2 text-xs text-gray-500">
                                    <span class="font-medium text-gray-700">Horizonte</span>
                                    <select :value="restockHorizon"
                                        class="rounded border border-gray-300 px-2 py-1 text-xs focus:border-gray-900 focus:ring-gray-900"
                                        :disabled="restockSavingPref"
                                        @change="changeRestockHorizon(($event.target as HTMLSelectElement).value as RestockHorizonOption)">
                                        <option v-for="opt in restockHorizonOptions" :key="opt.value" :value="opt.value">
                                            {{ opt.label }}
                                        </option>
                                    </select>
                                </label>
                            </div>

                            <p v-if="restockError"
                                class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                                {{ restockError }}
                            </p>

                            <div v-else class="space-y-4">
                                <div v-if="restockLoading" class="text-xs text-gray-500">Cargando datos…</div>
                                <div v-else-if="restockData" class="space-y-4">
                                    <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-gray-500">
                                        <div>
                                            Pronóstico generado el
                                            <span class="font-semibold text-gray-900">{{ restockData.forecast_date }}</span>
                                        </div>
                                        <div v-if="restockSummary"
                                            class="grid grid-cols-2 gap-3 text-[11px] text-gray-500 md:grid-cols-5">
                                            <div>
                                                <span class="block font-semibold text-gray-900">{{ restockSummary.total_items }}</span>
                                                <span>Productos analizados</span>
                                            </div>
                                            <div>
                                                <span class="block font-semibold text-gray-900">{{ restockData.lookback_days }} días</span>
                                                <span>Histórico</span>
                                            </div>
                                            <div>
                                                <span class="block font-semibold text-gray-900">{{ restockData.lead_time_days }} días</span>
                                                <span>Horizonte</span>
                                            </div>
                                            <div>
                                                <span class="block font-semibold text-gray-900">{{ restockData.minimum_inventory_days ?? '—' }} días</span>
                                                <span>Inventario mínimo</span>
                                            </div>
                                            <div>
                                                <span class="block font-semibold text-gray-900">{{ restockSummary.total_suggested }}</span>
                                                <span>Unidades sugeridas*</span>
                                            </div>
                                            <div>
                                                <span class="block font-semibold text-gray-900">{{ restockSummary.avg_daily_sales }}</span>
                                                <span>Promedio diario (u.)</span>
                                            </div>
                                        </div>
                                    </div>
                                    <p class="text-[11px] text-gray-500">*Sumatoria de sugerencias × precio público no incluida.</p>

                                    <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-gray-500">
                                        <label class="flex items-center gap-2">
                                            <span class="font-medium text-gray-700">Buscar</span>
                                            <input v-model="restockSearch" type="search"
                                                class="w-48 rounded border border-gray-300 px-3 py-1 text-xs focus:border-gray-900 focus:ring-gray-900"
                                                placeholder="Proveedor o producto…" />
                                        </label>
                                    </div>

                                    <div :class="tableClasses.wrapper">
                                        <table :class="tableClasses.table">
                                            <thead :class="tableClasses.head">
                                                <tr>
                                                    <th class="px-3 py-2">
                                                        <button type="button" class="flex items-center gap-1 font-semibold"
                                                            @click="toggleRestockSort('provider')">
                                                            Proveedor
                                                            <span class="text-[10px]">{{ restockSortIcon('provider') }}</span>
                                                        </button>
                                                    </th>
                                                    <th class="px-3 py-2">
                                                        <button type="button" class="flex items-center gap-1 font-semibold"
                                                            @click="toggleRestockSort('producto')">
                                                            Producto
                                                            <span class="text-[10px]">{{ restockSortIcon('producto') }}</span>
                                                        </button>
                                                    </th>
                                                    <th class="px-3 py-2 text-right">
                                                        <button type="button"
                                                            class="flex w-full items-center justify-end gap-1 font-semibold"
                                                            @click="toggleRestockSort('avg')">
                                                            Promedio diario
                                                            <span class="text-[10px]">{{ restockSortIcon('avg') }}</span>
                                                        </button>
                                                    </th>
                                                    <th class="px-3 py-2 text-right">
                                                        <button type="button"
                                                            class="flex w-full items-center justify-end gap-1 font-semibold"
                                                            @click="toggleRestockSort('stock')">
                                                            Inventario
                                                            <span class="text-[10px]">{{ restockSortIcon('stock') }}</span>
                                                        </button>
                                                    </th>
                                                    <th class="px-3 py-2 text-right">
                                                        <button type="button"
                                                            class="flex w-full items-center justify-end gap-1 font-semibold"
                                                            @click="toggleRestockSort('cover')">
                                                            Días de cobertura
                                                            <span class="text-[10px]">{{ restockSortIcon('cover') }}</span>
                                                        </button>
                                                    </th>
                                                    <th class="px-3 py-2 text-right">
                                                        <button type="button"
                                                            class="flex w-full items-center justify-end gap-1 font-semibold"
                                                            @click="toggleRestockSort('suggested')">
                                                            Pedido sugerido
                                                            <span class="text-[10px]">{{ restockSortIcon('suggested') }}</span>
                                                        </button>
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody :class="tableClasses.body">
                                                <tr v-if="filteredRestockItems.length === 0">
                                                    <td class="px-3 py-6 text-center text-gray-500" colspan="6">
                                                        No hay recomendaciones para el criterio seleccionado.
                                                    </td>
                                                </tr>
                                                <tr v-for="item in filteredRestockItems" :key="item.provider_ident + '-' + item.producto_ident"
                                                    :class="tableClasses.row">
                                                    <td class="px-3 py-2">
                                                        <div class="font-semibold text-gray-900">{{ item.provider_name ?? 'Proveedor ' + item.provider_ident }}</div>
                                                        <p class="text-[11px] text-gray-500">Ident {{ item.provider_ident }}</p>
                                                    </td>
                                                    <td class="px-3 py-2">
                                                        <div class="font-medium text-gray-900">{{ item.producto_nombre ?? 'Producto ' + item.producto_ident }}</div>
                                                        <p class="text-[11px] text-gray-500">Ident {{ item.producto_ident }}</p>
                                                    </td>
                                                    <td class="px-3 py-2 text-right">{{ Number(item.avg_daily_sales).toFixed(2) }}</td>
                                                    <td class="px-3 py-2 text-right">{{ item.inventory_on_hand }}</td>
                                                    <td class="px-3 py-2 text-right">
                                                        <span v-if="item.days_of_cover !== null">{{ item.days_of_cover }}</span>
                                                        <span v-else>—</span>
                                                    </td>
                                                    <td class="px-3 py-2 text-right font-semibold text-rose-600">
                                                        {{ item.suggested_order_qty }}
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <p v-else class="text-xs text-gray-500">Aún no hay datos de pronóstico. Ejecuta el comando restock:forecast.</p>
                            </div>
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
                                <div class="flex items-center gap-1 text-xs text-gray-500">
                                    <label for="caja-condensado-tipo" class="font-medium text-gray-600">Tipo:</label>
                                    <select
                                        id="caja-condensado-tipo"
                                        v-model="cajaCondensadoTipoFilter"
                                        class="rounded border border-gray-300 bg-white px-2 py-1 text-xs focus:border-gray-900 focus:ring-gray-900"
                                    >
                                        <option v-for="option in cajaCondensadoTipoOptions" :key="option.value" :value="option.value">
                                            {{ option.label }}
                                        </option>
                                    </select>
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
                                                <span class="block font-semibold text-gray-900">{{ formatCurrency(cajaCondensadoResumen.totalVendido) }}</span>
                                                <span>Vendido</span>
                                            </div>
                                            <div>
                                                <span class="block font-semibold text-gray-900">{{ formatCurrency(cajaCondensadoResumen.descuentoTipo) }}</span>
                                                <span>Desc. por tipo</span>
                                            </div>
                                            <div>
                                                <span class="block font-semibold text-gray-900">{{ formatCurrency(cajaCondensadoResumen.descuentoManual) }}</span>
                                                <span>Desc. manual</span>
                                            </div>
                                            <div>
                                                <span class="block font-semibold text-gray-900">{{ formatCurrency(cajaCondensadoResumen.cargosTarjeta) }}</span>
                                                <span>Cargos tarjeta</span>
                                            </div>
                                            <div>
                                                <span class="block font-semibold text-gray-900">{{ formatCurrency(cajaCondensadoResumen.ganancias) }}</span>
                                                <span>Ganancia real</span>
                                            </div>
                                            <div>
                                                <span class="block font-semibold text-gray-900">{{ cajaCondensadoResumen.totalProveedores }}</span>
                                                <span>Proveedores</span>
                                            </div>
                                        </div>
                                    </div>

                                    <template v-if="cajaCondensadoView === 'cards'">
                                        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3" style="border: 1px solid #eee; border-radius: 8px; padding: 8px;">
                                            <article v-for="(proveedor, index) in sortedCajaCondensadoProviders" :key="proveedor.proveedor_ident ?? proveedor.proveedor_id ?? index"
                                                class="space-y-3 rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
                                                <div class="flex items-start justify-between gap-2">
                                                    <div>
                                                        <h3 class="text-base font-semibold text-gray-900">{{ proveedor.proveedor_nombre }}</h3>
                                                        <p class="text-xs text-gray-500">Ident {{ proveedor.proveedor_ident }}</p>
                                                        <span class="mt-1 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px]"
                                                            :class="providerCondensadoBadge(proveedor).className">
                                                            <span>{{ providerCondensadoBadge(proveedor).label }}</span>
                                                        </span>
                                                    </div>
                                                    <button type="button"
                                                        class="inline-flex items-center justify-center rounded border border-gray-300 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50"
                                                        @click="openProveedorModal(proveedor)">
                                                        Movimientos
                                                    </button>
                                                </div>
                                                <dl class="grid grid-cols-2 gap-2 text-[11px] text-gray-600">
                                                    <div>
                                                        <dt class="text-gray-500">Vendido</dt>
                                                        <dd class="font-semibold text-gray-900">{{ formatCurrency(proveedor.total_vendido) }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt class="text-gray-500">Desc. por tipo</dt>
                                                        <dd class="font-semibold text-gray-900">{{ formatCurrency(proveedor.tipo_descuento_total) }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt class="text-gray-500">Desc. manual</dt>
                                                        <dd class="font-semibold text-gray-900">{{ formatCurrency(proveedor.manual_discount_total) }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt class="text-gray-500">Cargos tarjeta</dt>
                                                        <dd class="font-semibold text-gray-900">{{ formatCurrency(proveedor.card_fee_total) }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt class="text-gray-500">Ganancia real</dt>
                                                        <dd class="font-semibold text-gray-900">{{ formatCurrency(proveedor.real_earning ?? proveedor.expected_earning) }}</dd>
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
                                                        <span>Desc. proveedor</span>
                                                        <span class="font-semibold text-gray-900">{{ formatCurrency(providerItemTotals(proveedor).tipoDescuento) }}</span>
                                                    </div>
                                                    <div class="flex justify-between">
                                                        <span>Desc. manual</span>
                                                        <span class="font-semibold text-gray-900">{{ formatCurrency(providerItemTotals(proveedor).manual) }}</span>
                                                    </div>
                                                    <div class="flex justify-between">
                                                        <span>Cargo tarjeta</span>
                                                        <span class="font-semibold text-gray-900">{{ formatCurrency(providerItemTotals(proveedor).cardFee) }}</span>
                                                    </div>
                                                    <div class="flex justify-between">
                                                        <span>Ganancia real</span>
                                                        <span class="font-semibold text-gray-900">{{ formatCurrency(providerItemTotals(proveedor).real) }}</span>
                                                    </div>
                                                </div>
                                            </article>
                                            <div v-if="filteredCajaCondensadoProviders.length === 0"
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
                                                        <th class="px-3 py-2 text-left">
                                                            <button type="button" class="flex items-center gap-1 font-semibold" @click="toggleCajaCondensadoSort('proveedor')">
                                                                Proveedor
                                                                <span class="text-[10px]">{{ cajaCondensadoSortIcon('proveedor') }}</span>
                                                            </button>
                                                        </th>
                                                        <th class="px-3 py-2">
                                                            <button type="button" class="flex items-center gap-1 font-semibold" @click="toggleCajaCondensadoSort('ident')">
                                                                Ident
                                                                <span class="text-[10px]">{{ cajaCondensadoSortIcon('ident') }}</span>
                                                            </button>
                                                        </th>
                                                        <th class="px-3 py-2">Tipo</th>
                                                        <th class="px-3 py-2 text-right">
                                                            <button type="button" class="flex items-center gap-1 font-semibold" @click="toggleCajaCondensadoSort('ventas')">
                                                                Ventas brutas
                                                                <span class="text-[10px]">{{ cajaCondensadoSortIcon('ventas') }}</span>
                                                            </button>
                                                        </th>
                                                        <th class="px-3 py-2 text-right">
                                                            <button type="button" class="flex items-center gap-1 font-semibold" @click="toggleCajaCondensadoSort('tipo_descuento')">
                                                                Desc. proveedor
                                                                <span class="text-[10px]">{{ cajaCondensadoSortIcon('tipo_descuento') }}</span>
                                                            </button>
                                                        </th>
                                                        <th class="px-3 py-2 text-right">
                                                            <button type="button" class="flex items-center gap-1 font-semibold" @click="toggleCajaCondensadoSort('manual_descuento')">
                                                                Desc. manual
                                                                <span class="text-[10px]">{{ cajaCondensadoSortIcon('manual_descuento') }}</span>
                                                            </button>
                                                        </th>
                                                        <th class="px-3 py-2 text-right">
                                                            <button type="button" class="flex items-center gap-1 font-semibold" @click="toggleCajaCondensadoSort('card_fee')">
                                                                Cargos tarjeta
                                                                <span class="text-[10px]">{{ cajaCondensadoSortIcon('card_fee') }}</span>
                                                            </button>
                                                        </th>
                                                        <th class="px-3 py-2 text-right">
                                                            <button type="button" class="flex items-center gap-1 font-semibold" @click="toggleCajaCondensadoSort('real')">
                                                                Ganancia real
                                                                <span class="text-[10px]">{{ cajaCondensadoSortIcon('real') }}</span>
                                                            </button>
                                                        </th>
                                                        <th class="px-3 py-2 text-right">Movimientos</th>
                                                    </tr>
                                                </thead>
                                                <tbody :class="tableClasses.body">
                                                    <tr v-for="(proveedor, index) in sortedCajaCondensadoProviders" :key="proveedor.proveedor_ident ?? proveedor.proveedor_id ?? index" :class="tableClasses.row">
                                                        <td class="px-3 py-2 font-semibold text-gray-900">{{ proveedor.proveedor_nombre }}</td>
                                                        <td class="px-3 py-2">{{ proveedor.proveedor_ident }}</td>
                                                        <td class="px-3 py-2">
                                                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px]"
                                                                :class="providerCondensadoBadge(proveedor).className">
                                                                {{ providerCondensadoBadge(proveedor).label }}
                                                            </span>
                                                        </td>
                                                        <td class="px-3 py-2 text-right">{{ formatCurrency(proveedor.total_vendido) }}</td>
                                                        <td class="px-3 py-2 text-right">{{ formatCurrency(proveedor.tipo_descuento_total) }}</td>
                                                        <td class="px-3 py-2 text-right">{{ formatCurrency(proveedor.manual_discount_total) }}</td>
                                                        <td class="px-3 py-2 text-right">{{ formatCurrency(proveedor.card_fee_total) }}</td>
                                                        <td class="px-3 py-2 text-right">{{ formatCurrency(proveedor.real_earning ?? proveedor.expected_earning) }}</td>
                                                        <td class="px-3 py-2 text-right">
                                                            <button type="button"
                                                                class="inline-flex items-center justify-center rounded border border-gray-300 px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50"
                                                                @click="openProveedorModal(proveedor)">
                                                                Ver movimientos
                                                            </button>
                                                        </td>
                                                    </tr>
                                                    <tr v-if="sortedCajaCondensadoProviders.length === 0">
                                                        <td :class="tableClasses.emptyRow" colspan="9">
                                                            No se encontraron proveedores en el periodo seleccionado.
                                                        </td>
                                                    </tr>
                                                </tbody>
                                                <tfoot v-if="cajaCondensadoResumen" class="bg-gray-100 text-[11px] uppercase tracking-wide text-gray-600">
                                                    <tr>
                                                        <td class="px-3 py-2" colspan="3">Totales</td>
                                                        <td class="px-3 py-2 text-right">{{ formatCurrency(cajaCondensadoResumen.ventasBrutas) }}</td>
                                                        <td class="px-3 py-2 text-right">{{ formatCurrency(cajaCondensadoResumen.descuentos) }}</td>
                                                        <td class="px-3 py-2 text-right">{{ formatCurrency(cajaCondensadoResumen.descuentoManual) }}</td>
                                                        <td class="px-3 py-2 text-right">{{ formatCurrency(cajaCondensadoResumen.cargosTarjeta) }}</td>
                                                        <td class="px-3 py-2 text-right">{{ formatCurrency(cajaCondensadoResumen.ganancias) }}</td>
                                                        <td class="px-3 py-2" colspan="2"></td>
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
                                                    <span v-if="item.costo_inventario !== null">
                                                        {{ formatCurrency(item.costo_inventario) }}
                                                    </span>
                                                    <span v-else>—</span>
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
                <div class="relative z-10 w-full max-w-6xl max-h-[90vh] overflow-hidden rounded-2xl bg-white shadow-xl flex flex-col" role="dialog" aria-modal="true">
                    <header class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4">
                        <div>
                            <p class="text-sm uppercase tracking-wide text-gray-500">Movimientos por proveedor</p>
                            <h3 class="text-lg font-semibold text-gray-900">
                                {{ proveedorModalData?.proveedor_nombre }}
                            </h3>
                            <p class="text-xs text-gray-500">Ident {{ proveedorModalData?.proveedor_ident }}</p>
                            <span v-if="proveedorModalData" class="mt-1 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px]"
                                :class="providerCondensadoBadge(proveedorModalData).className">
                                {{ providerCondensadoBadge(proveedorModalData).label }}
                            </span>
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
                        <div class="grid gap-3 text-xs text-gray-600 sm:grid-cols-6">
                            <div>
                                <span class="block font-semibold text-gray-900">{{ proveedorModalTotals.cantidad }}</span>
                                <span>Unidades</span>
                            </div>
                            <div>
                                <span class="block font-semibold text-gray-900">{{ formatCurrency(proveedorModalTotals.total) }}</span>
                                <span>Ventas</span>
                            </div>
                            <div>
                                <span class="block font-semibold text-gray-900">{{ formatCurrency(proveedorModalTotals.tipoDescuento) }}</span>
                                <span>Desc. proveedor</span>
                            </div>
                            <div>
                                <span class="block font-semibold text-gray-900">{{ formatCurrency(proveedorModalTotals.manual) }}</span>
                                <span>Desc. manual</span>
                            </div>
                            <div>
                                <span class="block font-semibold text-gray-900">{{ formatCurrency(proveedorModalTotals.cardFee) }}</span>
                                <span>Cargo tarjeta</span>
                            </div>
                            <div>
                                <span class="block font-semibold text-gray-900">{{ formatCurrency(proveedorModalTotals.real) }}</span>
                                <span>Ganancia real</span>
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
                            <div :class="`${tableClasses.wrapper} text-[11px]`">
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
                                            <th class="px-3 py-2">
                            <button type="button" class="flex items-center gap-1 font-semibold" @click="toggleProveedorModalSort('venta')">
                                ID venta
                                <span class="text-[10px]">{{ proveedorModalSortIcon('venta') }}</span>
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
                                                <button type="button" class="flex w-full items-center justify-end gap-1 font-semibold" @click="toggleProveedorModalSort('provider_price')">
                                                    Precio proveedor
                                                    <span class="text-[10px]">{{ proveedorModalSortIcon('provider_price') }}</span>
                                                </button>
                                            </th>
                                            <th class="px-3 py-2 text-right">
                                                <button type="button" class="flex w-full items-center justify-end gap-1 font-semibold" @click="toggleProveedorModalSort('total')">
                                                    Total
                                                    <span class="text-[10px]">{{ proveedorModalSortIcon('total') }}</span>
                                                </button>
                                            </th>
                                            <th class="px-3 py-2 text-right">
                                                <button type="button" class="flex w-full items-center justify-end gap-1 font-semibold" @click="toggleProveedorModalSort('provider_discount')">
                                                    Desc. proveedor
                                                    <span class="text-[10px]">{{ proveedorModalSortIcon('provider_discount') }}</span>
                                                </button>
                                            </th>
                                            <th class="px-3 py-2 text-right">
                                                <button type="button" class="flex w-full items-center justify-end gap-1 font-semibold" @click="toggleProveedorModalSort('manual')">
                                                    Desc. manual
                                                    <span class="text-[10px]">{{ proveedorModalSortIcon('manual') }}</span>
                                                </button>
                                            </th>
                                            <th class="px-3 py-2 text-right">
                                                <button type="button" class="flex w-full items-center justify-end gap-1 font-semibold" @click="toggleProveedorModalSort('card_fee')">
                                                    Cargo tarjeta
                                                    <span class="text-[10px]">{{ proveedorModalSortIcon('card_fee') }}</span>
                                                </button>
                                            </th>
                                            <th class="px-3 py-2 text-right">
                                                <button type="button" class="flex w-full items-center justify-end gap-1 font-semibold" @click="toggleProveedorModalSort('real')">
                                                    Ganancia real
                                                    <span class="text-[10px]">{{ proveedorModalSortIcon('real') }}</span>
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
                                            <td :class="tableClasses.emptyRow" colspan="15">
                                                No hay movimientos para este proveedor.
                                            </td>
                                        </tr>
                                        <tr v-for="item in proveedorModalSortedItems" :key="item.ventadesg_id" :class="tableClasses.row">
                                            <td class="px-3 py-2">{{ item.fecha }}</td>
                                            <td class="px-3 py-2">{{ item.producto_nombre }}</td>
                                            <td class="px-3 py-2">{{ item.producto_ident }}</td>
                                            <td class="px-3 py-2">{{ item.idventa }}</td>
                                            <td class="px-3 py-2 text-right">{{ item.cantidad }}</td>
                                            <td class="px-3 py-2 text-right">{{ formatCurrency(item.precio_unitario) }}</td>
                                            <td class="px-3 py-2 text-right">{{ formatCurrency(item.provider_price) }}</td>
                                            <td class="px-3 py-2 text-right">{{ formatCurrency(item.total) }}</td>
                                            <td class="px-3 py-2 text-right">{{ formatCurrency(item.provider_discount) }}</td>
                                            <td class="px-3 py-2 text-right">{{ formatCurrency(item.manual_discount) }}</td>
                                            <td class="px-3 py-2 text-right">{{ formatCurrency(item.card_fee) }}</td>
                                            <td class="px-3 py-2 text-right">
                                                <div class="flex items-center justify-end gap-1">
                                                    <span>{{ formatCurrency(item.real_earning ?? item.expected_earning) }}</span>
                                                    <span
                                                        class="relative inline-flex h-4 w-4 cursor-help items-center justify-center rounded-full border border-gray-300 text-[10px] text-gray-500 group"
                                                        aria-label="Ver fórmula de ganancia real">
                                                        i
                                                        <span
                                                            class="pointer-events-none absolute bottom-full right-0 z-10 mb-1 hidden whitespace-nowrap rounded-md bg-gray-900 px-2 py-1 text-[10px] text-white group-hover:block">
                                                            {{ formatProviderEarningTooltip(item) }}
                                                        </span>
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="px-3 py-2 capitalize">{{ item.metodo }}</td>
                                            <td class="px-3 py-2">{{ item.vendedor }}</td>
                                            <td class="px-3 py-2">{{ item.promotion ?? 'normal' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </transition>
    </AppLayout>
</template>
