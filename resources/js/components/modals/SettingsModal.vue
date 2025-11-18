<script setup lang="ts">
import { ref, watch } from 'vue'
import { getSystemSettings, updateSystemSettings, runRestockForecastManual } from '../../api/settings'
import type { RestockHorizon } from '../../api/reports'

const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{ (e: 'close'): void }>()

type Horizon = RestockHorizon

const availableHorizons: Horizon[] = ['2w', '4w', '6w']
const horizonLabels: Record<Horizon, string> = {
    '2w': 'Próximas 2 semanas',
    '4w': 'Próximas 4 semanas',
    '6w': 'Próximas 6 semanas',
}

const selected = ref<Horizon[]>(['2w'])
const cardPercent = ref(4.5)
const minDays = ref(14)
const loading = ref(false)
const saving = ref(false)
const running = ref(false)
const message = ref('')
const error = ref('')
const lastRun = ref<string | null>(null)
const lastClosingBalance = ref<number | null>(null)
const includeZero = ref(false)

watch(
    () => props.open,
    (open) => {
        if (open) {
            loadSettings()
        } else {
            message.value = ''
            error.value = ''
        }
    }
)

async function loadSettings() {
    loading.value = true
    error.value = ''
    try {
        const data = await getSystemSettings()
        selected.value = (data.restock.horizon && data.restock.horizon.length
            ? (data.restock.horizon as Horizon[])
            : ['2w'])
        cardPercent.value = data.card_charge_percent ?? 4.5
        lastRun.value = data.restock.last_run ?? null
        lastClosingBalance.value = data.last_closing_balance ?? null
        includeZero.value = Boolean(data.restock.include_zero)
        minDays.value = data.restock.min_days ?? 14
    } catch (err: any) {
        error.value = err?.response?.data?.message || err?.message || 'No se pudo cargar la configuración.'
    } finally {
        loading.value = false
    }
}

function toggle(value: Horizon) {
    const set = new Set(selected.value)
    if (set.has(value)) {
        set.delete(value)
    } else {
        set.add(value)
    }
    if (set.size === 0) {
        return
    }
    selected.value = Array.from(set) as Horizon[]
}

async function saveSettings() {
    saving.value = true
    error.value = ''
    message.value = ''
    try {
        const data = await updateSystemSettings({
            horizon: selected.value,
            card_charge_percent: cardPercent.value,
            restock_include_zero: includeZero.value,
            restock_min_days: minDays.value,
        })
        selected.value = (data.restock.horizon && data.restock.horizon.length
            ? (data.restock.horizon as Horizon[])
            : ['2w'])
        cardPercent.value = data.card_charge_percent ?? 4.5
        message.value = 'Configuración guardada. Recargando…'
        setTimeout(() => window.location.reload(), 800)
    } catch (err: any) {
        error.value = err?.response?.data?.message || err?.message || 'No se pudo guardar la configuración.'
    } finally {
        saving.value = false
    }
}

async function runForecast() {
    running.value = true
    error.value = ''
    message.value = ''
    try {
        await runRestockForecastManual(selected.value)
        message.value = 'Pronóstico ejecutado correctamente.'
    } catch (err: any) {
        error.value = err?.response?.data?.message || err?.message || 'No se pudo ejecutar el pronóstico.'
    } finally {
        running.value = false
    }
}
</script>

<template>
    <teleport to="body">
        <transition name="fade">
            <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/40" @click="emit('close')"></div>
                <div class="relative z-10 w-full max-w-lg rounded-2xl bg-white shadow-xl">
                    <header class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                        <div>
                            <p class="text-sm uppercase tracking-wide text-gray-500">Configuración</p>
                            <h2 class="text-lg font-semibold text-gray-900">Preferencias del sistema</h2>
                        </div>
                        <button class="rounded border border-gray-300 px-3 py-1.5 text-sm" @click="emit('close')">Cerrar</button>
                    </header>
                    <div class="px-5 py-4 space-y-4 text-sm text-gray-700">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-500">Horizontes incluidos en el cron</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <label v-for="opt in availableHorizons" :key="opt"
                                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium"
                                    :class="selected.includes(opt) ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 text-gray-600'">
                                    <input type="checkbox" class="hidden" :checked="selected.includes(opt)" @change="toggle(opt)" />
                                    {{ horizonLabels[opt] }}
                                </label>
                            </div>
                            <p class="mt-2 text-[11px] text-gray-500">
                                El cron diario ejecutará el pronóstico para los horizontes seleccionados.
                                <span v-if="lastRun" class="block text-gray-400">Última ejecución: {{ lastRun }}</span>
                                <span v-if="lastClosingBalance !== null" class="block text-gray-400">
                                    Último saldo cierre: {{ new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(lastClosingBalance ?? 0) }}
                                </span>
                            </p>
                            <label class="mt-2 inline-flex cursor-pointer items-center gap-2 text-xs text-gray-600">
                                <input type="checkbox" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500"
                                    v-model="includeZero" />
                                <span>Mostrar y notificar productos con sugerencias iguales a 0 (>= 0).</span>
                            </label>
                            <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-gray-600">
                                <div>
                                    <span class="block font-semibold text-gray-700">Inventario mínimo (días)</span>
                                    <input type="number" min="0" max="120"
                                        class="mt-1 w-24 rounded border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                                        v-model.number="minDays" />
                                </div>
                                <p class="max-w-sm text-[11px] text-gray-500">
                                    El modelo garantizará al menos este número de días de inventario adicional sobre el horizonte seleccionado.
                                </p>
                            </div>
                            <button type="button"
                                class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 font-medium hover:bg-gray-50"
                                :disabled="running || loading"
                                @click="runForecast">
                                <span v-if="running">Ejecutando…</span>
                                <span v-else>Ejecutar pronóstico ahora</span>
                            </button>
                        </div>

                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-500">Cargo por tarjeta (%)</p>
                            <div class="mt-2 flex items-center gap-2">
                                <input type="number" step="0.1" min="0" max="100"
                                    class="w-24 rounded border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                                    v-model.number="cardPercent" />
                                <span class="text-[11px] text-gray-500">Aplicado sobre las ganancias del proveedor cuando la venta es con
                                    tarjeta.</span>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                            <button type="button"
                                class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 font-medium hover:bg-gray-50"
                                :disabled="saving || loading"
                                @click="saveSettings">
                                <span v-if="saving">Guardando…</span>
                                <span v-else>Guardar configuración</span>
                            </button>
                            
                        </div>

                        <div v-if="loading" class="text-xs text-gray-500">Cargando ajustes…</div>
                        <div v-if="error" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                            {{ error }}
                        </div>
                        <div v-if="message" class="rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700">
                            {{ message }}
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
