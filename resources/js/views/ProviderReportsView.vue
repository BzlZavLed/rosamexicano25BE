<script setup lang="ts">
import { computed, onMounted, ref, reactive } from 'vue';
import AppLayout from '../components/layout/AppLayout.vue';
import {
    getCajaProveedoresReport,
    getProviderTrends,
    type CajaProveedoresResponse,
    type CajaProveedorGroup,
    type ProviderTrendsResponse,
} from '../api/reports';
const loading = ref(false);
const downloading = ref(false);
const error = ref('');
const success = ref('');
const report = ref<CajaProveedoresResponse | null>(null);
const today = new Date();
const todayIso = formatDate(today);
const defaultMonth = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`;
const rangeMode = ref<'month' | 'range'>('month');
const selectedMonth = ref(defaultMonth);
const customRange = reactive({
    from: formatDate(startOfMonth(today)),
    to: todayIso,
});
const activeTab = ref<'summary' | 'trends'>('summary');

const trends = ref<ProviderTrendsResponse | null>(null);
const trendsLoading = ref(false);
const trendsError = ref('');
const trendsLoaded = ref(false);

const providerGroup = computed<CajaProveedorGroup | null>(() => {
    const groups: CajaProveedorGroup[] = report.value?.proveedores ?? [];
    if (groups.length === 0) return null;
    const firstGroup: CajaProveedorGroup | undefined = groups[0];
    return firstGroup ?? null;
});

const resumen = computed(() => report.value?.resumen ?? null);
const providerTotals = computed(() => {
    const items = providerGroup.value?.items ?? [];
    if (!items.length) return null;
    const cantidad = items.reduce((sum, item) => sum + Number(item.cantidad ?? 0), 0);
    const total = items.reduce((sum, item) => sum + Number(item.total ?? 0), 0);
    const providerDiscount = items.reduce((sum, item) => sum + Number(item.provider_discount ?? 0), 0);
    const manualDiscount = items.reduce((sum, item) => sum + Number(item.manual_discount ?? 0), 0);
    const cardFee = items.reduce((sum, item) => sum + Number(item.card_fee ?? 0), 0);
    const ganancia = items.reduce(
        (sum, item) => sum + Number(item.real_earning ?? item.expected_earning ?? 0),
        0
    );
    const precioPromedio =
        cantidad > 0 ? items.reduce((sum, item) => sum + Number(item.precio_unitario ?? 0) * Number(item.cantidad ?? 0), 0) / cantidad : 0;
    return { cantidad, precioPromedio, total, providerDiscount, manualDiscount, cardFee, ganancia };
});


function formatCurrency(value: number | string | null | undefined): string {
    const num = typeof value === 'string' ? Number(value) : value;
    if (!Number.isFinite(num)) return '$0.00';
    return Number(num).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' });
}

const topProductsBars = computed(() => {
    const list = trends.value?.top_products ?? [];
    if (!list.length) return [];
    const maxQty = Math.max(...list.map((item) => item.cantidad), 0);
    return list.map((item) => {
        const percentRaw = maxQty > 0 ? (item.cantidad / maxQty) * 100 : 0;
        const percent =
            maxQty > 0 ? Math.min(Math.max(percentRaw, 4), 100) : 0;
        return {
            ...item,
            percent,
            totalFormatted: formatCurrency(item.total ?? 0),
        };
    });
});

const earningsChart = computed(() => {
    const list = trends.value?.earnings ?? [];
    if (!list.length) return null;

    const sorted = [...list].sort((a, b) => a.date.localeCompare(b.date));
    const amounts = sorted.map((item) => item.amount);
    const max = Math.max(...amounts);
    const min = Math.min(...amounts);
    const range = max - min || 1;

    const points = sorted.map((item, idx) => {
        const x = sorted.length === 1 ? 0 : (idx / (sorted.length - 1)) * 100;
        const y = range === 0 ? 50 : 100 - ((item.amount - min) / range) * 100;
        return {
            x,
            y,
            label: item.date,
            dateDisplay: (() => {
                const d = new Date(item.date);
                const day = String(d.getDate()).padStart(2, '0');
                const month = String(d.getMonth() + 1).padStart(2, '0');
                return `${day}-${month}`;
            })(),
            amount: item.amount,
        };
    });

    const polyline = points.map((p) => `${p.x},${p.y}`).join(' ');
    const areaPoints = `0,100 ${polyline} 100,100`;

    return {
        points,
        polyline,
        areaPoints,
        min,
        max,
    };
});

async function fetchReport() {
    loading.value = true;
    error.value = '';
    success.value = '';
    report.value = null;
    try {
        const { fromDate, toDate } = computeRange();
        const data = await getCajaProveedoresReport({
            from_date: fromDate,
            to_date: toDate,
        });
        if (data instanceof Blob) {
            success.value = 'El reporte se generó correctamente. Revisa tu carpeta de descargas.';
        } else {
            report.value = data;
            success.value = '';
        }
    } catch (err: any) {
        error.value = err?.response?.data?.message || 'No se pudo generar el reporte.';
    } finally {
        loading.value = false;
    }
}

async function downloadReport() {
    downloading.value = true;
    error.value = '';
    try {
        const { fromDate, toDate } = computeRange();
        const blob = await getCajaProveedoresReport({
            from_date: fromDate,
            to_date: toDate,
            download: true,
        });
        if (!(blob instanceof Blob)) {
            throw new Error('El servidor no devolvió un archivo para descargar.');
        }
        const filename = `reporte_caja_proveedor_${fromDate}_al_${toDate}.csv`;
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
        success.value = `Descarga completada para el periodo ${fromDate} al ${toDate}.`;
    } catch (err: any) {
        error.value = err?.response?.data?.message || err?.message || 'No se pudo descargar el reporte.';
    } finally {
        downloading.value = false;
    }
}

onMounted(fetchReport);

async function fetchTrends() {
    const { fromDate, toDate } = computeRange();
    trendsLoading.value = true;
    trendsError.value = '';
    try {
        trends.value = await getProviderTrends({ from_date: fromDate, to_date: toDate });
        trendsLoaded.value = true;
    } catch (err: any) {
        trendsError.value = err?.response?.data?.message || err?.message || 'No se pudieron cargar las tendencias.';
    } finally {
        trendsLoading.value = false;
    }
}

function selectTab(tab: 'summary' | 'trends') {
    activeTab.value = tab;
    if (tab === 'trends' && !trendsLoaded.value) {
        fetchTrends();
    }
}

function computeRange() {
    if (rangeMode.value === 'month') {
        const [yearStr, monthStr] = (selectedMonth.value || defaultMonth).split('-');
        const year = Number(yearStr) || today.getFullYear();
        const monthIndex = Number(monthStr) - 1;
        const firstDay = new Date(year, isNaN(monthIndex) ? today.getMonth() : monthIndex, 1);
        const lastDay = new Date(firstDay.getFullYear(), firstDay.getMonth() + 1, 0);
        return {
            fromDate: formatDate(firstDay),
            toDate: formatDate(lastDay),
        };
    }
    const from = customRange.from ? new Date(customRange.from) : startOfMonth(today);
    const to = customRange.to ? new Date(customRange.to) : today;
    const fromTime = from.getTime();
    const toTime = to.getTime();
    if (fromTime > toTime) {
        return {
            fromDate: formatDate(to),
            toDate: formatDate(from),
        };
    }
    return {
        fromDate: formatDate(from),
        toDate: formatDate(to),
    };
}

function formatDate(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function startOfMonth(date: Date): Date {
    return new Date(date.getFullYear(), date.getMonth(), 1);
}
</script>

<template>
    <AppLayout>
        <div class="p-6 space-y-6">
            <header>
                <h1 class="text-xl font-semibold text-gray-900">Reportes</h1>
                <p class="text-sm text-gray-500">
                    Genera un resumen rápido de ventas por proveedor para la fecha actual. Para periodos más amplios
                    contacta al equipo administrativo.
                </p>

            </header>
            <div class="flex flex-wrap gap-2 border-b border-gray-200 pb-2">
                <button
                    type="button"
                    class="px-4 py-2 text-sm font-medium rounded-t-lg border-b-2 border-transparent text-gray-500 hover:text-[#E4007C]/80"
                    :class="activeTab === 'summary' ? 'text-[#E4007C] border-[#E4007C] bg-white shadow-sm' : ''"
                    @click="selectTab('summary')"
                >
                    Resumen (mes / rango)
                </button>
                <button
                    type="button"
                    class="px-4 py-2 text-sm font-medium rounded-t-lg border-b-2 border-transparent text-gray-500 hover:text-[#E4007C]/80"
                    :class="activeTab === 'trends' ? 'text-[#E4007C] border-[#E4007C] bg-white shadow-sm' : ''"
                    @click="selectTab('trends')"
                >
                    Tendencias (periodo seleccionado)
                </button>
            </div>


            <div v-if="activeTab === 'summary'"
                class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm space-y-4">
                <div>
                    <h2 class="text-base font-semibold text-gray-800">Reporte por mes o rango</h2>
                    <p class="text-sm text-gray-500">
                        Selecciona un mes completo o un rango personalizado de fechas para generar el resumen de ventas.
                    </p>
                </div>

                <div class="flex flex-col gap-3">
                    <div class="flex flex-wrap items-center gap-4 text-sm text-gray-700">
                        <label class="flex items-center gap-2">
                            <input
                                type="radio"
                                value="month"
                                v-model="rangeMode"
                                class="text-[#E4007C] focus:ring-[#E4007C]"
                            />
                            <span>Mes completo</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input
                                type="radio"
                                value="range"
                                v-model="rangeMode"
                                class="text-[#E4007C] focus:ring-[#E4007C]"
                            />
                            <span>Rango personalizado</span>
                        </label>
                    </div>
                    <div v-if="rangeMode === 'month'" class="flex flex-wrap items-center gap-3">
                        <label class="text-sm text-gray-700 flex items-center gap-2">
                            <span>Mes</span>
                            <input
                                type="month"
                                v-model="selectedMonth"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-[#E4007C] focus:ring-[#E4007C]"
                            />
                        </label>
                    </div>
                    <div v-else class="flex flex-wrap items-center gap-3">
                        <label class="text-sm text-gray-700 flex items-center gap-2">
                            <span>Desde</span>
                            <input
                                type="date"
                                v-model="customRange.from"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-[#E4007C] focus:ring-[#E4007C]"
                            />
                        </label>
                        <label class="text-sm text-gray-700 flex items-center gap-2">
                            <span>Hasta</span>
                            <input
                                type="date"
                                v-model="customRange.to"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-[#E4007C] focus:ring-[#E4007C]"
                            />
                        </label>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <button
                            type="button"
                            class="rounded-lg bg-[#E4007C] px-4 py-2 text-sm font-medium text-white hover:bg-[#cc006f] disabled:opacity-60 disabled:cursor-not-allowed"
                            :disabled="loading"
                            @click="fetchReport"
                        >
                        {{ loading ? 'Generando…' : 'Generar resumen' }}
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-[#E4007C] px-4 py-2 text-sm font-medium text-[#E4007C] hover:bg-[#E4007C]/10 disabled:opacity-60 disabled:cursor-not-allowed"
                            :disabled="downloading"
                            @click="downloadReport"
                        >
                        {{ downloading ? 'Descargando…' : 'Descargar CSV' }}
                        </button>
                    </div>
                    <p class="text-xs text-gray-500">
                        El cálculo se realiza con base en las ventas registradas durante el periodo seleccionado.
                    </p>
                </div>

                <p v-if="error" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                    {{ error }}
                </p>
                <p v-if="success"
                    class="rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                    {{ success }}
                </p>

                <div v-if="report" class="space-y-4">
                    <div v-if="resumen" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                            <p class="text-xs uppercase tracking-wide text-gray-500">Ventas brutas</p>
                            <p class="text-lg font-semibold text-gray-900">${{ resumen.ventas_brutas.toFixed(2) }}</p>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                            <p class="text-xs uppercase tracking-wide text-gray-500">Desc. proveedor</p>
                            <p class="text-lg font-semibold text-gray-900">${{ resumen.descuentos.toFixed(2) }}</p>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                            <p class="text-xs uppercase tracking-wide text-gray-500">Desc. manual</p>
                            <p class="text-lg font-semibold text-gray-900">
                                ${{ (resumen.manual_descuentos ?? report.manual_descuentos_total ?? 0).toFixed(2) }}
                            </p>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                            <p class="text-xs uppercase tracking-wide text-gray-500">Cargos tarjeta</p>
                            <p class="text-lg font-semibold text-gray-900">${{ resumen.cargos_tarjeta.toFixed(2) }}</p>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                            <p class="text-xs uppercase tracking-wide text-gray-500">Ganancia real</p>
                            <p class="text-lg font-semibold text-gray-900">${{ resumen.ganancias.toFixed(2) }}</p>
                        </div>
                    </div>

                    <div v-if="providerGroup" class="space-y-3">
                        <h3 class="text-sm font-semibold text-gray-800">
                            Detalle de {{ providerGroup.proveedor_nombre }}
                        </h3>
                        <div class="overflow-x-auto rounded-xl border border-gray-200">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                    <tr>
                                        <th class="px-4 py-2 text-left">Fecha</th>
                                        <th class="px-4 py-2 text-left">ID venta</th>
                                        <th class="px-4 py-2 text-left">Producto</th>
                                        <th class="px-4 py-2 text-right">Cantidad</th>
                                        <th class="px-4 py-2 text-right">Precio</th>
                                        <th class="px-4 py-2 text-right">Total</th>
                                        <th class="px-4 py-2 text-right">Desc. proveedor</th>
                                        <th class="px-4 py-2 text-right">Desc. manual</th>
                                        <th class="px-4 py-2 text-right">Cargo tarjeta</th>
                                        <th class="px-4 py-2 text-right">Ganancia real</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-if="providerGroup.items.length === 0">
                                        <td colspan="10" class="px-4 py-4 text-center text-gray-500">
                                            No se registraron ventas para la fecha seleccionada.
                                        </td>
                                    </tr>
                                    <tr v-for="item in providerGroup.items" :key="item.ventadesg_id"
                                        class="hover:bg-gray-50">
                                        <td class="px-4 py-2 text-gray-600">{{ item.fecha }}</td>
                                        <td class="px-4 py-2 text-gray-700">#{{ item.idventa ?? item.venta_id }}</td>
                                        <td class="px-4 py-2 font-medium text-gray-900">{{ item.producto_nombre }}</td>
                                        <td class="px-4 py-2 text-right text-gray-700">{{ item.cantidad }}</td>
                                        <td class="px-4 py-2 text-right text-gray-700">${{
                                            item.precio_unitario.toFixed(2) }}</td>
                                        <td class="px-4 py-2 text-right text-gray-700">${{ item.total.toFixed(2) }}</td>
                                        <td class="px-4 py-2 text-right text-gray-700">${{
                                            item.provider_discount.toFixed(2) }}</td>
                                        <td class="px-4 py-2 text-right text-gray-700">${{
                                            item.manual_discount.toFixed(2) }}</td>
                                        <td class="px-4 py-2 text-right text-gray-700">${{
                                            item.card_fee.toFixed(2) }}</td>
                                        <td class="px-4 py-2 text-right text-gray-700">${{
                                            (item.real_earning ?? item.expected_earning ?? 0).toFixed(2) }}
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot v-if="providerTotals">
                                    <tr class="bg-gray-50 text-gray-800 font-semibold">
                                        <td class="px-4 py-2 text-left" colspan="3">Totales</td>
                                        <td class="px-4 py-2 text-right">
                                            {{ providerTotals.cantidad }}
                                        </td>
                                        <td class="px-4 py-2 text-right">
                                            ${{ providerTotals.precioPromedio.toFixed(2) }}
                                        </td>
                                        <td class="px-4 py-2 text-right">
                                            ${{ providerTotals.total.toFixed(2) }}
                                        </td>
                                        <td class="px-4 py-2 text-right">
                                            ${{ providerTotals.providerDiscount.toFixed(2) }}
                                        </td>
                                        <td class="px-4 py-2 text-right">
                                            ${{ providerTotals.manualDiscount.toFixed(2) }}
                                        </td>
                                        <td class="px-4 py-2 text-right">
                                            ${{ providerTotals.cardFee.toFixed(2) }}
                                        </td>
                                        <td class="px-4 py-2 text-right">
                                            ${{ providerTotals.ganancia.toFixed(2) }}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm space-y-4">
                <div>
                    <h2 class="text-base font-semibold text-gray-800">Tendencias de los últimos 10 días</h2>
                    <p class="text-sm text-gray-500">
                        Visualiza los productos más vendidos y la evolución de tus ganancias durante los últimos diez
                        días.
                    </p>
                </div>

                <div v-if="trendsError"
                    class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                    {{ trendsError }}
                </div>
                <div v-else-if="trendsLoading" class="text-sm text-gray-500 px-3 py-2">
                    Cargando tendencias…
                </div>
                <div v-else-if="trends && trends.top_products.length === 0 && trends.earnings.every((e) => e.amount === 0)"
                    class="text-sm text-gray-500 px-3 py-2">
                    No hay datos suficientes para mostrar tendencias en los últimos diez días.
                </div>
                <div v-else class="space-y-6">
                    <section class="space-y-3" style="min-height: 320px;">
                        <h3 class="text-sm font-semibold text-gray-800">Productos más vendidos</h3>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 space-y-2">
                            <template v-if="topProductsBars.length">
                                <div
                                    v-for="item in topProductsBars"
                                    :key="item.ident"
                                    class="space-y-1"
                                >
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="font-medium text-gray-800">{{ item.nombre }}</span>
                                        <span class="text-xs text-gray-500">Cantidad: {{ item.cantidad }}</span>
                                    </div>
                                    <div class="h-3 rounded bg-gray-200 overflow-hidden">
                                        <div
                                            class="h-full rounded bg-[#E4007C]"
                                            :style="{ width: item.percent + '%' }"
                                        ></div>
                                    </div>
                                    <div class="flex items-center justify-between text-[11px] text-gray-500">
                                        <span>ID producto: {{ item.ident }}</span>
                                        <span>Total: {{ item.totalFormatted }}</span>
                                    </div>
                                </div>
                            </template>
                            <p v-else class="text-sm text-gray-500">Sin datos suficientes para graficar.</p>
                        </div>
                    </section>

                    <section class="space-y-3" style="min-height: 320px;">
                        <h3 class="text-sm font-semibold text-gray-800">Ganancia por día</h3>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 space-y-3">
                            <template v-if="earningsChart">
                                <div class="flex gap-3">
                                    <div class="flex flex-col justify-between text-[11px] text-gray-500">
                                        <span>{{ formatCurrency(earningsChart.max) }}</span>
                                        <span>{{ formatCurrency((earningsChart.max + earningsChart.min) / 2) }}</span>
                                        <span>{{ formatCurrency(earningsChart.min) }}</span>
                                    </div>
                                    <div class="relative flex-1 h-48">
                                        <svg viewBox="0 0 100 100" class="absolute inset-0 w-full h-full" preserveAspectRatio="none">
                                            <g stroke="#e5e7eb" stroke-width="0.5">
                                                <line v-for="n in 5" :key="n" :y1="(n - 1) * 25" :x2="100" :y2="(n - 1) * 25" />
                                            </g>
                                            <polygon :points="earningsChart.areaPoints" fill="rgba(45, 104, 196, 0.15)" />
                                            <polyline
                                                :points="earningsChart.polyline"
                                                fill="none"
                                                stroke="#2D68C4"
                                                stroke-width="1.5"
                                                stroke-linejoin="round"
                                                stroke-linecap="round"
                                            />
                                            <template v-for="point in earningsChart.points" :key="point.label">
                                                <circle :cx="point.x" :cy="point.y" r="1.2" fill="#2D68C4" />
                                            </template>
                                        </svg>
                                    </div>
                                </div>
                                <div class="flex justify-between text-[11px] text-gray-500 px-2">
                                    <span
                                        v-for="point in earningsChart.points"
                                        :key="point.label"
                                        :style="{
                                            width: `${100 / earningsChart.points.length}%`,
                                            textAlign: 'center',
                                        }"
                                    >
                                        {{ point.dateDisplay }}
                                    </span>
                                </div>
                            </template>
                            <p v-else class="text-sm text-gray-500">Sin datos suficientes para graficar.</p>
                        </div>
                    </section>
                </div>
            </div>

            <div class="rounded-xl border border-dashed border-gray-300 bg-white/60 p-5 text-sm text-gray-600">
                <p class="font-medium text-gray-800 mb-1">¿Necesitas reportes personalizados?</p>
                <p>
                    El panel de administrador puede descargar reportes detallados con filtros de fechas y descargar a
                    CSV.
                    Solicita el periodo que necesitas para recibirlos en tu correo.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
