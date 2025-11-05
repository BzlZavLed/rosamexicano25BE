<!-- src/components/widgets/MailerQuotaWidget.vue -->
<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { listMailerTrack, type MailerTrackItem } from '../../api/dashboard'

const loading = ref(true)
const errorMsg = ref<string | null>(null)
const items = ref<MailerTrackItem[]>([])
const selectedLabel = ref<string>('')

const MONTH_LIMIT = 3000

const fetchData = async () => {
    loading.value = true
    errorMsg.value = null
    try {
        const res = await listMailerTrack() // e.g. { limit: 24 }
        items.value = res.items ?? []

        // Pick current month if present; else most recent (API is desc by default)
        const now = new Date()
        const y = now.getFullYear()
        const m = now.getMonth() + 1
        const current = items.value.find(i => i.year === y && i.month === m)
        if (current) {
            selectedLabel.value = current.label
        } else if (items.value.length) {
            selectedLabel.value = items.value[0]?.label ?? ''
        } else {
            selectedLabel.value = ''
        }
    } catch (e: any) {
        errorMsg.value = e?.response?.data?.message || 'No se pudo cargar el historial de correos.'
    } finally {
        loading.value = false
    }
}

onMounted(fetchData)

const selectedItem = computed<MailerTrackItem | null>(() => {
    if (!selectedLabel.value) return null
    return items.value.find(i => i.label === selectedLabel.value) ?? null
})

const used = computed<number>(() => Math.max(0, selectedItem.value?.sent_count ?? 0))
const remaining = computed<number>(() => Math.max(0, MONTH_LIMIT - used.value))
const usedPct = computed<number>(() => {
    if (!MONTH_LIMIT) return 0
    const pct = (used.value / MONTH_LIMIT) * 100
    return Math.min(100, Math.max(0, Math.round(pct)))
})
</script>

<template>
    <div class="rounded-2xl border border-gray-200 p-4 shadow-sm bg-white">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-base font-semibold">Límite de correos por mes</h2>

            <div class="flex items-center gap-2">
                <label class="text-sm text-gray-500">Mes</label>
                <select v-model="selectedLabel"
                    class="rounded-lg border-gray-300 text-sm px-2 py-1 focus:outline-none focus:ring focus:ring-indigo-200"
                    :disabled="loading || !items.length">
                    <option v-for="it in items" :key="it.label" :value="it.label">
                        {{ it.label }}
                    </option>
                </select>
            </div>
        </div>

        <div v-if="loading" class="animate-pulse">
            <div class="h-5 w-40 bg-gray-200 rounded mb-2"></div>
            <div class="h-3 w-full bg-gray-200 rounded"></div>
        </div>

        <div v-else-if="errorMsg" class="text-sm text-red-600">
            {{ errorMsg }}
        </div>

        <div v-else-if="!selectedItem" class="text-sm text-gray-500">
            No hay datos disponibles.
        </div>

        <div v-else class="space-y-3">
            <div class="flex flex-wrap items-baseline gap-x-4">
                <div class="text-3xl font-bold tabular-nums">
                    {{ remaining.toLocaleString() }}
                </div>
                <div class="text-sm text-gray-500">
                    restantes de {{ MONTH_LIMIT.toLocaleString() }} en {{ selectedItem.label }}
                </div>
            </div>

            <div class="w-full h-3 bg-gray-100 rounded-xl overflow-hidden">
                <div class="h-3 rounded-xl" :style="{ width: usedPct + '%', backgroundColor: 'rgb(79 70 229)' }"
                    :aria-valuenow="usedPct" aria-valuemin="0" aria-valuemax="100" role="progressbar" />
            </div>

            <div class="flex justify-between text-xs text-gray-500 tabular-nums">
                <span>Usados: {{ used.toLocaleString() }}</span>
                <span>{{ usedPct }}%</span>
            </div>
        </div>
    </div>
</template>
