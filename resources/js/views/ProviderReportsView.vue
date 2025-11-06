<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import AppLayout from '../components/layout/AppLayout.vue';
import {
    getCajaProveedoresReport,
    getProviderTrends,
    type CajaProveedoresResponse,
    type CajaProveedorGroup,
    type ProviderTrendsResponse,
} from '../api/reports';
import { use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { BarChart, LineChart } from 'echarts/charts';
import { GridComponent, TooltipComponent, LegendComponent, TitleComponent } from 'echarts/components';
import VChart from 'vue-echarts';

use([CanvasRenderer, BarChart, LineChart, GridComponent, TooltipComponent, LegendComponent, TitleComponent]);

const loading = ref(false);
const downloading = ref(false);
const error = ref('');
const success = ref('');
const report = ref<CajaProveedoresResponse | null>(null);
const selectedDate = ref(new Date().toISOString().slice(0, 10));
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
    const cantidad = items.reduce((sum, item) => sum + item.cantidad, 0);
    const total = items.reduce((sum, item) => sum + item.total, 0);
    const descuentos = items.reduce((sum, item) => sum + item.descuento_total, 0);
    const ganancia = items.reduce((sum, item) => sum + item.ganancia, 0);
    const precioPromedio =
        cantidad > 0 ? items.reduce((sum, item) => sum + item.precio_unitario * item.cantidad, 0) / cantidad : 0;
    return { cantidad, precioPromedio, total, descuentos, ganancia };
});

const topProductsOption = computed(() => {
    const list = trends.value?.top_products ?? [];
    return {
        tooltip: { trigger: 'axis' },
        grid: { left: '3%', right: '4%', bottom: '5%', containLabel: true },
        xAxis: {
            type: 'category',
            data: list.map((item) => item.nombre),
            axisLabel: { rotate: 30 },
        },
        yAxis: { type: 'value', name: 'Cantidad vendida' },
        series: [
            {
                type: 'bar',
                data: list.map((item) => item.cantidad),
                itemStyle: { color: '#E4007C' },
                barMaxWidth: 40,
            },
        ],
    };
});

const earningsOption = computed(() => {
    const list = trends.value?.earnings ?? [];
    return {
        tooltip: { trigger: 'axis' },
        grid: { left: '3%', right: '4%', bottom: '5%', containLabel: true },
        xAxis: {
            type: 'category',
            data: list.map((item) => item.date),
            axisLabel: { rotate: 30 },
        },
        yAxis: { type: 'value', name: 'Ganancia' },
        series: [
            {
                name: 'Ganancia',
                type: 'line',
                smooth: true,
                data: list.map((item) => item.amount),
                itemStyle: { color: '#2D68C4' },
                areaStyle: { color: 'rgba(45,104,196,0.15)' },
            },
        ],
    };
});

async function fetchReport() {
    if (!selectedDate.value) return;
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
            const total = data?.resumen?.ganancias ?? 0;
            success.value = `Reporte del ${data.from_date} al ${data.to_date} listo. Ganancia estimada: $${total.toFixed(2)}.`;
        }
    } catch (err: any) {
        error.value = err?.response?.data?.message || 'No se pudo generar el reporte.';
    } finally {
        loading.value = false;
    }
}

async function downloadReport() {
    if (!selectedDate.value) return;
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
        success.value = `Descarga completada para ${selectedDate.value}.`;
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
    const endDate = new Date(selectedDate.value);
    const startDateObj = new Date(endDate);
    startDateObj.setDate(endDate.getDate() - 9);
    const formatDate = (d: Date) =>
        `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${String(d.getFullYear()).slice(-2)}`;
    return {
        fromDate: formatDate(startDateObj),
        toDate: formatDate(endDate),
    };
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
                <button type="button"
                    class="px-4 py-2 text-sm font-medium rounded-t-lg border-b-2 border-transparent text-gray-500 hover:text-[#E4007C]/80"
                    :class="activeTab === 'summary' ? 'text-[#E4007C] border-[#E4007C] bg-white shadow-sm' : ''"
                    @click="selectTab('summary')">
                    Resumen diario
                </button>
                <button type="button"
                    class="px-4 py-2 text-sm font-medium rounded-t-lg border-b-2 border-transparent text-gray-500 hover:text-[#E4007C]/80"
                    :class="activeTab === 'trends' ? 'text-[#E4007C] border-[#E4007C] bg-white shadow-sm' : ''"
                    @click="selectTab('trends')">
                    Tendencias (10 días)
                </button>
            </div>


            <div v-if="activeTab === 'summary'"
                class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm space-y-4">
                <div>
                    <h2 class="text-base font-semibold text-gray-800">Reporte diario</h2>
                    <p class="text-sm text-gray-500">
                        Selecciona una fecha para obtener el resumen de ventas y cargos correspondiente a ese día.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <label class="text-sm text-gray-700 flex items-center gap-2">
                        <span>Fecha</span>
                        <input type="date" v-model="selectedDate"
                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-[#E4007C] focus:ring-[#E4007C]">
                    </label>
                    <button type="button"
                        class="rounded-lg bg-[#E4007C] px-4 py-2 text-sm font-medium text-white hover:bg-[#cc006f] disabled:opacity-60 disabled:cursor-not-allowed"
                        :disabled="loading" @click="fetchReport">
                        {{ loading ? 'Generando…' : 'Generar resumen' }}
                    </button>
                    <button type="button"
                        class="rounded-lg border border-[#E4007C] px-4 py-2 text-sm font-medium text-[#E4007C] hover:bg-[#E4007C]/10 disabled:opacity-60 disabled:cursor-not-allowed"
                        :disabled="downloading" @click="downloadReport">
                        {{ downloading ? 'Descargando…' : 'Descargar CSV' }}
                    </button>
                    <p class="text-xs text-gray-500">
                        El cálculo se realiza con base en las ventas registradas en la fecha seleccionada.
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
                    <div v-if="resumen" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                            <p class="text-xs uppercase tracking-wide text-gray-500">Ventas brutas</p>
                            <p class="text-lg font-semibold text-gray-900">${{ resumen.ventas_brutas.toFixed(2) }}</p>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                            <p class="text-xs uppercase tracking-wide text-gray-500">Descuentos</p>
                            <p class="text-lg font-semibold text-gray-900">${{ resumen.descuentos.toFixed(2) }}</p>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                            <p class="text-xs uppercase tracking-wide text-gray-500">Cargos tarjeta</p>
                            <p class="text-lg font-semibold text-gray-900">${{ resumen.cargos_tarjeta.toFixed(2) }}</p>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                            <p class="text-xs uppercase tracking-wide text-gray-500">Ganancia total</p>
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
                                        <th class="px-4 py-2 text-left">Producto</th>
                                        <th class="px-4 py-2 text-right">Cantidad</th>
                                        <th class="px-4 py-2 text-right">Precio</th>
                                        <th class="px-4 py-2 text-right">Total</th>
                                        <th class="px-4 py-2 text-right">Descuentos</th>
                                        <th class="px-4 py-2 text-right">Ganancia</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-if="providerGroup.items.length === 0">
                                        <td colspan="7" class="px-4 py-4 text-center text-gray-500">
                                            No se registraron ventas para la fecha seleccionada.
                                        </td>
                                    </tr>
                                    <tr v-for="item in providerGroup.items" :key="item.ventadesg_id"
                                        class="hover:bg-gray-50">
                                        <td class="px-4 py-2 text-gray-600">{{ item.fecha }}</td>
                                        <td class="px-4 py-2 font-medium text-gray-900">{{ item.producto_nombre }}</td>
                                        <td class="px-4 py-2 text-right text-gray-700">{{ item.cantidad }}</td>
                                        <td class="px-4 py-2 text-right text-gray-700">${{
                                            item.precio_unitario.toFixed(2) }}</td>
                                        <td class="px-4 py-2 text-right text-gray-700">${{ item.total.toFixed(2) }}</td>
                                        <td class="px-4 py-2 text-right text-gray-700">${{
                                            item.descuento_total.toFixed(2) }}</td>
                                        <td class="px-4 py-2 text-right text-gray-700">${{ item.ganancia.toFixed(2) }}
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot v-if="providerTotals">
                                    <tr class="bg-gray-50 text-gray-800 font-semibold">
                                        <td class="px-4 py-2 text-left" colspan="2">Totales</td>
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
                                            ${{ providerTotals.descuentos.toFixed(2) }}
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
                    <section class="space-y-3">
                        <h3 class="text-sm font-semibold text-gray-800">Productos más vendidos</h3>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                            <VChart :option="topProductsOption" autoresize class="h-72" />
                        </div>
                    </section>

                    <section class="space-y-3">
                        <h3 class="text-sm font-semibold text-gray-800">Ganancia por día</h3>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                            <VChart :option="earningsOption" autoresize class="h-72" />
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
