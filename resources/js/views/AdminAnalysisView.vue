<script setup lang="ts">
import { onMounted, ref, watch, computed } from 'vue'
import AppLayout from '../components/layout/AppLayout.vue'
import type {
    AnalysisSummary,
    TopSellersResponse,
    MonthDetailsResponse,
    RecommendedImporteItem,
    RecommendedImporteResponse,
    TopProductsChartResponse,
    TransitionReportResponse,
    TransitionProviderDetailsResponse,
} from '../api/analysis'
import {
    getAnalysisSummary,
    importAnalysisFile,
    getTopSellersMatrix,
    getMonthDetails,
    recalculateRecommendedImportes,
    applyRecommendedImport,
    getTopProductsChart,
    getTransitionReport,
    getTransitionProviderDetails,
} from '../api/analysis'

type TransitionProviderItem = TransitionProviderDetailsResponse['items'][number]

const summary = ref<AnalysisSummary | null>(null)
const loading = ref(false)
const uploading = ref<'ventas' | 'ventadesg' | null>(null)
const successMessage = ref('')
const errorMessage = ref('')
const ventasFile = ref<File | null>(null)
const desgFile = ref<File | null>(null)
const activeTab = ref<'import' | 'topSellers' | 'topProducts' | 'recommended' | 'transition'>('import')
const topData = ref<TopSellersResponse | null>(null)
const topLoading = ref(false)
const topError = ref('')
const productsChartData = ref<TopProductsChartResponse | null>(null)
const productsChartLoading = ref(false)
const productsChartError = ref('')
const productsChartMonths = ref<3 | 6 | 9>(3)
const detailsOpen = ref(false)
const detailsLoading = ref(false)
const detailsError = ref('')
const detailsData = ref<MonthDetailsResponse | null>(null)
const recommendedData = ref<RecommendedImporteItem[]>([])
const recommendedLoading = ref(false)
const recommendedFetchError = ref('')
const recommendedActionError = ref('')
const recommendedMeta = ref<{ percentage: number; months: number; from: string; to: string } | null>(null)
const recommendedSuccess = ref('')
const recommendedFilter = ref<'all' | 'recommended' | 'no'>('all')
const acceptance = ref<Record<string, boolean>>({})
const emailOverrides = ref<Record<string, string>>({})
const applyLoading = ref<string | null>(null)
const recommendedReloading = ref(false)

// Transition report state
const transitionLoading = ref(false)
const transitionError = ref('')
const transitionData = ref<TransitionReportResponse | null>(null)
const transitionMonth = ref('2025-11')
const transitionProviderModalOpen = ref(false)
const transitionProviderDetails = ref<TransitionProviderDetailsResponse | null>(null)
const transitionProviderTarget = ref<{ identifier: string | null; name: string } | null>(null)
const transitionProviderLoading = ref(false)
const transitionProviderError = ref('')

function assignRecommendedResponse(response: RecommendedImporteResponse) {
    recommendedData.value = response.items
    recommendedMeta.value = response.settings
    const accepts: Record<string, boolean> = {}
    const emails: Record<string, string> = {}
    response.items.forEach((row) => {
        accepts[row.provider_ident] = false
        emails[row.provider_ident] = row.provider_email || ''
    })
    acceptance.value = accepts
    emailOverrides.value = emails
}

async function loadSummary() {
    loading.value = true
    errorMessage.value = ''
    try {
        summary.value = await getAnalysisSummary()
    } catch (err: any) {
        errorMessage.value = err?.response?.data?.message || err?.message || 'No se pudo cargar el resumen.'
        summary.value = null
    } finally {
        loading.value = false
    }
}

async function handleImport(type: 'ventas' | 'ventadesg') {
    const file = type === 'ventas' ? ventasFile.value : desgFile.value
    if (!file) {
        errorMessage.value = 'Selecciona un archivo CSV antes de importar.'
        return
    }

    uploading.value = type
    successMessage.value = ''
    errorMessage.value = ''
    try {
        const response = await importAnalysisFile(type, file)
        successMessage.value = response.message || 'Datos importados.'
        await loadSummary()
        if (type === 'ventas') ventasFile.value = null
        if (type === 'ventadesg') desgFile.value = null
        resetInput(type)
    } catch (err: any) {
        errorMessage.value = err?.response?.data?.message || err?.message || 'No se pudo importar el archivo.'
    } finally {
        uploading.value = null
    }
}

function resetInput(type: 'ventas' | 'ventadesg') {
    const element = document.getElementById(type === 'ventas' ? 'ventas-file' : 'ventadesg-file') as HTMLInputElement | null
    if (element) {
        element.value = ''
    }
}

onMounted(() => {
    loadSummary()
})

watch(
    () => activeTab.value,
    async (tab) => {
        if (tab === 'topSellers' && !topData.value && !topLoading.value) {
            await loadTopSellers()
        }
        if (tab === 'topProducts' && !productsChartData.value && !productsChartLoading.value) {
            await loadTopProductsChart()
        }
        if (tab === 'recommended' && recommendedData.value.length === 0 && !recommendedLoading.value) {
            await loadRecommended()
        }
        if (tab === 'transition' && !transitionData.value && !transitionLoading.value) {
            await loadTransitionReport()
        }
    }
)

watch(
    () => productsChartMonths.value,
    async () => {
        if (activeTab.value === 'topProducts') {
            await loadTopProductsChart()
        }
    }
)

async function loadTopSellers() {
    topLoading.value = true
    topError.value = ''
    try {
        topData.value = await getTopSellersMatrix()
    } catch (err: any) {
        topError.value = err?.response?.data?.message || err?.message || 'No se pudo cargar el análisis.'
        topData.value = null
    } finally {
        topLoading.value = false
    }
}

async function openMonthDetails(providerIdent: string, monthKey: string) {
    detailsOpen.value = true
    detailsLoading.value = true
    detailsError.value = ''
    detailsData.value = null
    try {
        detailsData.value = await getMonthDetails({ provider_ident: providerIdent, month: monthKey })
    } catch (err: any) {
        detailsError.value = err?.response?.data?.message || err?.message || 'No se pudo cargar el detalle.'
    } finally {
        detailsLoading.value = false
    }
}

async function loadRecommended() {
    recommendedLoading.value = true
    recommendedFetchError.value = ''
    recommendedActionError.value = ''
    recommendedSuccess.value = ''
    try {
        const response = await recalculateRecommendedImportes()
        assignRecommendedResponse(response)
    } catch (err: any) {
        recommendedFetchError.value = err?.response?.data?.message || err?.message || 'No se pudo calcular los importes.'
        recommendedData.value = []
        recommendedMeta.value = null
        acceptance.value = {}
        emailOverrides.value = {}
    } finally {
        recommendedLoading.value = false
    }
}

async function reloadRecommended() {
    recommendedReloading.value = true
    recommendedFetchError.value = ''
    recommendedActionError.value = ''
    recommendedSuccess.value = ''
    try {
        const response = await recalculateRecommendedImportes()
        assignRecommendedResponse(response)
        recommendedSuccess.value = 'Importes recomendados recalculados.'
    } catch (err: any) {
        recommendedFetchError.value = err?.response?.data?.message || err?.message || 'No se pudo recalcular los importes.'
    } finally {
        recommendedReloading.value = false
    }
}

const filteredRecommended = computed(() => {
    if (recommendedFilter.value === 'recommended') {
        return recommendedData.value.filter((row) => row.is_recommended)
    }
    if (recommendedFilter.value === 'no') {
        return recommendedData.value.filter((row) => !row.is_recommended)
    }
    return recommendedData.value
})

function getRowEmail(row: RecommendedImporteItem) {
    return emailOverrides.value[row.provider_ident] ?? row.provider_email ?? ''
}

const productsChartOrder = ref<'quantity' | 'amount'>('quantity')
const topProductsBars = computed(() => {
    if (!productsChartData.value) return []
    const items = [...productsChartData.value.items]
    if (productsChartOrder.value === 'amount') {
        items.sort((a, b) => b.total_amount - a.total_amount)
    } else {
        items.sort((a, b) => b.total_quantity - a.total_quantity)
    }
    const max = Math.max(...items.map((item) => items.length ? (productsChartOrder.value === 'amount' ? item.total_amount : item.total_quantity) : 0), 0)
    return items.map((item) => ({
        ident: item.producto_ident ?? 'sin-id',
        nombre: item.producto_nombre ?? (item.producto_ident ? `Producto ${item.producto_ident}` : 'Producto sin nombre'),
        proveedor: item.proveedor_nombre ?? 'Proveedor sin nombre',
        cantidad: item.total_quantity,
        monto: item.total_amount,
        percent: max > 0 ? Math.round(((productsChartOrder.value === 'amount' ? item.total_amount : item.total_quantity) / max) * 100) : 0,
    }))
})

async function loadTopProductsChart() {
    productsChartLoading.value = true
    productsChartError.value = ''
    try {
        productsChartData.value = await getTopProductsChart({ months: productsChartMonths.value })
    } catch (err: any) {
        productsChartError.value = err?.response?.data?.message || err?.message || 'No se pudo cargar los productos más vendidos.'
        productsChartData.value = null
    } finally {
        productsChartLoading.value = false
    }
}

async function loadTransitionReport() {
    transitionLoading.value = true
    transitionError.value = ''
    try {
        transitionData.value = await getTransitionReport({ month: transitionMonth.value })
    } catch (err: any) {
        transitionError.value = err?.response?.data?.message || err?.message || 'No se pudo generar el reporte.'
        transitionData.value = null
    } finally {
        transitionLoading.value = false
    }
}

async function openTransitionProviderDetails(row: { provider_ident: string | null; provider_name: string }) {
    if (!transitionData.value) return
    if (!row.provider_ident) return

    transitionProviderModalOpen.value = true
    transitionProviderTarget.value = {
        identifier: row.provider_ident,
        name: row.provider_name,
    }
    transitionProviderLoading.value = true
    transitionProviderError.value = ''
    transitionProviderDetails.value = null

    try {
        transitionProviderDetails.value = await getTransitionProviderDetails({
            month: transitionMonth.value,
            provider_ident: row.provider_ident,
        })
        console.log(transitionProviderDetails.value)
    } catch (err: any) {
        transitionProviderError.value = err?.response?.data?.message || err?.message || 'No se pudo cargar el detalle.'
    } finally {
        transitionProviderLoading.value = false
    }
}

async function handleApplyImport(row: RecommendedImporteItem, sendEmail: boolean) {
    recommendedActionError.value = ''
    recommendedSuccess.value = ''
    if (!acceptance.value[row.provider_ident]) {
        recommendedActionError.value = 'Confirma que el proveedor aceptó el nuevo importe antes de actualizar.'
        return
    }
    const email = getRowEmail(row)
    if (sendEmail && !email) {
        recommendedActionError.value = 'Proporciona un correo electrónico para enviar la confirmación.'
        return
    }
    applyLoading.value = row.provider_ident
    try {
        await applyRecommendedImport({
            provider_ident: row.provider_ident,
            importe: row.recommended_importe,
            accepted: true,
            send_email: sendEmail,
            email: sendEmail ? email : undefined,
        })
        row.current_importe = row.recommended_importe
        row.is_recommended = true
        recommendedSuccess.value = sendEmail ? 'Importe actualizado y correo enviado.' : 'Importe actualizado correctamente.'
    } catch (err: any) {
        recommendedActionError.value = err?.response?.data?.message || err?.message || 'No se pudo actualizar el importe.'
    } finally {
        applyLoading.value = null
    }
}
</script>

<template>
    <AppLayout>
        <div class="p-6 space-y-6 overflow-x-hidden">
            <div class="flex flex-col gap-2">
                <div>
                    <h1 class="text-xl font-semibold text-gray-900">Análisis histórico</h1>
                    <p class="text-sm text-gray-600">Importa los CSV y consulta métricas avanzadas basadas en datos históricos.</p>
                </div>
                <div class="flex gap-3 border-b border-gray-200">
                    <button
                        class="px-3 py-2 text-sm font-semibold"
                        :class="activeTab === 'import' ? 'text-emerald-700 border-b-2 border-emerald-600' : 'text-gray-500'"
                        @click="activeTab = 'import'">
                        Importar datos
                    </button>
                    <button
                        class="px-3 py-2 text-sm font-semibold"
                        :class="activeTab === 'topSellers' ? 'text-emerald-700 border-b-2 border-emerald-600' : 'text-gray-500'"
                        @click="activeTab = 'topSellers'">
                        Top sellers (proveedores)
                    </button>
                    <button
                        class="px-3 py-2 text-sm font-semibold"
                        :class="activeTab === 'topProducts' ? 'text-emerald-700 border-b-2 border-emerald-600' : 'text-gray-500'"
                        @click="activeTab = 'topProducts'">
                        Productos más vendidos
                    </button>
                    <button
                        class="px-3 py-2 text-sm font-semibold"
                        :class="activeTab === 'recommended' ? 'text-emerald-700 border-b-2 border-emerald-600' : 'text-gray-500'"
                        @click="activeTab = 'recommended'">
                        Importes recomendados
                    </button>
                    <button
                        class="px-3 py-2 text-sm font-semibold"
                        :class="activeTab === 'transition' ? 'text-emerald-700 border-b-2 border-emerald-600' : 'text-gray-500'"
                        @click="activeTab = 'transition'">
                        Reporte transición (Nov)
                    </button>
                </div>
            </div>

            <div v-if="activeTab === 'import'" class="grid gap-4 md:grid-cols-2">
                <section class="rounded-xl border border-gray-200 bg-white p-4 space-y-3">
                    <div>
                        <h2 class="font-semibold text-gray-900">Ventas (cabecera)</h2>
                        <p class="text-xs text-gray-500">Archivo con columnas: id, idventa, totalventa, método, recibo, cambio, vendedor, fecha, ie, concepto.</p>
                    </div>
                    <div class="text-sm text-gray-700">
                        <p class="text-xs uppercase text-gray-400">Resumen</p>
                        <p v-if="summary">Registros: <span class="font-semibold">{{ summary.ventas.rows }}</span></p>
                        <p v-if="summary && summary.ventas.from">De {{ summary.ventas.from }} a {{ summary.ventas.to }}</p>
                        <p v-else class="text-gray-500 text-xs">Aún no se importan datos.</p>
                    </div>
                    <div class="space-y-2 text-sm">
                        <input id="ventas-file" type="file" accept=".csv,text/csv"
                            @change="ventasFile = ($event.target as HTMLInputElement).files?.[0] ?? null"
                            class="block w-full text-sm text-gray-600 file:mr-3 file:rounded file:border file:border-gray-200 file:bg-white file:px-3 file:py-1.5" />
                        <button type="button"
                            class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-60"
                            :disabled="uploading === 'ventas'"
                            @click="handleImport('ventas')">
                            <span v-if="uploading === 'ventas'">Importando…</span>
                            <span v-else>Importar archivo</span>
                        </button>
                    </div>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-4 space-y-3">
                    <div>
                        <h2 class="font-semibold text-gray-900">Ventas desglose (productos)</h2>
                        <p class="text-xs text-gray-500">Archivo con columnas: id, idventa, fecha, idProd, nombre, proveedor, pUni, cant, total, totdesc, hora.</p>
                    </div>
                    <div class="text-sm text-gray-700">
                        <p class="text-xs uppercase text-gray-400">Resumen</p>
                        <p v-if="summary">Registros: <span class="font-semibold">{{ summary.ventadesg.rows }}</span></p>
                        <p v-if="summary && summary.ventadesg.from">De {{ summary.ventadesg.from }} a {{ summary.ventadesg.to }}</p>
                        <p v-else class="text-gray-500 text-xs">Aún no se importan datos.</p>
                    </div>
                    <div class="space-y-2 text-sm">
                        <input id="ventadesg-file" type="file" accept=".csv,text/csv"
                            @change="desgFile = ($event.target as HTMLInputElement).files?.[0] ?? null"
                            class="block w-full text-sm text-gray-600 file:mr-3 file:rounded file:border file:border-gray-200 file:bg-white file:px-3 file:py-1.5" />
                        <button type="button"
                            class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-60"
                            :disabled="uploading === 'ventadesg'"
                            @click="handleImport('ventadesg')">
                            <span v-if="uploading === 'ventadesg'">Importando…</span>
                            <span v-else>Importar archivo</span>
                        </button>
                    </div>
                </section>
            </div>

            <div v-if="activeTab === 'import'">
                <div v-if="loading" class="text-sm text-gray-500">Actualizando resumen…</div>
                <div v-if="successMessage"
                    class="rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                    {{ successMessage }}
                </div>
                <div v-if="errorMessage"
                    class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                    {{ errorMessage }}
                </div>
            </div>

            <div v-else-if="activeTab === 'topSellers'">
                <div v-if="topLoading" class="text-sm text-gray-500">Calculando top sellers…</div>
                <div v-else-if="topError" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                    {{ topError }}
                </div>
                <div v-else-if="topData && topData.rows.length" class="grid gap-4 md:grid-cols-2 overflow-x-auto">
                    <table class="min-w-[640px] divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-3 py-2 text-left bg-gray-50 md:sticky md:left-0 md:z-20">Proveedor</th>
                                <th v-for="month in topData.months" :key="month.key" class="px-3 py-2 text-right">
                                    {{ month.label }}
                                </th>
                                <th class="px-3 py-2 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="row in topData.rows" :key="row.provider_ident || row.provider_name"
                                class="hover:bg-emerald-50">
                                <td class="px-3 py-2 border-r border-gray-100 bg-white md:sticky md:left-0 md:z-10">
                                    <p class="font-semibold text-gray-900">{{ row.provider_name }}</p>
                                    <p class="text-[11px] text-gray-500">ID: {{ row.provider_ident || 'N/A' }}</p>
                                </td>
                                <td v-for="month in topData.months" :key="month.key + row.provider_ident"
                                    class="px-3 py-2 text-right whitespace-nowrap">
                                    <button type="button"
                                        class="text-emerald-700 hover:text-emerald-900 underline text-xs font-semibold"
                                        @click="openMonthDetails(row.provider_ident, month.key)">
                                        {{ row.totals[month.key]?.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) ?? '$0.00' }}
                                    </button>
                                </td>
                                <td class="px-3 py-2 text-right font-semibold text-gray-900">
                                    {{ row.grand_total.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="text-sm text-gray-500">Aún no hay datos suficientes para calcular top sellers.</div>
            </div>

            <div v-else-if="activeTab === 'topProducts'">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 class="text-base font-semibold text-gray-800">Top 20 productos más vendidos</h2>
                        <p class="text-sm text-gray-500">
                            Basado en las ventas históricas registradas.
                        </p>
                        <p v-if="productsChartData" class="text-xs text-gray-400 mt-1">
                            Rango analizado: {{ productsChartData.range.from }} — {{ productsChartData.range.to }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 text-sm text-gray-600">
                        <label>
                            <span class="mr-2 font-medium text-gray-700">Últimos meses</span>
                            <select v-model.number="productsChartMonths"
                                class="rounded border border-gray-300 px-3 py-1.5 text-sm focus:border-emerald-700 focus:ring-emerald-700">
                                <option :value="3">3 meses</option>
                                <option :value="6">6 meses</option>
                                <option :value="9">9 meses</option>
                            </select>
                        </label>
                        <label>
                            <span class="mr-2 font-medium text-gray-700">Ordenar por</span>
                            <select v-model="productsChartOrder"
                                class="rounded border border-gray-300 px-3 py-1.5 text-sm focus:border-emerald-700 focus:ring-emerald-700">
                                <option value="quantity">Cantidad vendida</option>
                                <option value="amount">Monto de venta</option>
                            </select>
                        </label>
                    </div>
                </div>

                <div v-if="productsChartError"
                    class="mt-4 rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                    {{ productsChartError }}
                </div>
                <div v-else-if="productsChartLoading" class="mt-4 text-sm text-gray-500">
                    Cargando productos más vendidos…
                </div>
                <div v-else-if="productsChartData && productsChartData.items.length === 0" class="mt-4 text-sm text-gray-500">
                    No hay datos suficientes para este periodo.
                </div>
                <div v-else class="mt-4 space-y-3 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <div v-for="item in topProductsBars" :key="item.ident" class="space-y-1">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium text-gray-800 truncate">{{ item.nombre }}</span>
                            <span class="text-xs text-gray-500">Vendidos: {{ item.cantidad }}</span>
                        </div>
                        <p class="text-[11px] text-gray-500">Proveedor: {{ item.proveedor }}</p>
                        <div class="text-[11px] text-gray-500">
                            Valor aproximado: {{ item.monto.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) }}
                        </div>
                        <div class="h-3 rounded bg-gray-200 overflow-hidden">
                            <div class="h-full rounded bg-emerald-600" :style="{ width: item.percent + '%' }"></div>
                        </div>
                        <p class="text-[11px] text-gray-500">ID: {{ item.ident }}</p>
                    </div>
                </div>
            </div>

            <div v-else-if="activeTab === 'recommended'">
                <p class="text-xs text-gray-500 mb-3">
                    * El importe recomendado corresponde al {{ recommendedMeta?.percentage ?? 5 }}% del promedio mensual de ventas históricas
                    ({{ recommendedMeta?.months ?? 12 }} meses considerados<span v-if="recommendedMeta">
                        , de {{ recommendedMeta.from }} a {{ recommendedMeta.to }}
                    </span>).
                </p>
                <div class="flex flex-wrap items-center gap-3 text-xs text-gray-600">
                    <div class="flex items-center gap-2">
                        <label class="font-semibold">Mostrar:</label>
                        <select v-model="recommendedFilter" class="rounded border border-gray-300 px-3 py-1">
                            <option value="all">Todos</option>
                            <option value="recommended">Solo recomendados</option>
                            <option value="no">Solo no recomendados</option>
                        </select>
                    </div>
                    <span v-if="recommendedSuccess" class="rounded border border-emerald-200 bg-emerald-50 px-3 py-1 text-emerald-700">{{ recommendedSuccess }}</span>
                    <span v-if="recommendedActionError" class="rounded border border-rose-200 bg-rose-50 px-3 py-1 text-rose-700">{{ recommendedActionError }}</span>
                </div>
                <div v-if="recommendedLoading" class="text-sm text-gray-500">Calculando importes recomendados…</div>
                <div v-else-if="recommendedFetchError" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                    {{ recommendedFetchError }}
                </div>
                <div v-else-if="recommendedData.length" class="space-y-3">
                    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                        <table class="min-w-[640px] divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-3 py-2 text-left">Proveedor</th>
                                    <th class="px-3 py-2 text-right">Importe actual</th>
                                    <th class="px-3 py-2 text-right">Total ventas histórico</th>
                                    <th class="px-3 py-2 text-right">Meses</th>
                                    <th class="px-3 py-2 text-right">Promedio mensual</th>
                                    <th class="px-3 py-2 text-right">Importe recomendado</th>
                                    <th class="px-3 py-2 text-left">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="row in filteredRecommended" :key="row.provider_ident" class="hover:bg-emerald-50">
                                    <td class="px-3 py-2">
                                        <p class="font-semibold text-gray-900">{{ row.provider_name }}</p>
                                        <p class="text-xs text-gray-500">ID: {{ row.provider_ident }}</p>
                                        <span class="mt-1 inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                            :class="row.is_recommended ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'">
                                            {{ row.is_recommended ? 'Recomendado' : 'No recomendado' }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-right">{{ row.current_importe.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) }}</td>
                                    <td class="px-3 py-2 text-right">{{ row.total_sales.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) }}</td>
                                    <td class="px-3 py-2 text-right">{{ row.months }}</td>
                                    <td class="px-3 py-2 text-right">{{ row.avg_monthly_sales.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) }}</td>
                                    <td class="px-3 py-2 text-right font-semibold text-emerald-700">
                                        {{ row.recommended_importe.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) }}
                                    </td>
                                    <td class="px-3 py-2 text-xs">
                                        <label class="inline-flex items-center gap-2 text-[11px] text-gray-600">
                                            <input type="checkbox" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500"
                                                v-model="acceptance[row.provider_ident]" />
                                            <span>Proveedor aceptó</span>
                                        </label>
                                        <input type="email" class="mt-2 w-full rounded border border-gray-300 px-3 py-1.5 text-xs focus:border-emerald-600 focus:ring-emerald-600"
                                            placeholder="Correo del proveedor"
                                            v-model="emailOverrides[row.provider_ident]" />
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            <button type="button"
                                                class="rounded border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                                                :disabled="applyLoading === row.provider_ident"
                                                @click="handleApplyImport(row, false)">
                                                <span v-if="applyLoading === row.provider_ident">Aplicando…</span>
                                                <span v-else>Aplicar importe</span>
                                            </button>
                                            <button type="button"
                                                class="rounded border border-emerald-500 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50 disabled:opacity-60"
                                                :disabled="applyLoading === row.provider_ident"
                                                @click="handleApplyImport(row, true)">
                                                <span v-if="applyLoading === row.provider_ident">Enviando…</span>
                                                <span v-else>Enviar confirmación</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex justify-end">
                        <button type="button"
                            class="inline-flex items-center gap-2 rounded border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                            :disabled="recommendedReloading"
                            @click="reloadRecommended">
                            <svg v-if="recommendedReloading" class="h-3 w-3 animate-spin text-gray-500" viewBox="0 0 24 24" fill="none">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4l3-3-3-3v4a8 8 0 000 16v-4l-3 3 3 3v-4a8 8 0 01-8-8z"></path>
                            </svg>
                            <span>{{ recommendedReloading ? 'Recalculando…' : 'Recalcular importes sugeridos' }}</span>
                        </button>
                    </div>
                </div>
                <div v-else class="text-sm text-gray-500">No hay datos suficientes para calcular importes.</div>
            </div>

            <div v-else-if="activeTab === 'transition'">
                <div class="flex flex-wrap items-center gap-3 text-sm text-gray-600">
                    <label class="flex items-center gap-2">
                        <span class="font-semibold text-gray-700">Mes a consultar</span>
                        <input type="month" v-model="transitionMonth"
                            class="rounded border border-gray-300 px-3 py-1.5 focus:border-emerald-700 focus:ring-emerald-700" />
                    </label>
                    <button type="button"
                        class="inline-flex items-center rounded border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                        :disabled="transitionLoading"
                        @click="loadTransitionReport">
                        <span v-if="transitionLoading">Consultando…</span>
                        <span v-else>Consultar</span>
                    </button>
                    <span v-if="transitionError" class="rounded border border-rose-200 bg-rose-50 px-3 py-1 text-xs text-rose-700">
                        {{ transitionError }}
                    </span>
                </div>

                <div v-if="transitionLoading" class="mt-4 text-sm text-gray-500">Calculando reporte de transición…</div>
                <div v-else-if="transitionData" class="mt-4 space-y-6">
                    <div class="grid gap-4 md:grid-cols-3">
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <p class="text-xs uppercase tracking-wide text-gray-500">Periodo</p>
                            <p class="text-sm font-semibold text-gray-900">{{ transitionData.range.from }} — {{ transitionData.range.to }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <p class="text-xs uppercase tracking-wide text-gray-500">Ventas registradas</p>
                            <p class="text-lg font-semibold text-gray-900">
                                {{ transitionData.sales.total_sales.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) }}
                            </p>
                            <p class="text-xs text-gray-500">Tickets: {{ transitionData.sales.tickets }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <p class="text-xs uppercase tracking-wide text-gray-500">Cobrado</p>
                            <p class="text-lg font-semibold text-gray-900">
                                {{ transitionData.sales.total_recibido.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) }}
                            </p>
                        </div>
                    </div>

                    <section class="rounded-xl border border-gray-200 bg-white p-4 space-y-3">
                        <header>
                            <h3 class="text-sm font-semibold text-gray-900">Caja condensado (transición)</h3>
                            <p class="text-xs text-gray-500">Ventas consolidadas por proveedor.</p>
                        </header>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                    <tr>
                                        <th class="px-3 py-2 text-left">Proveedor</th>
                                        <th class="px-3 py-2 text-right">Ventas brutas</th>
                                        <th class="px-3 py-2 text-right">Descuentos</th>
                                        <th class="px-3 py-2 text-right">Ventas netas</th>
                                        <th class="px-3 py-2 text-left">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-for="row in transitionData.caja_condensado" :key="`${row.provider_ident ?? 'sin'}-${row.provider_name}`">
                                        <td class="px-3 py-2">
                                            <p class="font-semibold text-gray-900">{{ row.provider_name }}</p>
                                            <p class="text-xs text-gray-500">Ident: {{ row.provider_ident ?? '—' }}</p>
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px]"
                                                :class="row.legacy ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'">
                                                {{ row.legacy ? 'Histórico' : 'Actual' }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            {{ row.total_publico.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) }}
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            {{ row.descuentos.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) }}
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            {{ row.total_neto.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) }}
                                        </td>
                                        <td class="px-3 py-2 text-left text-xs text-gray-500">
                                            <button v-if="row.provider_ident"
                                                type="button"
                                                class="inline-flex items-center rounded-full border border-gray-300 px-2 py-0.5 text-[11px] text-gray-600 hover:bg-gray-50"
                                                @click="openTransitionProviderDetails(row)">
                                                Ver productos
                                            </button>
                                            <span v-else>--</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
                <div v-else class="mt-4 text-sm text-gray-500">Consulta el reporte para ver los datos combinados de noviembre.</div>
            </div>
        </div>

        <teleport to="body">
            <transition name="fade">
                <div v-if="detailsOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-black/40" @click="detailsOpen = false"></div>
                    <div class="relative z-10 w-full max-w-3xl h-[80vh] rounded-2xl bg-white shadow-xl flex flex-col">
                        <header class="border-b border-gray-200 px-5 py-4 flex items-center justify-between">
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">Detalle mensual</p>
                                <h2 class="text-lg font-semibold text-gray-900">
                                    {{ detailsData?.provider_name ?? 'Proveedor' }} · {{ detailsData?.month }}
                                </h2>
                            </div>
                            <button class="rounded border border-gray-300 px-3 py-1.5 text-sm" @click="detailsOpen = false">Cerrar</button>
                        </header>
                        <div class="px-5 py-4 space-y-3 text-sm flex-1 overflow-y-auto">
                            <div v-if="detailsLoading" class="text-gray-500">Cargando productos…</div>
                            <div v-else-if="detailsError" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-rose-700">
                                {{ detailsError }}
                            </div>
                            <div v-else-if="detailsData && detailsData.items.length">
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                                            <tr>
                                                <th class="px-3 py-2 text-left">Producto</th>
                                                <th class="px-3 py-2 text-left">Ident</th>
                                                <th class="px-3 py-2 text-right">Cantidad</th>
                                                <th class="px-3 py-2 text-right">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            <tr v-for="item in detailsData.items" :key="item.producto_ident ?? item.producto_nombre ?? 'unknown'">
                                                <td class="px-3 py-2 text-gray-900">{{ item.producto_nombre || 'Producto sin nombre' }}</td>
                                                <td class="px-3 py-2 text-gray-500 text-xs">{{ item.producto_ident || 'N/A' }}</td>
                                                <td class="px-3 py-2 text-right">{{ item.cantidad.toLocaleString('es-MX') }}</td>
                                                <td class="px-3 py-2 text-right">
                                                    {{ item.total.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) }}
                                                </td>
                                            </tr>
                                        </tbody>
                                        <tfoot class="bg-gray-50 text-xs uppercase text-gray-500">
                                            <tr>
                                                <td class="px-3 py-2 font-semibold text-right" colspan="2">Totales</td>
                                                <td class="px-3 py-2 text-right font-semibold">
                                                    {{ detailsData.totals.cantidad.toLocaleString('es-MX') }}
                                                </td>
                                                <td class="px-3 py-2 text-right font-semibold">
                                                    {{ detailsData.totals.monto.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) }}
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                            <div v-else class="text-gray-500">No hay productos registrados para este mes.</div>
                        </div>
                    </div>
                </div>
            </transition>
            <transition name="fade">
                <div v-if="transitionProviderModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-black/40" @click="transitionProviderModalOpen = false"></div>
                    <div class="relative z-10 w-full max-w-4xl h-[80vh] rounded-2xl bg-white shadow-xl flex flex-col">
                        <header class="border-b border-gray-200 px-5 py-4 flex items-center justify-between">
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">Detalle transición · Noviembre</p>
                                <h2 class="text-lg font-semibold text-gray-900">
                                    {{ transitionProviderTarget?.name ?? 'Proveedor' }} · Ident {{ transitionProviderTarget?.identifier ?? '—' }}
                                </h2>
                            </div>
                            <button class="rounded border border-gray-300 px-3 py-1.5 text-sm" @click="transitionProviderModalOpen = false">Cerrar</button>
                        </header>
                        <div class="px-5 py-4 space-y-4 text-sm flex-1 overflow-y-auto">
                            <div v-if="transitionProviderLoading" class="text-gray-500">Cargando ventas…</div>
                            <div v-else-if="transitionProviderError" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-rose-700">
                                {{ transitionProviderError }}
                            </div>
                            <div v-else-if="transitionProviderDetails">
                                <section class="space-y-2">
                                    <h3 class="text-sm font-semibold text-gray-900">Ventas por producto</h3>
                                    <div class="overflow-x-auto rounded border border-gray-200">
                                        <table class="min-w-full divide-y divide-gray-200 text-xs">
                                            <thead class="bg-gray-50 text-gray-500 uppercase tracking-wide">
                                                <tr>
                                                    <th class="px-3 py-2 text-left">Venta</th>
                                                    <th class="px-3 py-2 text-left">Fecha</th>
                                                    <th class="px-3 py-2 text-left">Producto</th>
                                                    <th class="px-3 py-2 text-right">Cantidad</th>
                                                    <th class="px-3 py-2 text-right">Monto</th>
                                                    <th class="px-3 py-2 text-right">Descuento</th>
                                                    <th class="px-3 py-2 text-left">Método</th>
                                                    <th class="px-3 py-2 text-left">Vendedor</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100">
                                                <tr v-for="item in transitionProviderDetails.items" :key="`${item.venta_id}-${item.fecha}`">
                                                    <td class="px-3 py-2">{{ item.venta_id }}</td>
                                                    <td class="px-3 py-2">{{ item.fecha ?? '—' }}</td>
                                                    <td class="px-3 py-2">
                                                        <p class="font-medium text-gray-800">{{ item.producto_nombre ?? 'Producto sin nombre' }}</p>
                                                        <p class="text-[11px] text-gray-500">Ident: {{ item.producto_ident ?? '—' }}</p>
                                                    </td>
                                                    <td class="px-3 py-2 text-right">{{ item.cantidad.toLocaleString('es-MX') }}</td>
                                                    <td class="px-3 py-2 text-right">{{ item.monto.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) }}</td>
                                                    <td class="px-3 py-2 text-right">{{ item.descuento.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) }}</td>
                                                    <td class="px-3 py-2 capitalize">{{ item.metodo ?? '—' }}</td>
                                                    <td class="px-3 py-2">{{ item.vendedor ?? '—' }}</td>
                                                </tr>
                                                <tr v-if="transitionProviderDetails.items.length === 0">
                                                    <td colspan="8" class="px-3 py-4 text-center text-gray-500">Sin líneas asociadas.</td>
                                                </tr>
                                            </tbody>
                                            <tfoot v-if="transitionProviderDetails.items && transitionProviderDetails.items.length" class="bg-gray-50 text-gray-500 uppercase tracking-wide">
                                                <tr>
                                                    <td class="px-3 py-2 font-semibold text-right" colspan="3">Totales</td>
                                                    <td class="px-3 py-2 text-right font-semibold">
                                                        {{
                                                            transitionProviderDetails.items
                                                                .reduce((sum: number, item: TransitionProviderItem) => sum + (Number(item.cantidad) || 0), 0)
                                                                .toLocaleString('es-MX')
                                                        }}
                                                    </td>
                                                    <td class="px-3 py-2 text-right font-semibold">
                                                        {{
                                                            transitionProviderDetails.items
                                                                .reduce((sum: number, item: TransitionProviderItem) => sum + Number(item.monto || 0), 0)
                                                                .toLocaleString('es-MX', { style: 'currency', currency: 'MXN' })
                                                        }}
                                                    </td>
                                                    <td class="px-3 py-2 text-right font-semibold">
                                                        {{
                                                            transitionProviderDetails.items
                                                                .reduce((sum: number, item: TransitionProviderItem) => sum + Number(item.descuento || 0), 0)
                                                                .toLocaleString('es-MX', { style: 'currency', currency: 'MXN' })
                                                        }}
                                                    </td>
                                                    <td colspan="2"></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </section>
                            </div>
                            <div v-else class="text-gray-500">Selecciona un proveedor para ver su detalle.</div>
                        </div>
                    </div>
                </div>
            </transition>
        </teleport>
    </AppLayout>
</template>
