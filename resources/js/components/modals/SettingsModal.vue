<script setup lang="ts">
import { ref, watch } from 'vue'
import { getSystemSettings, updateSystemSettings, runRestockForecastManual, runCashAutoClose } from '../../api/settings'
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
const restockLookback = ref(90)
const recommendedPercent = ref(5)
const recommendedMonths = ref(12)
const loading = ref(false)
const saving = ref(false)
const running = ref(false)
const runningAutoClose = ref(false)
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
        restockLookback.value = data.restock.lookback_days ?? 90
        recommendedPercent.value = data.analysis?.recommended_percentage ?? 5
        recommendedMonths.value = data.analysis?.recommended_months ?? 12
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
            restock_lookback_days: restockLookback.value,
            recommended_percentage: recommendedPercent.value,
            recommended_months: recommendedMonths.value,
        })
        selected.value = (data.restock.horizon && data.restock.horizon.length
            ? (data.restock.horizon as Horizon[])
            : ['2w'])
        cardPercent.value = data.card_charge_percent ?? 4.5
        recommendedPercent.value = data.analysis?.recommended_percentage ?? recommendedPercent.value
        recommendedMonths.value = data.analysis?.recommended_months ?? recommendedMonths.value
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

async function runAutoCloseCashbox() {
    runningAutoClose.value = true
    error.value = ''
    message.value = ''
    try {
        const { message: msg, dates } = await runCashAutoClose()
        message.value = dates?.length ? `${msg} Fechas: ${dates.join(', ')}` : msg
    } catch (err: any) {
        error.value = err?.response?.data?.message || err?.message || 'No se pudo ejecutar el cierre automático.'
    } finally {
        runningAutoClose.value = false
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
                    <div class="px-5 py-4 space-y-6 text-sm text-gray-700 divide-y divide-gray-200">
                        <section class="space-y-2 pt-0">
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">Pronósticos de restock</p>
                                <p class="text-[11px] text-gray-500">Define cómo se alimentan el widget de restock y los reportes condensados.</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <label v-for="opt in availableHorizons" :key="opt"
                                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium"
                                    :class="selected.includes(opt) ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 text-gray-600'">
                                    <input type="checkbox" class="hidden" :checked="selected.includes(opt)" @change="toggle(opt)" />
                                    {{ horizonLabels[opt] }}
                                </label>
                            </div>
                            <p class="text-[11px] text-gray-500">
                                Los horizontes seleccionados se ejecutan diariamente.<span v-if="lastRun" class="block text-gray-400">Última ejecución: {{ lastRun }}</span>
                            </p>
                            <label class="inline-flex cursor-pointer items-center gap-2 text-xs text-gray-600">
                                <input type="checkbox" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500"
                                    v-model="includeZero" />
                                <span>Mostrar también sugerencias en cero en widgets y reportes.</span>
                            </label>
                            <div class="flex flex-wrap items-center gap-2 text-xs text-gray-600">
                                <div>
                                    <span class="block font-semibold text-gray-700">Inventario mínimo (días)</span>
                                    <input type="number" min="0" max="120"
                                        class="mt-1 w-24 rounded border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                                        v-model.number="minDays" />
                                </div>
                                <p class="max-w-sm text-[11px] text-gray-500">
                                    Días adicionales que el sistema reserva al generar las alertas de restock.
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2 text-xs text-gray-600">
                                <div>
                                    <span class="block font-semibold text-gray-700">Lookback (días)</span>
                                    <input type="number" min="30" max="365" step="5"
                                        class="mt-1 w-24 rounded border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                                        v-model.number="restockLookback" />
                                </div>
                                <p class="max-w-sm text-[11px] text-gray-500">
                                    Periodo histórico mínimo que se usará para calcular ventas; si las tablas actuales no cubren ese rango, se complementa automáticamente con las tablas históricas.
                                </p>
                            </div>
                            <button type="button"
                                class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 font-medium hover:bg-gray-50"
                                :disabled="running || loading"
                                @click="runForecast">
                                <span v-if="running">Ejecutando…</span>
                                <span v-else>Ejecutar pronóstico ahora</span>
                            </button>
                            <button type="button"
                                class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 font-medium hover:bg-gray-50"
                                :disabled="runningAutoClose || loading"
                                @click="runAutoCloseCashbox">
                                <span v-if="runningAutoClose">Cerrando…</span>
                                <span v-else>Forzar cierre de caja (hoy)</span>
                            </button>
                        </section>

                        <section class="space-y-2 pt-4">
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">Pagos / Caja</p>
                                <p class="text-[11px] text-gray-500">Aplicado en reportes de caja y cálculo de pagos a proveedores.</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="number" step="0.1" min="0" max="100"
                                    class="w-24 rounded border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                                    v-model.number="cardPercent" />
                                <span class="text-[11px] text-gray-500">Porcentaje descontado cuando una venta se paga con tarjeta.</span>
                            </div>
                        </section>

                        <section class="space-y-2 pt-4">
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">Análisis históricos</p>
                                <p class="text-[11px] text-gray-500">Usado en la pestaña “Importes recomendados”.</p>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="text-xs text-gray-600">
                                    <span class="block font-semibold text-gray-700">Porcentaje sobre ventas (%)</span>
                                    <input type="number" step="0.1" min="0" max="100"
                                        class="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                                        v-model.number="recommendedPercent" />
                                </label>
                                <label class="text-xs text-gray-600">
                                    <span class="block font-semibold text-gray-700">Meses históricos</span>
                                    <input type="number" min="1" max="60"
                                        class="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                                        v-model.number="recommendedMonths" />
                                </label>
                            </div>
                            <p class="text-[11px] text-gray-500">
                                Controla el porcentaje y ventana de tiempo usados para sugerir nuevos importes en el módulo de Análisis.
                            </p>
                        </section>

                        <section class="flex flex-wrap items-center gap-2 text-xs text-gray-500 pt-4">
                            <button type="button"
                                class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 font-medium hover:bg-gray-50"
                                :disabled="saving || loading"
                                @click="saveSettings">
                                <span v-if="saving">Guardando…</span>
                                <span v-else>Guardar configuración</span>
                            </button>
                            <span class="text-[11px] text-gray-500">Los cambios impactan inmediatamente los módulos mencionados.</span>
                        </section>

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
