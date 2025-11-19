<script setup lang="ts">
import { computed, ref, watch, toRef } from 'vue'
import type { RestockForecastItem, RestockHorizon } from '../../api/reports'
import { getRestockForecastReport, notifyRestockForecast } from '../../api/reports'
import { getSystemSettings } from '../../api/settings'

type Horizon = RestockHorizon

const props = withDefaults(defineProps<{
    open: boolean
    horizon: Horizon
}>(), {
    horizon: '2w',
})
const emit = defineEmits<{ (e: 'close'): void }>()
const horizonRef = toRef(props, 'horizon')

const horizonLabels: Record<Horizon, string> = {
    '2w': 'Próximas 2 semanas',
    '4w': 'Próximas 4 semanas',
    '6w': 'Próximas 6 semanas',
}

const loading = ref(false)
const fatalError = ref('')
const actionError = ref('')
const success = ref('')
const items = ref<RestockForecastItem[]>([])
const forecastDate = ref<string>('')
const lookbackDays = ref<number>(0)
const leadTimeDays = ref<number>(0)
const minimumDays = ref<number | null>(null)
const sendingAll = ref(false)
const sendingProvider = ref<string | null>(null)
const includeZeroSuggestions = ref(false)
const settingsLoaded = ref(false)

const filteredItems = computed(() =>
    items.value.filter((item) => (includeZeroSuggestions.value ? item.suggested_order_qty >= 0 : item.suggested_order_qty > 0))
)
type ProviderGroup = {
    key: string;
    provider_ident: string | null;
    provider_name: string | null;
    provider_email: string | null;
    items: RestockForecastItem[];
    total_suggested: number;
};

const providerEmailFilter = ref<'all' | 'with' | 'without'>('all');

const providerGroups = computed<ProviderGroup[]>(() => {
    const map = new Map<string, ProviderGroup>();
    const groups: ProviderGroup[] = [];

    filteredItems.value.forEach((item, index) => {
        const ident = item.provider_ident ? String(item.provider_ident) : null;
        const key = ident && ident !== '' ? `id:${ident}` : `missing:${item.provider_name ?? 'sin'}:${index}`;
        let group = map.get(key);
        if (!group) {
            group = {
                key,
                provider_ident: ident,
                provider_name: item.provider_name ?? null,
                provider_email: item.provider_email ?? null,
                items: [],
                total_suggested: 0,
            };
            map.set(key, group);
            groups.push(group);
        }
        if (!group.provider_email && item.provider_email) {
            group.provider_email = item.provider_email;
        }
        if (!group.provider_name && item.provider_name) {
            group.provider_name = item.provider_name;
        }
        group.items.push(item);
        group.total_suggested += item.suggested_order_qty;
    });

    return groups;
});

const filteredProviders = computed(() => {
    return providerGroups.value.filter((group) => {
        if (providerEmailFilter.value === 'with') {
            return Boolean(group.provider_email);
        }
        if (providerEmailFilter.value === 'without') {
            return !group.provider_email;
        }
        return true;
    });
});

const providersWithEmail = computed(() =>
    filteredProviders.value.filter((group) => Boolean(group.provider_ident && group.provider_email))
);

const providerCount = computed(() => providersWithEmail.value.length);

watch(
    () => props.open,
    async (open) => {
        if (open) {
            await ensureSettingsLoaded()
            await loadReport()
        } else {
            fatalError.value = ''
            actionError.value = ''
            success.value = ''
            providerEmailFilter.value = 'all'
        }
    }
)

watch(
    horizonRef,
    async () => {
        if (props.open) {
            await ensureSettingsLoaded()
            await loadReport()
        }
    }
)

async function ensureSettingsLoaded() {
    if (settingsLoaded.value) {
        return
    }
    try {
        const data = await getSystemSettings()
        includeZeroSuggestions.value = Boolean(data.restock.include_zero)
    } catch (err) {
        includeZeroSuggestions.value = false
    } finally {
        settingsLoaded.value = true
    }
}

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
        minimumDays.value = data.minimum_inventory_days ?? null
    } catch (err: any) {
        fatalError.value = err?.response?.data?.message || err?.message || 'No se pudo cargar el pronóstico.'
        items.value = []
    } finally {
        loading.value = false
    }
}

async function notifyAllProviders() {
    const recipients = providersWithEmail.value
        .map((group) => group.provider_ident)
        .filter((ident): ident is string => Boolean(ident))
    if (recipients.length === 0) {
        actionError.value = 'No hay proveedores con correo electrónico en esta vista.'
        return
    }
    sendingAll.value = true
    actionError.value = ''
    success.value = ''
    try {
        const response = await notifyRestockForecast({ horizon: horizonRef.value, providers: recipients })
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
                                Pronosticado el {{ forecastDate || '—' }} · Ventas últimas {{ lookbackDays }} días · Horizonte {{ leadTimeDays }} días · Inventario mínimo {{
                                    minimumDays ?? '—' }} días
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <label class="flex items-center gap-1 text-gray-600">
                                <span>Filtro email</span>
                                <select
                                    v-model="providerEmailFilter"
                                    class="rounded border border-gray-300 px-2 py-1 text-xs focus:border-emerald-600 focus:ring-emerald-600"
                                >
                                    <option value="all">Todos</option>
                                    <option value="with">Con correo</option>
                                    <option value="without">Sin correo</option>
                                </select>
                            </label>
                            <button type="button"
                                class="rounded border border-gray-300 px-3 py-1.5 hover:bg-gray-50"
                                @click="emit('close')">Cerrar</button>
                            <button type="button"
                                class="inline-flex items-center gap-2 rounded bg-emerald-600 px-3 py-1.5 font-medium text-white hover:bg-emerald-700 disabled:opacity-60"
                                :disabled="sendingAll || providerCount === 0"
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
                            <p class="mb-3 text-[11px] text-gray-500">
                                <strong>Inventario actual</strong> refleja las existencias actuales; <strong>Stock recomendado</strong> es la meta proyectada con las ventas promedio para este horizonte.
                            </p>
                            <p v-if="filteredProviders.length === 0" class="text-gray-500">
                                No hay proveedores que coincidan con el filtro actual.
                            </p>
                            <div v-else class="max-h-[60vh] overflow-auto">
                                <table class="min-w-full divide-y divide-gray-200 text-left text-xs">
                                    <thead class="sticky top-0 z-10 bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
                                        <tr>
                                            <th class="px-3 py-2">Proveedor</th>
                                            <th class="px-3 py-2">Productos</th>
                                            <th class="px-3 py-2 text-right">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <tr v-for="group in filteredProviders" :key="group.key">
                                            <td class="px-3 py-2 align-top">
                                                <p class="font-semibold text-gray-900">{{ group.provider_name ?? (group.provider_ident ? 'Proveedor ' + group.provider_ident : 'Proveedor sin nombre') }}</p>
                                                <p class="text-[11px] text-gray-500">ID: {{ group.provider_ident ?? '—' }}</p>
                                                <p class="text-[11px]" :class="group.provider_email ? 'text-emerald-600' : 'text-rose-600'">
                                                    {{ group.provider_email ?? 'Sin correo registrado' }}
                                                </p>
                                                <p class="text-[11px] text-gray-500">Productos: {{ group.items.length }} · Sugerido total: {{ group.total_suggested }}</p>
                                            </td>
                                            <td class="px-3 py-2">
                                                <ul class="space-y-2">
                                                    <li v-for="item in group.items" :key="item.provider_ident + '-' + item.producto_ident" class="rounded border border-gray-100 bg-gray-50 px-3 py-2">
                                                        <div class="flex flex-col gap-1">
                                                            <div class="flex flex-col text-sm text-gray-900">
                                                                <span class="font-semibold">{{ item.producto_nombre ?? ('Producto ' + item.producto_ident) }}</span>
                                                                <span class="text-[11px] text-gray-500">ID: {{ item.producto_ident }}</span>
                                                            </div>
                                                            <div class="flex flex-wrap justify-between text-[11px] text-gray-600 gap-2">
                                                                <span>Sugerido: <strong class="text-gray-900">{{ item.suggested_order_qty }}</strong></span>
                                                                <span>Stock recomendado: {{ item.recommended_inventory }}</span>
                                                                <span>Inventario: {{ item.inventory_on_hand }}</span>
                                                                <span>Promedio diario: {{ item.avg_daily_sales.toFixed(2) }}</span>
                                                                <span>Cobertura: {{ item.days_of_cover !== null ? item.days_of_cover + ' días' : 'Sin datos' }}</span>
                                                                <span>
                                                                    <span v-if="item.restock_asap" class="mr-1 rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-semibold text-rose-700">ASAP</span>
                                                                    Reabastecer antes de {{ item.restock_by_date }}
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </li>
                                                </ul>
                                            </td>
                                            <td class="px-3 py-2 text-right align-top">
                                                <button type="button"
                                                    class="rounded border border-gray-300 px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                                                    :disabled="sendingProvider === group.provider_ident || !group.provider_email || !group.provider_ident"
                                                    @click="notifyProvider(group.provider_ident || '')">
                                                    <span v-if="sendingProvider === group.provider_ident">Enviando…</span>
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
