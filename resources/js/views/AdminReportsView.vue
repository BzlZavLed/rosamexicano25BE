<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { jsPDF } from 'jspdf';
import AppLayout from '../components/layout/AppLayout.vue';
import { listProveedoresAll, type Proveedor } from '../api/proveedores';
import {
    getCajaReport,
    getEntradasReport,
    getInventarioReport,
    downloadInventarioReport,
    getCajaProveedoresReport,
    getEgresosCajaReport,
    getFlujoCajaReport,
    getRestockForecastReport,
    updateRestockPreference,
    getMensualidadReport,
    getCancelacionesReport,
    type CajaReportResponse,
    type CajaReportVenta,
    type CajaReportLine,
    type EgresosCajaReportResponse,
    type EgresoCajaMovimiento,
    type MensualidadReportResponse,
    type MensualidadReportItem,
    type EntradasReportResponse,
    type CajaProveedoresResponse,
    type CajaProveedorGroup,
    type CajaProveedorItem,
    type InventarioRow,
    type ProductosPagination,
    type FlujoCajaResponse,
    type FlujoCajaRow,
    type RestockForecastResponse,
    type RestockForecastItem,
    type RestockHorizon,
    type CancelacionesReportResponse,
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
    const qty = Number(linea.quantity ?? 0);
    const providerPrice = Number(linea.provider_price ?? 0);
    const manual = Number(linea.manual_discount_amount ?? 0);
    const promo = Number(linea.promotion_discount_amount ?? 0);
    const card = Number(linea.credit_card_discount ?? 0);
    const providerBase = providerPrice * qty;

    if (linea.provider_discount_type === 'porcentaje') {
        const pct = providerPercentageRate(linea);
        const adjustedBase = Math.max(0, providerBase - promo - manual - card);
        const pctAmount = adjustedBase * pct;
        const parts = [
            `(${formatCurrency(providerPrice)} × ${qty})`,
            promo > 0 ? `− ${formatCurrency(promo)} (promo)` : null,
            manual > 0 ? `− ${formatCurrency(manual)} (manual)` : null,
            card > 0 ? `− ${formatCurrency(card)} (tarjeta)` : null,
        ].filter(Boolean);
        return `${parts.join(' ')} = ${formatCurrency(adjustedBase)} → × ${(pct * 100).toFixed(0)}% = ${formatCurrency(pctAmount)}`;
    }
    if (linea.provider_discount_type === 'consigna') {
        const unit = Number(linea.unit_price ?? 0);
        const baseDiff = Math.max(0, (unit - providerPrice) * qty);
        return `(${formatCurrency(unit)} − ${formatCurrency(providerPrice)}) × ${qty} = ${formatCurrency(baseDiff)}`;
    }
  
    return 'Sin descuento proveedor';
}

function providerPercentageRate(linea: CajaReportLine): number {
    const rawPct = linea.provider?.porcentaje ?? 20;
    const pct = Number(rawPct);
    if (!Number.isFinite(pct) || pct <= 0) return 0.2;
    return Math.min(pct, 100) / 100;
}

function providerPaymentTooltip(linea: CajaReportLine, metodo?: string): string {
    const qty = Number(linea.quantity ?? 0);
    const publicTotal = Number(linea.public_total ?? 0);
    const providerPrice = Number(linea.provider_price ?? 0);
    const card = metodo === 'tarjeta' ? (linea.credit_card_discount ?? 0) : 0;
    const manual = Number(linea.manual_discount_amount ?? 0);
    const promo = Number(linea.promotion_discount_amount ?? 0);
    const providerBase = providerPrice * qty;
    const tooltipBase = linea.provider_discount_type === 'consigna' ? publicTotal : providerBase;
    const baseLabel =
        linea.provider_discount_type === 'consigna'
            ? `${formatCurrency(publicTotal)} total venta`
            : `(${formatCurrency(providerPrice)} × ${qty}) = ${formatCurrency(providerBase)}`;
    const discountParts = [
        baseLabel,
        promo > 0 ? `− ${formatCurrency(promo)} (promo)` : null,
        manual > 0 ? `− ${formatCurrency(manual)} (manual)` : null,
        card > 0 ? `− ${formatCurrency(card)} (tarjeta)` : null,
    ].filter(Boolean);
    const tooltipNet = Math.max(0, tooltipBase - promo - manual - card);

    if (linea.provider_discount_type === 'consigna' || linea.provider_discount_type === 'porcentaje') {
        if (linea.provider_discount_type === 'porcentaje') {
            const pct = providerPercentageRate(linea);
            const pctAmount = tooltipNet * pct;
            const totalGeneral = Math.max(0, tooltipNet - pctAmount);
            const firstStage = `${discountParts.join(' ')} = ${formatCurrency(tooltipNet)}`;
            const secondStage =
                pctAmount > 0
                    ? `− ${formatCurrency(pctAmount)} (${(pct * 100).toFixed(0)}% proveedor) = ${formatCurrency(totalGeneral)}`
                    : `= ${formatCurrency(totalGeneral)}`;
            return `${firstStage} → ${secondStage}`;
        }

        const consignaDiscount = Math.max(0, publicTotal - providerBase);
        const tooltipPayment = Math.max(0, tooltipNet - consignaDiscount);
        const firstStage = `${discountParts.join(' ')} = ${formatCurrency(tooltipNet)}`;
        const secondStage = consignaDiscount > 0
            ? `− ${formatCurrency(consignaDiscount)} (desc. proveedor) = ${formatCurrency(tooltipPayment)}`
            : `= ${formatCurrency(tooltipPayment)}`;
        return `${firstStage} → ${secondStage}`;
    }

    const totalGeneral = Math.max(0, publicTotal - manual - card);
    const parts = [
        `${formatCurrency(publicTotal)} total venta`,
        manual > 0 ? `− ${formatCurrency(manual)} (manual)` : null,
        card > 0 ? `− ${formatCurrency(card)} (tarjeta)` : null,
    ].filter(Boolean);
    return `${parts.join(' ')} = ${formatCurrency(totalGeneral)}`;
}

function cajaLineProviderPayment(linea: CajaReportLine, metodo?: string) {

    const qty = Number(linea.quantity ?? 0);
    const publicTotal = Number(linea.public_total ?? 0);
    const promo = Number(linea.promotion_discount_amount ?? 0);
    const providerPrice = Number(linea.provider_price ?? 0);
    const card = metodo === 'tarjeta' ? (linea.credit_card_discount ?? 0) : 0;
    const manual = Number(linea.manual_discount_amount ?? 0);
    const providerBase = providerPrice * qty;
    const adjustedBase = Math.max(0, providerBase - promo - manual - card);

    if (linea.provider_discount_type === 'porcentaje') {
        const pct = providerPercentageRate(linea);
        const pctAmount = adjustedBase * pct;
        return Math.max(0, adjustedBase - pctAmount);
    }
    if (linea.provider_discount_type === 'consigna') {
        return adjustedBase;
    }
    return Math.max(0, publicTotal - manual - card);
}





function formatMonthLabel(value?: string | null) {
    if (!value) return '--';
    try {
        const normalized = value.length >= 7 ? value.slice(0, 7) : value;
        const [year, month] = normalized.split('-').map(Number);
        if (year && month) {
            const formatter = new Intl.DateTimeFormat('es-MX', { month: 'long', year: 'numeric' });
            return formatter.format(new Date(year, month - 1, 1));
        }
    } catch {
        /* ignore */
    }
    return value;
}

function mensualidadGroupTotals(items: MensualidadReportItem[]) {
    return items.reduce(
        (acc, item) => {
            acc.importe += Number(item.importe ?? 0);
            acc.pagado += Number(item.cantidad_pago ?? 0);
            acc.restante += Number(item.restante ?? 0);
            return acc;
        },
        { importe: 0, pagado: 0, restante: 0 }
    );
}

const inventarioMarcaSelectedProvider = computed(() => {
    const ident = inventarioMarcaSelectedProviderIdent.value;
    if (!ident) return null;
    return inventarioMarcaProviders.value.find((provider) => provider.ident === ident) ?? null;
});
const inventarioMarcaTotalItems = computed(() => inventarioMarcaPagination.value?.total ?? inventarioMarcaItems.value.length);
const inventarioMarcaTotalPages = computed(() => inventarioMarcaPagination.value?.last_page ?? 1);
const inventarioMarcaPageStart = computed(() => {
    if (!inventarioMarcaTotalItems.value) return 0;
    return (inventarioMarcaPage.value - 1) * inventarioMarcaPerPage.value + 1;
});
const inventarioMarcaPageEnd = computed(() => {
    if (!inventarioMarcaTotalItems.value) return 0;
    return Math.min(inventarioMarcaPage.value * inventarioMarcaPerPage.value, inventarioMarcaTotalItems.value);
});

async function fetchInventarioMarcaProviders() {
    if (inventarioMarcaProvidersLoading.value) return;
    inventarioMarcaProvidersLoading.value = true;
    inventarioMarcaProvidersError.value = '';
    try {
        const providers = await listProveedoresAll();
        inventarioMarcaProviders.value = [...providers].sort((a, b) => a.nombre.localeCompare(b.nombre));
    } catch (err: any) {
        inventarioMarcaProvidersError.value =
            err?.response?.data?.message || err?.message || 'No se pudo cargar la lista de proveedores.';
    } finally {
        inventarioMarcaProvidersLoading.value = false;
    }
}

async function fetchInventarioMarca() {
    const providerIdent = inventarioMarcaSelectedProviderIdent.value;
    if (!providerIdent) {
        inventarioMarcaError.value = 'Selecciona una marca para consultar inventario.';
        inventarioMarcaItems.value = [];
        inventarioMarcaPagination.value = null;
        return;
    }

    inventarioMarcaLoading.value = true;
    inventarioMarcaError.value = '';
    try {
        const response = await getInventarioReport({
            proveedor_id: providerIdent,
            q: inventarioMarcaSearch.value.trim() || undefined,
            page: inventarioMarcaPage.value,
            per_page: inventarioMarcaPerPage.value,
            sort: inventarioMarcaSort.value,
            direction: inventarioMarcaSortDirection.value,
        });
        inventarioMarcaItems.value = response.data ?? [];
        inventarioMarcaPagination.value = response.pagination ?? null;
    } catch (err: any) {
        inventarioMarcaError.value =
            err?.response?.data?.message || err?.message || 'No se pudo cargar el inventario.';
        inventarioMarcaItems.value = [];
        inventarioMarcaPagination.value = null;
    } finally {
        inventarioMarcaLoading.value = false;
    }
}

function resetInventarioMarcaPagination() {
    inventarioMarcaPage.value = 1;
}

function goInventarioMarcaPrevPage() {
    if (inventarioMarcaPage.value <= 1) return;
    inventarioMarcaPage.value -= 1;
    fetchInventarioMarca();
}

function goInventarioMarcaNextPage() {
    if (inventarioMarcaPage.value >= inventarioMarcaTotalPages.value) return;
    inventarioMarcaPage.value += 1;
    fetchInventarioMarca();
}

function updateInventarioMarcaPerPage(value: number) {
    inventarioMarcaPerPage.value = value;
    resetInventarioMarcaPagination();
    fetchInventarioMarca();
}

function toggleInventarioMarcaSort(column: 'precio' | 'existencia' | 'valor') {
    if (inventarioMarcaSort.value === column) {
        inventarioMarcaSortDirection.value = inventarioMarcaSortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        inventarioMarcaSort.value = column;
        inventarioMarcaSortDirection.value = 'desc';
    }
    resetInventarioMarcaPagination();
    fetchInventarioMarca();
}

function inventarioMarcaSortIcon(column: 'precio' | 'existencia' | 'valor') {
    if (inventarioMarcaSort.value !== column) return '';
    return inventarioMarcaSortDirection.value === 'asc' ? '▲' : '▼';
}

async function fetchAllInventarioMarcaItems() {
    const providerIdent = inventarioMarcaSelectedProviderIdent.value;
    if (!providerIdent) return [];

    const perPage = 200;
    let page = 1;
    const rows: InventarioRow[] = [];

    while (true) {
        const response = await getInventarioReport({
            proveedor_id: providerIdent,
            q: inventarioMarcaSearch.value.trim() || undefined,
            page,
            per_page: perPage,
            sort: inventarioMarcaSort.value,
            direction: inventarioMarcaSortDirection.value,
        });
        rows.push(...(response.data ?? []));
        const pagination = response.pagination;
        if (!pagination || pagination.current_page >= pagination.last_page) break;
        page += 1;
    }

    return rows;
}

async function downloadInventarioMarcaCsv() {
    if (inventarioMarcaDownloadLoading.value) return;
    inventarioMarcaDownloadLoading.value = true;
    try {
        const providerIdent = inventarioMarcaSelectedProviderIdent.value;
        if (!providerIdent) throw new Error('Selecciona una marca para descargar.');
        const blob = await downloadInventarioReport({
            proveedor_id: providerIdent,
            q: inventarioMarcaSearch.value.trim() || undefined,
            sort: inventarioMarcaSort.value,
            direction: inventarioMarcaSortDirection.value,
        });
        const url = URL.createObjectURL(blob);
        const now = new Date();
        const pad = (num: number) => String(num).padStart(2, '0');
        const providerName = inventarioMarcaSelectedProvider.value?.nombre ?? 'marca';
        const filename = `inventario-por-marca-${providerName}-${now.getFullYear()}${pad(
            now.getMonth() + 1
        )}${pad(now.getDate())}-${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}.csv`;
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    } catch (err: any) {
        const message = err?.response?.data?.message || err?.message || 'No se pudo descargar el CSV.';
        window.alert(message);
    } finally {
        inventarioMarcaDownloadLoading.value = false;
    }
}

type InventarioMarcaPdfColumnKey = 'producto' | 'descripcion' | 'precio' | 'existencia' | 'importe';

const inventarioMarcaPdfColumns: Array<{
    key: InventarioMarcaPdfColumnKey;
    title: string;
    width: number;
    align?: 'left' | 'right';
}> = [
    { key: 'producto', title: 'Producto', width: 52 },
    { key: 'descripcion', title: 'Descripcion', width: 70 },
    { key: 'precio', title: 'Precio', width: 22, align: 'right' },
    { key: 'existencia', title: 'Existencia', width: 20, align: 'right' },
    { key: 'importe', title: 'Valor', width: 24, align: 'right' },
];

function buildInventarioMarcaPdf(items: InventarioRow[]) {
    const doc = new jsPDF({ unit: 'mm' });
    const marginX = 12;
    const marginY = 16;
    const rowPadding = 2;
    const lineHeight = 4;
    const pageHeight = doc.internal.pageSize.getHeight();
    const totalWidth = inventarioMarcaPdfColumns.reduce((sum, col) => sum + col.width, 0);
    const columnPositions: number[] = [];
    inventarioMarcaPdfColumns.forEach((_, idx) => {
        const last = columnPositions[idx - 1] ?? marginX;
        const previousWidth = inventarioMarcaPdfColumns[idx - 1]?.width ?? 0;
        columnPositions.push(idx === 0 ? marginX : last + previousWidth);
    });

    let currentY = marginY;

    const drawHeader = () => {
        doc.setFontSize(12);
        doc.setTextColor(30);
        doc.text('Inventario por marca', marginX, currentY);
        currentY += 6;
        doc.setFontSize(9);
        const providerName = inventarioMarcaSelectedProvider.value?.nombre ?? 'Sin proveedor';
        doc.text(`Marca: ${providerName}`, marginX, currentY);
        currentY += 5;
        doc.text(`Generado: ${new Date().toLocaleString('es-MX')}`, marginX, currentY);
        currentY += 5;
    };

    const drawTableHeader = () => {
        doc.setFillColor(245, 245, 245);
        doc.rect(marginX, currentY, totalWidth, 7, 'F');
        doc.setFontSize(8);
        doc.setTextColor(90);
        inventarioMarcaPdfColumns.forEach((col, idx) => {
            const x = (columnPositions[idx] ?? marginX) + 1;
            doc.text(col.title, x, currentY + 5);
        });
        doc.setTextColor(20);
        currentY += 7;
    };

    const ensureSpace = (height: number) => {
        if (currentY + height > pageHeight - marginY) {
            doc.addPage();
            currentY = marginY;
            drawTableHeader();
        }
    };

    drawHeader();
    drawTableHeader();
    doc.setFontSize(8);

    items.forEach((item) => {
        const values = {
            producto: item.producto_nombre ?? (item as any)?.producto?.nombre ?? '',
            descripcion: item.producto_descripcion ?? (item as any)?.producto?.descripcion ?? '',
            precio: formatCurrency(Number(item.precio ?? 0)),
            existencia: String(Number(item.existencia ?? 0)),
            importe: formatCurrency(Number(item.costo_inventario ?? 0)),
        };
        const wrappedLines = inventarioMarcaPdfColumns.map((col) => {
            const value = values[col.key] ?? '';
            return col.key === 'descripcion'
                ? doc.splitTextToSize(value, col.width - 2)
                : [value];
        });
        const maxLines = Math.max(...wrappedLines.map((lines) => lines.length), 1);
        const rowHeight = maxLines * lineHeight + rowPadding * 2;
        ensureSpace(rowHeight);
        wrappedLines.forEach((lines, idx) => {
            const col = inventarioMarcaPdfColumns[idx];
            if (!col) return;
            const x = columnPositions[idx] ?? marginX;
            const align = col.align ?? 'left';
            const startY = currentY + rowPadding + lineHeight - 1;
            lines.forEach((line: string, lineIndex: number) => {
                const textY = startY + lineIndex * lineHeight;
                const textX = align === 'right' ? x + col.width - 1 : x + 1;
                doc.text(String(line ?? ''), textX, textY, { align: align as 'left' | 'right' });
            });
        });
        currentY += rowHeight;
    });

    return doc;
}

async function downloadInventarioMarcaPdf() {
    if (inventarioMarcaPdfLoading.value) return;
    inventarioMarcaPdfLoading.value = true;
    try {
        const items = await fetchAllInventarioMarcaItems();
        const doc = buildInventarioMarcaPdf(items);
        const providerName = inventarioMarcaSelectedProvider.value?.nombre ?? 'marca';
        doc.save(`inventario-por-marca-${providerName}.pdf`);
    } catch (err: any) {
        const message = err?.response?.data?.message || err?.message || 'No se pudo generar el PDF.';
        window.alert(message);
    } finally {
        inventarioMarcaPdfLoading.value = false;
    }
}


type ReportType =
    | 'caja'
    | 'entradas'
    | 'inventario-marca'
    | 'caja-condensado'
    | 'caja-egresos'
    | 'flujo-caja'
    | 'restock'
    | 'mensualidad'
    | 'cancelaciones';

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

const groupedOptions: Array<{ group: string; options: Array<{ value: ReportType; label: string }> }> = [
    {
        group: 'Caja',
        options: [
            { value: 'caja', label: 'Caja' },
            { value: 'caja-condensado', label: 'Caja condensado' },
            { value: 'caja-egresos', label: 'Egresos de caja' },
            { value: 'flujo-caja', label: 'Flujo de caja' },
        ],
    },
    {
        group: 'Inventario',
        options: [
            { value: 'entradas', label: 'Entradas' },
            { value: 'inventario-marca', label: 'Inventario por marca' },
            { value: 'restock', label: 'Alertas de restock' },
        ],
    },
    {
        group: 'Proveedores',
        options: [{ value: 'mensualidad', label: 'Mensualidad' }],
    },
    {
        group: 'Administrativo',
        options: [{ value: 'cancelaciones', label: 'Cancelaciones' }],
    },
];

type RestockHorizonOption = RestockHorizon;
const restockHorizonOptions: Array<{ value: RestockHorizonOption; label: string }> = [
    { value: '2w', label: 'Próximas 2 semanas' },
    { value: '4w', label: 'Próximas 4 semanas' },
    { value: '6w', label: 'Próximas 6 semanas' },
];

type SortDirection = 'asc' | 'desc';
type CajaSortColumn = 'fecha' | 'metodo' | 'vendedor' | 'total' | 'id';
const cajaSortLabels: Record<CajaSortColumn, string> = {
    fecha: 'Fecha',
    metodo: 'Método de pago',
    vendedor: 'Vendedor',
    total: 'Total venta',
    id: 'ID de venta',
};


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
const cajaSortColumn = ref<CajaSortColumn>('id');
const cajaSortDirection = ref<SortDirection>('desc');

const entradasLoading = ref(false);
const entradasError = ref('');
const entradasData = ref<EntradasReportResponse | null>(null);

const inventarioMarcaProviders = ref<Proveedor[]>([]);
const inventarioMarcaProvidersLoading = ref(false);
const inventarioMarcaProvidersError = ref('');
const inventarioMarcaSelectedProviderIdent = ref<number | null>(null);
const inventarioMarcaItems = ref<InventarioRow[]>([]);
const inventarioMarcaPagination = ref<ProductosPagination | null>(null);
const inventarioMarcaLoading = ref(false);
const inventarioMarcaError = ref('');
const inventarioMarcaSearch = ref('');
const inventarioMarcaPage = ref(1);
const inventarioMarcaPerPage = ref(25);
const inventarioMarcaPerPageOptions = [10, 25, 50, 100];
const inventarioMarcaDownloadLoading = ref(false);
const inventarioMarcaPdfLoading = ref(false);
let inventarioMarcaSearchDebounce: ReturnType<typeof setTimeout> | null = null;
const inventarioMarcaSort = ref<'producto' | 'precio' | 'existencia' | 'valor'>('producto');
const inventarioMarcaSortDirection = ref<SortDirection>('asc');

const cajaCondensadoLoading = ref(false);
const cajaCondensadoError = ref('');
const cajaCondensadoData = ref<CajaProveedoresResponse | null>(null);
const cajaCondensadoView = ref<'cards' | 'table'>('table');
const cajaCondensadoProveedorSearch = ref('');
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

const cancelacionesLoading = ref(false);
const cancelacionesError = ref('');
const cancelacionesData = ref<CancelacionesReportResponse | null>(null);
const cancelacionesSearch = ref('');
const cancelacionesExpanded = ref<Record<number, boolean>>({});

const filteredCancelaciones = computed(() => {
    if (!cancelacionesData.value) return [];
    const term = cancelacionesSearch.value.trim().toLowerCase();
    if (!term) return cancelacionesData.value.items;
    return cancelacionesData.value.items.filter((item) => {
        const haystack = [
            item.id?.toString() ?? '',
            item.idventa?.toString() ?? '',
            item.reason ?? '',
            item.metodo ?? '',
            item.vendedor ?? '',
            item.admin?.nombre ?? '',
            item.admin?.email ?? '',
        ]
            .filter(Boolean)
            .map((value) => value.toLowerCase());
        return haystack.some((value) => value.includes(term));
    });
});

function formatDateTime(date?: string | null, time?: string | null) {
    if (!date && !time) return '—';

    const sanitizeDate = (value: string) => value.trim().replace(/\.\d+Z$/, 'Z');
    const sanitizeTime = (value: string) => value.trim().replace(/^0+/, '') || '00:00:00';

    let isoCandidate: string | null = null;

    if (date) {
        const base = sanitizeDate(date);
        if (base.includes('T')) {
            isoCandidate = base;
        } else if (time) {
            isoCandidate = `${base}T${sanitizeTime(time)}`;
        } else {
            isoCandidate = `${base}T00:00:00`;
        }
    } else if (time) {
        const today = new Date().toISOString().split('T')[0];
        isoCandidate = `${today}T${sanitizeTime(time)}`;
    }

    if (isoCandidate) {
        const parsed = new Date(isoCandidate);
        if (!Number.isNaN(parsed.getTime())) {
            return parsed.toLocaleString('es-MX', {
                dateStyle: date ? 'short' : undefined,
                timeStyle: 'medium',
            });
        }
    }

    const parts: string[] = [];
    if (date) parts.push(date.replace(/T/, ' ').replace(/\.\d+Z$/, '').replace(/Z$/, ''));
    if (time) parts.push(time.replace(/^0+/, ''));
    return parts.length ? parts.join(' ') : '—';
}
const restockHorizon = ref<RestockHorizonOption>('2w');
const restockSavingPref = ref(false);

const mensualidadLoading = ref(false);
const mensualidadError = ref('');
const mensualidadData = ref<MensualidadReportResponse | null>(null);
const mensualidadSearch = ref('');
const mensualidadSort = ref<MensualidadSortableColumn>('proveedor');
const mensualidadSortDirection = ref<SortDirection>('asc');
const mensualidadStatusMap: Record<string, string> = {
    pending: 'Pendiente',
    paid: 'Pagado',
};

const reportHeader = computed(() => {
    switch (selected.value) {
        case 'caja':
            return 'Reporte de caja';
        case 'entradas':
            return 'Reporte de entradas';
        case 'inventario-marca':
            return 'Inventario por marca';
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
        cajaSortColumn.value = 'id';
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
    const search = cajaCondensadoProveedorSearch.value.trim();

    cajaCondensadoLoading.value = true;
    try {
        const response = await getCajaProveedoresReport({
            from_date: from,
            to_date: to,
            q: search || undefined,
        });
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
    const search = cajaCondensadoProveedorSearch.value.trim();

    try {
        const blob = await getCajaProveedoresReport({
            from_date: from,
            to_date: to,
            download: true,
            q: search || undefined,
        });
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

async function fetchMensualidadReport(download = false) {
    if (selected.value !== 'mensualidad') return;
    mensualidadError.value = '';

    if (download) {
        try {
            const blob = await getMensualidadReport({
                download: true,
            });
            if (!(blob instanceof Blob)) {
                mensualidadError.value = 'La respuesta del reporte no es válida para descarga.';
                return;
            }
            const filename = 'reporte-mensualidad-completo.csv';
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
        const data = await getMensualidadReport();
        if (data instanceof Blob) {
            mensualidadError.value = 'La respuesta del reporte no es válida.';
            mensualidadData.value = null;
        } else {
            mensualidadData.value = data;
            mensualidadSearch.value = '';
            mensualidadExpandedMonths.value = {};
        }
    } catch (err: any) {
        mensualidadError.value =
            err?.response?.data?.message || err?.message || 'No se pudo cargar el reporte de mensualidad.';
        mensualidadData.value = null;
    } finally {
        mensualidadLoading.value = false;
    }
}

async function fetchCancelacionesReport() {
    if (selected.value !== 'cancelaciones') return;
    if (!rangeStart.value) {
        window.alert('Selecciona una fecha inicial');
        return;
    }
    cancelacionesLoading.value = true;
    cancelacionesError.value = '';
    try {
        const data = await getCancelacionesReport({
            from_date: rangeStart.value,
            to_date: rangeEnd.value || undefined,
        });
        cancelacionesData.value = data;
        cancelacionesExpanded.value = {};
    } catch (err: any) {
        cancelacionesError.value =
            err?.response?.data?.message || err?.message || 'No se pudo cargar el reporte de cancelaciones.';
        cancelacionesData.value = null;
    } finally {
        cancelacionesLoading.value = false;
    }
}

function toggleCancelacionExpanded(id: number) {
    cancelacionesExpanded.value = {
        ...cancelacionesExpanded.value,
        [id]: !cancelacionesExpanded.value[id],
    };
}

function downloadCancelacionesCsv() {
    if (!cancelacionesData.value?.items.length) return;
    const lines = [
        [
            'Cancelada',
            'Venta original',
            'Ticket',
            'Venta ID',
            'Total original',
            'Método',
            'Vendedor',
            'Administrador',
            'Correo admin',
            'Motivo',
            'Productos',
        ],
    ];
    cancelacionesData.value.items.forEach((item) => {
            const productos = item.line_items
                .map((line) => `${line.producto_nombre ?? 'Producto'} (${line.cantidad ?? 0})`)
                .join('; ');
            lines.push([
            formatDateTime(item.cancelled_at),
            formatDateTime(item.sale_date, item.sale_time),
            String(item.idventa ?? item.venta_id ?? ''),
            String(item.venta_id ?? ''),
            item.total !== null ? formatCurrency(item.total) : '',
            item.metodo ?? '',
            item.vendedor ?? '',
            item.admin?.nombre ?? '',
            item.admin?.email ?? '',
            item.reason ?? '',
            productos,
        ]);
    });
    const csv = lines
        .map((row) => row.map((value) => `"${String(value ?? '').replace(/"/g, '""')}"`).join(','))
        .join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `cancelaciones-${cancelacionesData.value.range.from}-${cancelacionesData.value.range.to}.csv`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

function downloadCancelacionesPdf() {
    if (!cancelacionesData.value?.items.length) return;
    const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'legal' });
    const marginX = 36;
    const marginY = 36;
    const lineHeight = 12;
    const headerHeight = 60;
    const columns = [
        { title: 'Cancelada', width: 140 },
        { title: 'Venta original', width: 140 },
        { title: 'Ticket', width: 70 },
        { title: 'Venta ID', width: 70 },
        { title: 'Total', width: 80, align: 'right' },
        { title: 'Método', width: 80 },
        { title: 'Vendedor', width: 120 },
        { title: 'Administrador', width: 130 },
        { title: 'Motivo', width: 180 },
    ];
    const columnPositions: number[] = [];
    let offset = marginX;
    columns.forEach((col) => {
        columnPositions.push(offset);
        offset += col.width;
    });

    let currentY = marginY;
    let page = 1;
    const drawHeader = () => {
        doc.setFontSize(16);
        doc.text('Reporte de cancelaciones', marginX, currentY);
        doc.setFontSize(10);
        doc.text(
            `Periodo: ${cancelacionesData.value?.range.from ?? ''} - ${cancelacionesData.value?.range.to ?? ''}`,
            marginX,
            currentY + 16
        );
        doc.text(`Generado: ${new Date().toLocaleString('es-MX')}`, marginX, currentY + 30);
        doc.text(`Página ${page}`, doc.internal.pageSize.getWidth() - marginX, currentY + 30, { align: 'right' });
        currentY += headerHeight;
    };
    const drawTableHeader = () => {
        doc.setFontSize(9);
        doc.setFillColor(243, 244, 246);
        const totalWidth = columns.reduce((sum, col) => sum + col.width, 0);
        doc.rect(marginX, currentY, totalWidth, 20, 'F');
        columns.forEach((col, idx) => {
            const textX =
                col && columnPositions[idx] !== undefined
                    ? (col.align ?? 'left') === 'right'
                        ? columnPositions[idx]! + col.width - 6
                        : columnPositions[idx]! + 6
                    : 6;
            doc.text(col.title, textX, currentY + 13, { align: (col.align ?? 'left') as 'right' | 'left' | 'center' | 'justify' });
        });
        currentY += 24;
    };
    const ensureSpace = (height: number) => {
        if (currentY + height > doc.internal.pageSize.getHeight() - marginY) {
            doc.addPage();
            page += 1;
            currentY = marginY;
            drawHeader();
            drawTableHeader();
        }
    };

    drawHeader();
    drawTableHeader();
    doc.setFontSize(9);

    cancelacionesData.value.items.forEach((item) => {
        const rows = [
            formatDateTime(item.cancelled_at),
            formatDateTime(item.sale_date, item.sale_time),
            String(item.idventa ?? item.venta_id ?? ''),
            String(item.venta_id ?? ''),
            item.total !== null ? formatCurrency(item.total) : '—',
            item.metodo ?? '—',
            item.vendedor ?? '—',
            item.admin?.nombre ?? '—',
            item.reason ?? '—',
        ];
        const lineChunks = rows.map((text, idx) => {
            const width = columns[idx]?.width !== undefined ? columns[idx].width - 10 : 100;
            return doc.splitTextToSize(text, width);
        });
        const maxLines = Math.max(...lineChunks.map((chunk) => chunk.length || 1));
        const rowHeight = maxLines * lineHeight + 6;
        ensureSpace(rowHeight);
        lineChunks.forEach((chunk, idx) => {
            const align = columns[idx]?.align ?? 'left';
            const startX = (columnPositions[idx] ?? 0) + 6;
            let textY = currentY + 12;
            chunk.forEach((line: string) => {
                if (align === 'right') {
                    if (columns[idx] !== undefined && columnPositions[idx] !== undefined) {
                        doc.text(line, columnPositions[idx] + columns[idx].width - 6, textY, { align: 'right' });
                    } else {
                        doc.text(line, startX, textY, { align: 'right' });
                    }
                } else {
                    doc.text(line, startX, textY);
                }
                textY += lineHeight;
            });
        });
        currentY += rowHeight;
    });

    doc.save(`cancelaciones-${cancelacionesData.value.range.from}-${cancelacionesData.value.range.to}.pdf`);
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

const cajaTableTotals = computed(() => {
    const totals = filteredCajaVentas.value.reduce(
        (acc, venta) => {
            acc.total += Number(venta.totalventa ?? 0);
            acc.recibido += Number(venta.total_recibido ?? 0);
            acc.cambio += Number(venta.cambio ?? 0);
            return acc;
        },
        { total: 0, recibido: 0, cambio: 0 }
    );
    return { ...totals, count: filteredCajaVentas.value.length };
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
    cajaSortColumn.value = 'id';
    cajaSortDirection.value = 'desc';
}

function toggleCajaSortDirection() {
    cajaSortDirection.value = cajaSortDirection.value === 'asc' ? 'desc' : 'asc';
}

function cajaVentaLineTotals(lineas: CajaReportLine[]) {
    return lineas.reduce(
        (acc, linea) => {
            const qty = Number(linea.quantity ?? 0);
            const unit = Number(linea.unit_price ?? 0);
            const publicTotal = Number(linea.public_total ?? unit * qty);
            acc.cantidad += qty;
            acc.totalProducto += publicTotal;
            acc.totalProveedor += Number(linea.provider_price ?? 0) * qty;
            acc.promo += Number(linea.promotion_discount_amount ?? 0);
            acc.manual += Number(linea.manual_discount_amount ?? 0);
            acc.tarjeta += Number(linea.credit_card_discount ?? 0);
            acc.descProveedor += Number(linea.provider_discount_amount ?? 0);
            acc.pagoProveedor += Number(linea.provider_payment ?? 0);
            acc.gananciaAdmin += Number(linea.admin_earnings ?? 0);
            return acc;
        },
        {
            cantidad: 0,
            totalProducto: 0,
            totalProveedor: 0,
            promo: 0,
            manual: 0,
            tarjeta: 0,
            descProveedor: 0,
            pagoProveedor: 0,
            gananciaAdmin: 0,
        }
    );
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
    const filter = cajaCondensadoTipoFilter.value;
    const search = cajaCondensadoProveedorSearch.value.trim().toLowerCase();
    let providers = cajaCondensadoData.value.proveedores ?? [];

    if (filter !== 'todos') {
        providers = providers.filter((prov) => normalizeProveedorTipo(prov.proveedor_tipo) === filter);
    }

    if (search) {
        providers = providers.filter((prov) => {
            const haystack = [
                prov.proveedor_nombre ?? '',
                prov.proveedor_ident ?? '',
                prov.proveedor_id ? String(prov.proveedor_id) : '',
            ]
                .filter(Boolean)
                .map((value) => value.toLowerCase());
            return haystack.some((value) => value.includes(search));
        });
    }

    return providers;
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
    const search = cajaCondensadoProveedorSearch.value.trim();
    const useGlobalSummary = cajaCondensadoTipoFilter.value === 'todos' && !search;
    if (useGlobalSummary) {
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

const mensualidadGroupedItems = computed(() => {
    const groups = new Map<string, MensualidadReportItem[]>();
    sortedMensualidadItems.value.forEach((item) => {
        const key = item.mes_cobro && item.mes_cobro.length >= 7 ? item.mes_cobro.slice(0, 7) : item.mes_cobro || 'Sin fecha';
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key)!.push(item);
    });
    return Array.from(groups.entries()).map(([month, items]) => ({
        month,
        items,
        totals: mensualidadGroupTotals(items),
    }));
});

const mensualidadExpandedMonths = ref<Record<string, boolean>>({});

function isMensualidadMonthOpen(month: string) {
    const state = mensualidadExpandedMonths.value[month];
    return state === true;
}

function toggleMensualidadMonth(month: string) {
    mensualidadExpandedMonths.value[month] = !isMensualidadMonthOpen(month);
}

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

function downloadProveedorModalPdf() {
    if (!proveedorModalData.value) return;
    const items = proveedorModalSortedItems.value;
    if (items.length === 0) return;

    const provider = proveedorModalData.value;
    const totals = proveedorModalTotals.value;
    const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'legal' });
    const marginX = 36;
    const marginY = 36;
    const pageWidth = doc.internal.pageSize.getWidth();
    const pageHeight = doc.internal.pageSize.getHeight();
    let currentY = marginY;
    let page = 1;

    const summaryItems = [
        { label: 'Unidades', value: String(totals.cantidad ?? 0) },
        { label: 'Ventas', value: formatCurrency(totals.total ?? 0) },
        { label: 'Desc. proveedor', value: formatCurrency(totals.tipoDescuento ?? 0) },
        { label: 'Desc. manual', value: formatCurrency(totals.manual ?? 0) },
        { label: 'Cargo tarjeta', value: formatCurrency(totals.cardFee ?? 0) },
        { label: 'Ganancia real', value: formatCurrency(totals.real ?? 0) },
    ];

    const availableWidth = pageWidth - marginX * 2;
    const baseColumns: Array<{ key: string; title: string; width: number; align?: 'left' | 'right' }> = [
        { key: 'fecha', title: 'Fecha', width: 65 },
        { key: 'producto', title: 'Producto', width: 130 },
        { key: 'ident', title: 'Ident', width: 60 },
        { key: 'venta', title: '#', width: 50 },
        { key: 'cantidad', title: 'Cant.', width: 40, align: 'right' },
        { key: 'precio', title: 'Precio unit.', width: 65, align: 'right' },
        { key: 'provider_price', title: 'Precio prov.', width: 65, align: 'right' },
        { key: 'total', title: 'Total', width: 65, align: 'right' },
        { key: 'provider_discount', title: 'Desc. prov.', width: 70, align: 'right' },
        { key: 'manual', title: 'Desc. manual', width: 70, align: 'right' },
        { key: 'card_fee', title: 'Cargo tarjeta', width: 70, align: 'right' },
        { key: 'real', title: 'Ganancia', width: 70, align: 'right' },
        { key: 'metodo', title: 'Método', width: 60 },
        { key: 'vendedor', title: 'Vendedor', width: 80 },
        { key: 'promocion', title: 'Promoción', width: 60 },
    ];
    const totalBaseWidth = baseColumns.reduce((sum, col) => sum + col.width, 0);
    const scale = totalBaseWidth > availableWidth ? availableWidth / totalBaseWidth : 1;
    const columns = baseColumns.map((col) => ({
        ...col,
        width: Math.max(col.width * scale, 40),
    }));
    const columnPositions: number[] = [];
    let offset = marginX;
    columns.forEach((col) => {
        columnPositions.push(offset);
        offset += col.width;
    });

    const drawHeader = () => {
        doc.setFontSize(16);
        doc.text('Movimientos por proveedor', marginX, currentY);
        doc.setFontSize(11);
        doc.text(`Proveedor: ${provider.proveedor_nombre ?? 'Sin proveedor'}`, marginX, currentY + 18);
        doc.text(`Identificador: ${provider.proveedor_ident ?? 'N/D'}`, marginX, currentY + 32);
        doc.text(`Generado: ${new Date().toLocaleString('es-MX')}`, marginX, currentY + 46);
        doc.text(`Página ${page}`, pageWidth - marginX, currentY + 46, { align: 'right' });
        currentY += 62;

        doc.setFontSize(10);
        const summaryCols = 3;
        const summaryColWidth = (pageWidth - marginX * 2) / summaryCols;
        summaryItems.forEach((item, idx) => {
            const col = idx % summaryCols;
            const row = Math.floor(idx / summaryCols);
            const x = marginX + col * summaryColWidth;
            const y = currentY + row * 28;
            doc.setFont('', 'bold');
            doc.text(item.value, x, y);
            doc.setFont('', 'normal');
            doc.text(item.label, x, y + 12);
        });
        currentY += Math.ceil(summaryItems.length / summaryCols) * 28 + 18;
    };

    const drawTableHeader = () => {
        doc.setFontSize(9);
        doc.setFillColor(243, 244, 246);
        const totalWidth = columns.reduce((sum, col) => sum + col.width, 0);
        doc.rect(marginX, currentY, totalWidth, 20, 'F');
        columns.forEach((col, idx) => {
            const align = col.align ?? 'left';
            const textX = align === 'right'
                ? columnPositions[idx]! + col.width - 4
                : columnPositions[idx]! + 4;
            doc.text(col.title, textX, currentY + 13, { align: align as 'left' | 'right' });
        });
        currentY += 24;
    };

    const ensureSpace = (height: number) => {
        if (currentY + height > pageHeight - marginY) {
            doc.addPage();
            page += 1;
            currentY = marginY;
            drawHeader();
            drawTableHeader();
        }
    };

    const valueForColumn = (item: CajaProveedorItem, key: string) => {
        switch (key) {
            case 'fecha':
                return item.fecha ?? '';
            case 'producto':
                return item.producto_nombre ?? '';
            case 'ident':
                return item.producto_ident ?? '';
            case 'venta':
                return String(item.idventa ?? item.venta_id ?? '');
            case 'cantidad':
                return item.cantidad !== undefined ? String(item.cantidad) : '';
            case 'precio':
                return formatCurrency(item.precio_unitario ?? 0);
            case 'provider_price':
                return formatCurrency(item.provider_price ?? 0);
            case 'total':
                return formatCurrency(item.total ?? 0);
            case 'provider_discount':
                return formatCurrency(item.provider_discount ?? 0);
            case 'manual':
                return formatCurrency(item.manual_discount ?? 0);
            case 'card_fee':
                return formatCurrency(item.card_fee ?? 0);
            case 'real':
                return formatCurrency(item.real_earning ?? item.expected_earning ?? 0);
            case 'metodo':
                return item.metodo ?? '';
            case 'vendedor':
                return item.vendedor ?? '';
            case 'promocion':
                return item.promotion ?? 'normal';
            default:
                return '';
        }
    };

    drawHeader();
    drawTableHeader();
    doc.setFontSize(8.5);

    const rowHeight = 16;
    items.forEach((item) => {
        ensureSpace(rowHeight);
        columns.forEach((col, idx) => {
            const align = col.align ?? 'left';
            const textX = align === 'right'
                ? columnPositions[idx]! + col.width - 4
                : columnPositions[idx]! + 4;
            const text = valueForColumn(item, col.key);
            doc.text(String(text ?? ''), textX, currentY + 11, { align: align as 'left' | 'right' });
        });
        currentY += rowHeight;
    });

    const filename = `caja-condensado-${provider.proveedor_ident ?? 'proveedor'}-movimientos.pdf`;
    doc.save(filename);
}

watch(
    () => selected.value,
    (val) => {
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
        if (val === 'inventario-marca') {
            inventarioMarcaError.value = '';
            if (!inventarioMarcaProviders.value.length) {
                fetchInventarioMarcaProviders();
            }
            inventarioMarcaSort.value = 'producto';
            inventarioMarcaSortDirection.value = 'asc';
            if (inventarioMarcaSelectedProviderIdent.value && !inventarioMarcaItems.value.length) {
                fetchInventarioMarca();
            }
        }
        if (val === 'mensualidad') {
            mensualidadError.value = '';
            if (!mensualidadData.value) {
                fetchMensualidadReport();
            }
        }
        if (val === 'cancelaciones') {
            cancelacionesError.value = '';
            if (!cancelacionesData.value && rangeStart.value) {
                fetchCancelacionesReport();
            }
        }
    },
    { immediate: false }
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
        if (selected.value === 'cancelaciones') {
            cancelacionesData.value = null;
            cancelacionesError.value = '';
            cancelacionesExpanded.value = {};
        }
    }
);

watch(
    () => inventarioMarcaSelectedProviderIdent.value,
    () => {
        inventarioMarcaItems.value = [];
        inventarioMarcaPagination.value = null;
        inventarioMarcaError.value = '';
        inventarioMarcaSearch.value = '';
        inventarioMarcaSort.value = 'producto';
        inventarioMarcaSortDirection.value = 'asc';
        resetInventarioMarcaPagination();
        if (selected.value === 'inventario-marca' && inventarioMarcaSelectedProviderIdent.value) {
            fetchInventarioMarca();
        }
    }
);

watch(
    () => inventarioMarcaSearch.value,
    () => {
        if (inventarioMarcaSearchDebounce) {
            clearTimeout(inventarioMarcaSearchDebounce);
        }
        inventarioMarcaSearchDebounce = setTimeout(() => {
            resetInventarioMarcaPagination();
            inventarioMarcaSort.value = 'producto';
            inventarioMarcaSortDirection.value = 'asc';
            if (selected.value === 'inventario-marca' && inventarioMarcaSelectedProviderIdent.value) {
                fetchInventarioMarca();
            }
        }, 300);
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

watch(
    () => cancelacionesData.value,
    () => {
        cancelacionesSearch.value = '';
        cancelacionesExpanded.value = {};
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
                            <optgroup v-for="group in groupedOptions" :key="group.group" :label="group.group">
                                <option v-for="option in group.options" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </optgroup>
                        </select>
                    </label>
                    <label class="flex flex-col text-sm text-gray-600" v-if="['caja', 'entradas','caja-condensado','caja-egresos','flujo-caja','cancelaciones'].includes(selected)">
                        <span class="font-medium text-gray-700">Fecha inicial</span>
                        <input v-model="rangeStart" type="date"
                            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900" />
                    </label>
                    <label class="flex flex-col text-sm text-gray-600" v-if="['caja', 'entradas','caja-condensado','caja-egresos','flujo-caja','cancelaciones'].includes(selected)">
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
                                                            <th class="px-2 py-1 text-right">Total producto</th>
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
                                                            <td class="px-2 py-1 text-right font-semibold text-gray-900">
                                                                {{ formatCurrency(linea.public_total ?? linea.unit_price * linea.quantity) }}
                                                            </td>
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
                                                                    <div class="flex flex-col items-end leading-tight">
                                                                        <span class="font-semibold text-sky-700">{{ formatCurrency(cajaLineProviderPayment(linea, venta.metodo)) }}</span>
                                                                        
                                                                    </div>
                                                                    <span class="relative inline-flex cursor-help text-[10px] text-gray-500 group">
                                                                        Fórmula
                                                                    <span
                                                                        class="pointer-events-none absolute bottom-full right-0 z-10 mb-1 hidden whitespace-nowrap rounded-md bg-gray-900 px-2 py-1 text-[10px] text-white group-hover:block">
                                                                            {{ providerPaymentTooltip(linea, venta.metodo) }}
                                                                    </span>
                                                                </span>
                                                                </div>
                                                            </td>
                                                            <td class="px-2 py-1 text-right font-semibold text-emerald-700">{{ formatCurrency(linea.admin_earnings) }}</td>
                                                        </tr>
                                                    </tbody>
                                                    <tfoot v-if="venta.lineas?.length" class="bg-gray-50 text-[11px] font-semibold text-gray-700">
                                                        <tr>
                                                            <td class="px-2 py-1 text-right" colspan="2">Totales</td>
                                                            <td class="px-2 py-1 text-right">{{ cajaVentaLineTotals(venta.lineas).cantidad }}</td>
                                                            <td class="px-2 py-1"></td>
                                                            <td class="px-2 py-1 text-right">{{ formatCurrency(cajaVentaLineTotals(venta.lineas).totalProducto) }}</td>
                                                            <td class="px-2 py-1 text-right">{{ formatCurrency(cajaVentaLineTotals(venta.lineas).totalProveedor) }}</td>
                                                            <td class="px-2 py-1 text-right">{{ formatCurrency(cajaVentaLineTotals(venta.lineas).promo) }}</td>
                                                            <td class="px-2 py-1 text-right">{{ formatCurrency(cajaVentaLineTotals(venta.lineas).manual) }}</td>
                                                            <td class="px-2 py-1 text-right">{{ formatCurrency(cajaVentaLineTotals(venta.lineas).tarjeta) }}</td>
                                                            <td class="px-2 py-1 text-right">{{ formatCurrency(cajaVentaLineTotals(venta.lineas).descProveedor) }}</td>
                                                            <td class="px-2 py-1 text-right text-sky-700">{{ formatCurrency(cajaVentaLineTotals(venta.lineas).pagoProveedor) }}</td>
                                                            <td class="px-2 py-1 text-right text-emerald-700">{{ formatCurrency(cajaVentaLineTotals(venta.lineas).gananciaAdmin) }}</td>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                            <tfoot v-if="filteredCajaVentas.length" class="bg-gray-50 text-[11px] font-semibold text-gray-700">
                                <tr>
                                    <td class="px-3 py-2 text-right" colspan="4">Totales (ventas filtradas)</td>
                                    <td class="px-3 py-2 text-right">{{ formatCurrency(cajaTableTotals.total) }}</td>
                                    <td class="px-3 py-2 text-right">{{ formatCurrency(cajaTableTotals.recibido) }}</td>
                                    <td class="px-3 py-2 text-right">{{ formatCurrency(cajaTableTotals.cambio) }}</td>
                                    <td class="px-3 py-2 text-right text-gray-500">{{ cajaTableTotals.count }} ventas</td>
                                </tr>
                            </tfoot>
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
                    <template v-else-if="selected === 'inventario-marca'">
                        <div class="space-y-4">
                            <div class="flex flex-wrap items-end gap-3">
                                <label class="flex flex-col text-xs text-gray-500">
                                    <span class="font-medium text-gray-700">Marca</span>
                                    <select
                                        v-model.number="inventarioMarcaSelectedProviderIdent"
                                        class="mt-1 min-w-[220px] rounded border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                                        :disabled="inventarioMarcaProvidersLoading"
                                    >
                                        <option :value="0">Selecciona una marca</option>
                                        <option v-for="provider in inventarioMarcaProviders" :key="provider.ident" :value="provider.ident">
                                            {{ provider.nombre }}
                                        </option>
                                    </select>
                                </label>
                                <label class="flex flex-col text-xs text-gray-500">
                                    <span class="font-medium text-gray-700">Buscar producto</span>
                                    <input
                                        v-model="inventarioMarcaSearch"
                                        type="search"
                                        placeholder="Nombre o ident…"
                                        class="mt-1 min-w-[220px] rounded border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                                        :disabled="!inventarioMarcaSelectedProviderIdent"
                                    />
                                </label>
                                <label class="flex items-center gap-2 text-xs text-gray-500">
                                    <span class="font-medium text-gray-700">Filas por pagina</span>
                                    <select
                                        :value="inventarioMarcaPerPage"
                                        class="rounded border border-gray-300 px-2 py-1 text-xs focus:border-gray-900 focus:ring-gray-900"
                                        @change="updateInventarioMarcaPerPage(Number(($event.target as HTMLSelectElement).value))"
                                    >
                                        <option v-for="option in inventarioMarcaPerPageOptions" :key="option" :value="option">
                                            {{ option }}
                                        </option>
                                    </select>
                                </label>
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:bg-gray-300"
                                    :disabled="inventarioMarcaLoading || !inventarioMarcaSelectedProviderIdent"
                                    @click="fetchInventarioMarca"
                                >
                                    <span v-if="inventarioMarcaLoading">Consultando…</span>
                                    <span v-else>Consultar inventario</span>
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:text-gray-400"
                                    :disabled="inventarioMarcaDownloadLoading || !inventarioMarcaSelectedProviderIdent"
                                    @click="downloadInventarioMarcaCsv"
                                >
                                    {{ inventarioMarcaDownloadLoading ? 'Generando CSV…' : 'Descargar CSV' }}
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:text-gray-400"
                                    :disabled="inventarioMarcaPdfLoading || !inventarioMarcaSelectedProviderIdent"
                                    @click="downloadInventarioMarcaPdf"
                                >
                                    {{ inventarioMarcaPdfLoading ? 'Generando PDF…' : 'Descargar PDF' }}
                                </button>
                            </div>

                            <p v-if="inventarioMarcaProvidersError"
                                class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                                {{ inventarioMarcaProvidersError }}
                            </p>
                            <p v-else-if="inventarioMarcaProvidersLoading" class="text-xs text-gray-500">
                                Cargando marcas…
                            </p>
                            <p v-if="inventarioMarcaError"
                                class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                                {{ inventarioMarcaError }}
                            </p>

                            <div v-if="!inventarioMarcaSelectedProviderIdent" class="text-xs text-gray-500">
                                Selecciona una marca para ver su inventario.
                            </div>
                            <div v-else class="space-y-4">
                                <div v-if="inventarioMarcaLoading" class="text-xs text-gray-500">Cargando inventario…</div>
                                <div v-else>
                                    <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-gray-500">
                                        <div v-if="inventarioMarcaTotalItems">
                                            Mostrando {{ inventarioMarcaPageStart }}-{{ inventarioMarcaPageEnd }}
                                            de {{ inventarioMarcaTotalItems }}
                                        </div>
                                        <div v-else>
                                            Sin registros para esta marca.
                                        </div>
                                    </div>
                                    <div :class="tableClasses.wrapper">
                                        <table :class="tableClasses.table">
                                            <thead :class="tableClasses.head">
                                                <tr>
                                                    <th class="px-3 py-2">Producto</th>
                                                    <th class="px-3 py-2">Descripcion</th>
                                                    <th class="px-3 py-2 text-right">
                                                        <button
                                                            type="button"
                                                            class="flex w-full items-center justify-end gap-1 font-semibold"
                                                            @click="toggleInventarioMarcaSort('precio')"
                                                        >
                                                            Precio
                                                            <span class="text-[10px]">{{ inventarioMarcaSortIcon('precio') }}</span>
                                                        </button>
                                                    </th>
                                                    <th class="px-3 py-2 text-right">
                                                        <button
                                                            type="button"
                                                            class="flex w-full items-center justify-end gap-1 font-semibold"
                                                            @click="toggleInventarioMarcaSort('existencia')"
                                                        >
                                                            Existencia
                                                            <span class="text-[10px]">{{ inventarioMarcaSortIcon('existencia') }}</span>
                                                        </button>
                                                    </th>
                                                    <th class="px-3 py-2 text-right">
                                                        <button
                                                            type="button"
                                                            class="flex w-full items-center justify-end gap-1 font-semibold"
                                                            @click="toggleInventarioMarcaSort('valor')"
                                                        >
                                                            Valor inventario
                                                            <span class="text-[10px]">{{ inventarioMarcaSortIcon('valor') }}</span>
                                                        </button>
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody :class="tableClasses.body">
                                                <tr v-if="inventarioMarcaItems.length === 0">
                                                    <td class="px-3 py-6 text-center text-gray-500" colspan="5">
                                                        No hay inventario registrado para esta marca.
                                                    </td>
                                                </tr>
                                                <tr v-for="item in inventarioMarcaItems" :key="item.inventario_id" :class="tableClasses.row">
                                                    <td class="px-3 py-2 font-medium text-gray-900">
                                                        {{ item.producto_nombre || (item as any)?.producto?.nombre || 'Producto sin nombre' }}
                                                    </td>
                                                    <td class="px-3 py-2 text-gray-600">
                                                        {{ item.producto_descripcion || (item as any)?.producto?.descripcion || '—' }}
                                                    </td>
                                                    <td class="px-3 py-2 text-right">{{ formatCurrency(item.precio ?? 0) }}</td>
                                                    <td class="px-3 py-2 text-right">{{ Number(item.existencia ?? 0) }}</td>
                                                    <td class="px-3 py-2 text-right">{{ formatCurrency(item.costo_inventario ?? 0) }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div v-if="inventarioMarcaTotalPages > 1" class="flex items-center justify-end gap-2">
                                        <button
                                            type="button"
                                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50 disabled:opacity-60"
                                            :disabled="inventarioMarcaPage <= 1"
                                            @click="goInventarioMarcaPrevPage"
                                        >
                                            Anterior
                                        </button>
                                        <span class="text-sm text-gray-500">
                                            Pagina {{ inventarioMarcaPage }} de {{ inventarioMarcaTotalPages }}
                                        </span>
                                        <button
                                            type="button"
                                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50 disabled:opacity-60"
                                            :disabled="inventarioMarcaPage >= inventarioMarcaTotalPages"
                                            @click="goInventarioMarcaNextPage"
                                        >
                                            Siguiente
                                        </button>
                                    </div>
                                </div>
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
                            <template v-else-if="mensualidadData">
                                <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-gray-500">
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
                                <p v-if="mensualidadSummary" class="text-[11px] text-gray-500">
                                    *Pagos completos registrados: {{ mensualidadSummary.pagosCompletos }}.
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

                                <div v-if="filteredMensualidadItems.length === 0"
                                    class="rounded-2xl border border-dashed border-gray-200 bg-white/60 px-4 py-6 text-center text-sm text-gray-500">
                                    No hay registros de mensualidad para los criterios seleccionados.
                                </div>
                                <div v-else class="space-y-4">
                                    <article v-for="group in mensualidadGroupedItems" :key="group.month"
                                        class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                                        <button type="button"
                                            class="flex w-full flex-wrap items-center justify-between gap-4 px-4 py-3 text-left transition hover:bg-gray-50"
                                            @click="toggleMensualidadMonth(group.month)">
                                            <div>
                                                <p class="text-sm font-semibold text-gray-900">{{ formatMonthLabel(group.month) }}</p>
                                                <p class="text-xs text-gray-500">{{ group.items.length }} registros</p>
                                            </div>
                                            <dl class="flex flex-wrap items-center gap-4 text-xs text-gray-500">
                                                <div>
                                                    <dt class="font-semibold text-gray-900">{{ formatCurrency(group.totals.importe) }}</dt>
                                                    <dd>Importe</dd>
                                                </div>
                                                <div>
                                                    <dt class="font-semibold text-gray-900">{{ formatCurrency(group.totals.pagado) }}</dt>
                                                    <dd>Pagado</dd>
                                                </div>
                                                <div>
                                                    <dt class="font-semibold text-gray-900">{{ formatCurrency(group.totals.restante) }}</dt>
                                                    <dd>Restante</dd>
                                                </div>
                                            </dl>
                                            <span class="text-xs font-semibold text-gray-500">
                                                {{ isMensualidadMonthOpen(group.month) ? 'Contraer' : 'Expandir' }}
                                            </span>
                                        </button>
                                        <div v-if="isMensualidadMonthOpen(group.month)" class="border-t border-gray-100">
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
                                                        <tr v-for="item in group.items" :key="item.id" :class="tableClasses.row">
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
                                                    <tfoot class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-600">
                                                        <tr>
                                                            <td class="px-3 py-2 font-semibold" colspan="2">Totales del mes</td>
                                                            <td class="px-3 py-2 text-right">{{ formatCurrency(group.totals.importe) }}</td>
                                                            <td class="px-3 py-2 text-right">{{ formatCurrency(group.totals.pagado) }}</td>
                                                            <td class="px-3 py-2 text-right">{{ formatCurrency(group.totals.restante) }}</td>
                                                            <td class="px-3 py-2" colspan="5"></td>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        </div>
                                    </article>
                                </div>
                            </template>
                            <p v-else class="text-xs text-gray-500">Consulta el reporte para ver los cobros del mes seleccionado.</p>
                        </div>
                    </template>

                    <template v-else-if="selected === 'cancelaciones'">
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button"
                                class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:bg-gray-300"
                                :disabled="cancelacionesLoading"
                                @click="fetchCancelacionesReport">
                                <span v-if="cancelacionesLoading">Consultando…</span>
                                <span v-else>Consultar reporte</span>
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:text-gray-400"
                                :disabled="cancelacionesLoading || !cancelacionesData?.items.length"
                                @click="downloadCancelacionesCsv"
                            >
                                Descargar CSV
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:text-gray-400"
                                :disabled="cancelacionesLoading || !cancelacionesData?.items.length"
                                @click="downloadCancelacionesPdf"
                            >
                                Descargar PDF
                            </button>
                        </div>
                        <p v-if="cancelacionesError"
                            class="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                            {{ cancelacionesError }}
                        </p>
                        <div v-else class="mt-4 space-y-4">
                            <div v-if="cancelacionesLoading" class="text-xs text-gray-500">Cargando datos…</div>
                            <template v-else-if="cancelacionesData">
                                <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-gray-500">
                                    <div>
                                        Periodo:
                                        <span class="font-semibold text-gray-900">{{ cancelacionesData.range.from }}</span>
                                        –
                                        <span class="font-semibold text-gray-900">{{ cancelacionesData.range.to }}</span>
                                    </div>
                                    <label class="flex items-center gap-2 text-xs text-gray-600">
                                        <span class="font-medium text-gray-700">Buscar</span>
                                        <input
                                            v-model="cancelacionesSearch"
                                            type="search"
                                            placeholder="Ticket, admin o motivo…"
                                            class="w-60 rounded border border-gray-300 px-3 py-1 text-xs focus:border-gray-900 focus:ring-gray-900"
                                        />
                                    </label>
                                </div>
                                <div v-if="!filteredCancelaciones.length" class="text-xs text-gray-500">
                                    No hay cancelaciones para los filtros seleccionados.
                                </div>
                                <div v-else :class="tableClasses.wrapper">
                                    <table :class="tableClasses.table">
                                        <thead :class="tableClasses.head">
                                            <tr>
                                                <th class="px-3 py-2">Cancelada</th>
                                                <th class="px-3 py-2">Ticket</th>
                                                <th class="px-3 py-2">Venta ID</th>
                                                <th class="px-3 py-2 text-right">Total original</th>
                                                <th class="px-3 py-2">Método</th>
                                                <th class="px-3 py-2">Vendedor</th>
                                                <th class="px-3 py-2">Administrador</th>
                                                <th class="px-3 py-2">Motivo</th>
                                                <th class="px-3 py-2 text-left">Productos</th>
                                            </tr>
                                        </thead>
                                        <tbody :class="tableClasses.body">
                                            <template v-for="item in filteredCancelaciones" :key="item.id">
                                                <tr :class="tableClasses.row">
                                                    <td class="px-3 py-2">
                                                        <div class="font-medium text-gray-900">{{ formatDateTime(item.cancelled_at) }}</div>
                                                        <p class="text-[11px] text-gray-500">
                                                            Venta original: {{ formatDateTime(item.sale_date, item.sale_time) }}
                                                        </p>
                                                    </td>
                                                    <td class="px-3 py-2">{{ item.idventa ?? item.venta_id }}</td>
                                                    <td class="px-3 py-2">{{ item.venta_id }}</td>
                                                    <td class="px-3 py-2 text-right">
                                                        <span v-if="item.total !== null">{{ formatCurrency(item.total) }}</span>
                                                        <span v-else>—</span>
                                                    </td>
                                                    <td class="px-3 py-2">{{ item.metodo ?? '—' }}</td>
                                                    <td class="px-3 py-2">{{ item.vendedor ?? '—' }}</td>
                                                    <td class="px-3 py-2">
                                                        <div class="font-medium text-gray-900">{{ item.admin?.nombre ?? '—' }}</div>
                                                        <p class="text-[11px] text-gray-500">{{ item.admin?.email ?? '' }}</p>
                                                    </td>
                                                    <td class="px-3 py-2">{{ item.reason ?? '—' }}</td>
                                                    <td class="px-3 py-2">
                                                        {{ item.line_items.length }}
                                                        <button
                                                            type="button"
                                                            class="ml-2 text-xs text-gray-500 underline"
                                                            @click="toggleCancelacionExpanded(item.id)"
                                                        >
                                                            {{ cancelacionesExpanded[item.id] ? 'Ocultar' : 'Ver' }} detalle
                                                        </button>
                                                    </td>
                                                </tr>
                                                <tr v-if="cancelacionesExpanded[item.id]" class="bg-gray-50">
                                                    <td colspan="8" class="px-4 py-3">
                                                        <div class="space-y-2 text-xs text-gray-600">
                                                            <p class="font-semibold text-gray-800">Productos cancelados</p>
                                                            <div class="space-y-1">
                                                                <div
                                                                    v-for="line in item.line_items"
                                                                    :key="`${item.id}-${line.producto_ident}-${line.producto_nombre}`"
                                                                    class="flex flex-wrap items-center justify-between rounded border border-gray-200 bg-white px-3 py-2 text-[12px]"
                                                                >
                                                                    <div class="flex-1">
                                                                        <p class="font-medium text-gray-800">{{ line.producto_nombre ?? 'Producto sin nombre' }}</p>
                                                                        <p class="text-[11px] text-gray-500">Ident: {{ line.producto_ident ?? '—' }}</p>
                                                                    </div>
                                                                    <div class="text-right">
                                                                        <p>
                                                                            Cant.
                                                                            {{ line.cantidad ?? '—' }}
                                                                        </p>
                                                                        <p>
                                                                            Total
                                                                            {{ line.line_total !== null ? formatCurrency(line.line_total) : '—' }}
                                                                        </p>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </template>
                            <div v-else class="text-xs text-gray-500">No hay datos disponibles.</div>
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
                                <div class="flex flex-1 items-center gap-1 text-xs text-gray-500">
                                    <label for="caja-condensado-search" class="font-medium text-gray-600 whitespace-nowrap">Proveedor:</label>
                                    <input
                                        id="caja-condensado-search"
                                        v-model="cajaCondensadoProveedorSearch"
                                        type="text"
                                        placeholder="Buscar por nombre o ident"
                                        class="w-full rounded border border-gray-300 px-2 py-1 text-xs text-gray-900 focus:border-gray-900 focus:ring-gray-900"
                                    />
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
                                @click="downloadProveedorModalPdf">
                                Descargar PDF
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
