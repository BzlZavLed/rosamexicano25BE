<script setup lang="ts">
import { computed, ref, watch, toRef } from 'vue'
import type { RestockForecastItem } from '../../api/reports'
import { getRestockForecastReport, notifyRestockForecast } from '../../api/reports'

const props = withDefaults(defineProps<{
    open: boolean
    horizon: 'day' | 'week' | 'month'
}>(), {
    horizon: 'week',
})
const emit = defineEmits<{ (e: 'close'): void }>()
const horizonRef = toRef(props, 'horizon')

const horizonLabels: Record<'day' | 'week' | 'month', string> = {
    day: 'Próximo día',
    week: 'Próxima semana',
    month: 'Próximo mes',
}

const loading = ref(false)
const fatalError = ref('')
const actionError = ref('')
const success = ref('')
const items = ref<RestockForecastItem[]>([])
const forecastDate = ref<string>('')
const lookbackDays = ref<number>(0)
const leadTimeDays = ref<number>(0)
const sendingAll = ref(false)
const sendingProvider = ref<string | null>(null)

const filteredItems = computed(() => items.value.filter((item) => item.suggested_order_qty >= 0))
const providerCount = computed(() => new Set(filteredItems.value.map((item) => item.provider_ident)).size)

watch(
    () => props.open,
    (open) => {
        if (open) {
            loadReport()
        } else {
            fatalError.value = ''
            actionError.value = ''
            success.value = ''
        }
    }
)

watch(
    horizonRef,
    () => {
        if (props.open) {
            loadReport()
        }
    }
)

async function loadReport() {
    loading.value = true
    fatalError.value = ''
    actionError.value = ''
    success.value = ''
    try {
        const data = await getRestockForecastReport({ horizon: horizonRef.value })
        items.value = data.items
        forecastDate.value = data.forecast_date
        lookbackDays.value = data.lookback_days
        leadTimeDays.value = data.lead_time_days
    } catch (err: any) {
        fatalError.value = err?.response?.data?.message || err?.message || 'No se pudo cargar el pronóstico.'
        items.value = []
    } finally {
        loading.value = false
    }
}

async function notifyAllProviders() {
    sendingAll.value = true
    actionError.value = ''
    success.value = ''
    try {
        const response = await notifyRestockForecast({ horizon: horizonRef.value })
        success.value = `${response.sent} proveedores notificados.`
    } catch (err: any) {
        actionError.value = err?.response?.data?.message || err?.message || 'No se pudieron enviar las notificaciones.'
    } finally {
        sendingAll.value = false
    }
}

async function notifyProvider(ident: string) {
    if (!ident) {
        return
    }
    sendingProvider.value = ident
    actionError.value = ''
    success.value = ''
    try {
        const response = await notifyRestockForecast({ horizon: horizonRef.value, providers: [ident] })
        success.value = response.sent > 0 ? 'Proveedor notificado.' : 'No se pudo notificar al proveedor.'
    } catch (err: any) {
        actionError.value = err?.response?.data?.message || err?.message || 'No se pudo enviar la notificación.'
    } finally {
        sendingProvider.value = null
    }
}
</script>

<template>
    <teleport to="body">
        <transition name="fade">
            <div v-if="open" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4">
                <div class="absolute inset-0 bg-black/40" @click="emit('close')" />
                <div class="relative z-10 mt-8 w-full max-w-5xl rounded-2xl bg-white shadow-2xl">
                    <header class="flex flex-wrap items-start justify-between gap-4 border-b border-gray-200 px-6 py-4">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-500">Pronóstico de restock</p>
                            <h2 class="text-xl font-semibold text-gray-900">{{ horizonLabels[horizonRef] }}</h2>
                            <p class="text-xs text-gray-500">
                                Pronosticado el {{ forecastDate || '—' }} · Ventas últimas {{ lookbackDays }} días · Tiempo de entrega estimado {{ leadTimeDays }} días
                            </p>
                        </div>
                        <div class="flex items-center gap-2 text-xs">
                            <button type="button"
                                class="rounded border border-gray-300 px-3 py-1.5 hover:bg-gray-50"
                                @click="emit('close')">Cerrar</button>
                            <button type="button"
                                class="inline-flex items-center gap-2 rounded bg-emerald-600 px-3 py-1.5 font-medium text-white hover:bg-emerald-700 disabled:opacity-60"
                                :disabled="sendingAll || filteredItems.length === 0"
                                @click="notifyAllProviders">
                                <span v-if="sendingAll">Enviando…</span>
                                <span v-else>Notificar a todos ({{ providerCount }})</span>
                            </button>
                        </div>
                    </header>
                    <div class="px-6 py-4 text-sm">
                        <div v-if="loading" class="text-gray-500">Cargando pronóstico…</div>
                        <div v-else-if="fatalError" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-rose-700">{{ fatalError }}</div>
                        <div v-else>
                            <p v-if="filteredItems.length === 0" class="text-gray-500">No hay productos con sugerencia de resurtido mayor a cero.</p>
                            <div v-else class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 text-left text-xs">
                                    <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
                                        <tr>
                                            <th class="px-3 py-2">Proveedor / Producto</th>
                                            <th class="px-3 py-2 text-right">Sugerido</th>
                                            <th class="px-3 py-2 text-right">Inventario</th>
                                            <th class="px-3 py-2 text-right">Promedio diario</th>
                                            <th class="px-3 py-2 text-right">Cobertura</th>
                                            <th class="px-3 py-2 text-right">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <tr v-for="item in filteredItems" :key="item.provider_ident + '-' + item.producto_ident">
                                            <td class="px-3 py-2 align-top">
                                                <p class="font-semibold text-gray-900">{{ item.producto_nombre ?? ('Producto ' + item.producto_ident) }}</p>
                                                <p class="text-[11px] text-gray-500">
                                                    {{ item.provider_name ?? ('Proveedor ' + item.provider_ident) }}
                                                    <span v-if="item.provider_email" class="text-emerald-600">· {{ item.provider_email }}</span>
                                                    <span v-else class="text-rose-600">· Sin email</span>
                                                </p>
                                                <p class="text-[11px] text-gray-400">ID: {{ item.producto_ident }}</p>
                                            </td>
                                            <td class="px-3 py-2 text-right font-semibold text-gray-900">{{ item.suggested_order_qty }}</td>
                                            <td class="px-3 py-2 text-right text-gray-700">{{ item.inventory_on_hand }}</td>
                                            <td class="px-3 py-2 text-right text-gray-700">{{ item.avg_daily_sales.toFixed(2) }}</td>
                                            <td class="px-3 py-2 text-right text-gray-700">
                                                {{ item.days_of_cover !== null ? item.days_of_cover + ' días' : 'Sin datos' }}
                                            </td>
                                            <td class="px-3 py-2 text-right">
                                                <button type="button"
                                                    class="rounded border border-gray-300 px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                                                    :disabled="sendingProvider === item.provider_ident || !item.provider_email || !item.provider_ident"
                                                    @click="notifyProvider(item.provider_ident)">
                                                    <span v-if="sendingProvider === item.provider_ident">Enviando…</span>
                                                    <span v-else>Notificar</span>
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div v-if="success" class="mt-3 rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700">
                            {{ success }}
                        </div>
                        <div v-if="actionError" class="mt-3 rounded border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                            {{ actionError }}
                        </div>
                    </div>
                </div>
            </div>
        </transition>
    </teleport>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
