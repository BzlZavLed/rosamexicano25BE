<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { getRestockAlerts } from '../../api/widgets'
import { updateRestockPreference } from '../../api/reports'
import type { RestockHorizon } from '../../api/reports'
import RestockForecastModal from '../modals/RestockForecastModal.vue'

type Horizon = RestockHorizon

const horizonOptions: Array<{ value: Horizon; label: string }> = [
    { value: '2w', label: 'Próximas 2 semanas' },
    { value: '4w', label: 'Próximas 4 semanas' },
    { value: '6w', label: 'Próximas 6 semanas' },
]

const loading = ref(false)
const saving = ref(false)
const error = ref('')
const horizon = ref<Horizon>('2w')
const items = ref<Awaited<ReturnType<typeof getRestockAlerts>>['items']>([])
const forecastDate = ref<string>('')
const showDetails = ref(false)
const visibleItems = computed(() => items.value.slice(0, 3))

async function loadAlerts() {
    loading.value = true
    error.value = ''
    try {
        const data = await getRestockAlerts({ horizon: horizon.value, limit: 5 })
        forecastDate.value = data.forecast_date
        horizon.value = data.horizon
        items.value = data.items
    } catch (err: any) {
        error.value = err?.response?.data?.message || err?.message || 'No se pudo cargar el pronóstico.'
        items.value = []
    } finally {
        loading.value = false
    }
}

async function changeHorizon(value: Horizon) {
    horizon.value = value
    saving.value = true
    try {
        await updateRestockPreference(value)
    } catch (err) {
        // ignore preference errors; user will still see selected horizon
    } finally {
        saving.value = false
    }
    await loadAlerts()
}

onMounted(() => {
    loadAlerts()
})
</script>

<template>
    <div class="sm:col-span-2 xl:col-span-2 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="text-sm font-semibold text-gray-900">Alertas de restock</p>
                <p class="text-xs text-gray-500">Prioriza productos con menor cobertura.</p>
            </div>
            <label class="flex items-center gap-1 text-xs text-gray-500">
                <span>Horizonte</span>
                <select
                    :value="horizon"
                    class="rounded border border-gray-300 px-2 py-1 text-xs focus:border-gray-900 focus:ring-gray-900"
                    :disabled="saving || loading"
                    @change="changeHorizon(($event.target as HTMLSelectElement).value as Horizon)"
                >
                    <option v-for="opt in horizonOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
            </label>
        </div>

        <div class="mt-3 text-xs text-gray-500" v-if="forecastDate">
            Última actualización: <span class="font-semibold text-gray-900">{{ forecastDate }}</span>
        </div>

        <div v-if="error" class="mt-3 rounded border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
            {{ error }}
        </div>
        <div v-else-if="loading" class="mt-3 text-xs text-gray-500">Cargando datos…</div>
        <div v-else-if="items.length === 0" class="mt-3 text-xs text-gray-500">
            No hay sugerencias de restock para este horizonte.
        </div>
        <div v-else>
            <ul class="mt-3 space-y-2">
                <li v-for="item in visibleItems" :key="item.provider_ident + '-' + item.producto_ident"
                    class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-semibold text-gray-900">{{ item.producto_nombre ?? `Producto ${item.producto_ident}` }}</p>
                            <p class="text-[11px] text-gray-500">
                                Proveedor: {{ item.provider_name ?? item.provider_ident }}
                                <span v-if="item.restock_asap" class="ml-1 rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-semibold text-rose-700">
                                    Restock ASAP
                                </span>
                            </p>
                        </div>
                        <div class="text-right text-xs text-gray-600">
                            <p><span class="font-semibold text-gray-900">{{ item.suggested_order_qty }}</span> sugeridas</p>
                            <p v-if="item.days_of_cover !== null">Cobertura: {{ item.days_of_cover }} días</p>
                            <p v-else class="text-rose-600">Sin ventas recientes</p>
                        </div>
                    </div>
                <div class="mt-1 flex items-center justify-between text-[11px] text-gray-500">
                    <span>Inventario: {{ item.inventory_on_hand }}</span>
                    <span>Promedio diario: {{ item.avg_daily_sales.toFixed(2) }}</span>
                </div>
                <p class="mt-1 text-[11px] text-gray-500">
                    Reabastecer antes de <span class="font-semibold text-gray-900">{{ item.restock_by_date }}</span>
                </p>
            </li>
        </ul>
            <button type="button" class="mt-3 text-xs font-semibold text-emerald-700 hover:text-emerald-800" @click="showDetails = true">
                Ver todo el pronóstico →
            </button>
        </div>
    </div>
    <RestockForecastModal :open="showDetails" :horizon="horizon" @close="showDetails = false" />
</template>
