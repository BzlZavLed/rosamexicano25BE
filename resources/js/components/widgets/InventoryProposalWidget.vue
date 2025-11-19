<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import type {
    InventoryProposalResponse,
    InventoryProposalSummary,
    RestockHorizon,
} from '../../api/reports'
import {
    generateInventoryProposal,
    getInventoryProposal,
    listInventoryProposals,
} from '../../api/reports'
import InventoryProposalModal from '../modals/InventoryProposalModal.vue'

type Horizon = RestockHorizon

const horizonOptions: Array<{ value: Horizon; label: string }> = [
    { value: '2w', label: '2 semanas' },
    { value: '4w', label: '4 semanas' },
    { value: '6w', label: '6 semanas' },
]

const selected = ref<Horizon>('2w')
const proposals = ref<InventoryProposalSummary[]>([])
const loading = ref(false)
const generating = ref(false)
const error = ref('')
const modalOpen = ref(false)
const modalLoading = ref(false)
const modalError = ref('')
const modalData = ref<InventoryProposalResponse | null>(null)
const modalHorizon = ref<Horizon | null>(null)
const lookbackDays = ref(90)

const proposalMap = computed(() => {
    const base: Record<Horizon, InventoryProposalSummary | undefined> = {
        '2w': undefined,
        '4w': undefined,
        '6w': undefined,
    }
    proposals.value.forEach((item) => {
        base[item.horizon] = item
    })
    return base
})

const modalHorizonLabel = computed(() => {
    const horizon = modalHorizon.value
    if (!horizon) return ''
    return horizonOptions.find((opt) => opt.value === horizon)?.label ?? horizon
})

async function loadProposals() {
    loading.value = true
    error.value = ''
    try {
        proposals.value = await listInventoryProposals()
    } catch (err: any) {
        error.value = err?.response?.data?.message || err?.message || 'No se pudieron cargar las propuestas.'
    } finally {
        loading.value = false
    }
}

async function openProposal(horizon: Horizon) {
    modalOpen.value = true
    modalLoading.value = true
    modalError.value = ''
    modalData.value = null
    modalHorizon.value = horizon
    try {
        modalData.value = await getInventoryProposal(horizon)
    } catch (err: any) {
        modalError.value = err?.response?.data?.message || err?.message || 'No se pudo cargar la propuesta.'
    } finally {
        modalLoading.value = false
    }
}

async function handleGenerate() {
    generating.value = true
    error.value = ''
    modalError.value = ''
    try {
        const days = Math.max(30, Math.min(365, lookbackDays.value || 90))
        lookbackDays.value = days
        const data = await generateInventoryProposal({ horizon: selected.value, lookback_days: days })
        modalData.value = data
        modalHorizon.value = data.horizon
        modalOpen.value = true
        modalLoading.value = false
        await loadProposals()
    } catch (err: any) {
        error.value = err?.response?.data?.message || err?.message || 'No se pudo generar la propuesta.'
    } finally {
        generating.value = false
    }
}

function closeModal() {
    modalOpen.value = false
}

onMounted(() => {
    loadProposals()
})
</script>

<template>
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="text-sm font-semibold text-gray-900">Propuesta de inventario</p>
               
            </div>
            
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-3 text-xs text-gray-600">
        <label class="flex items-center gap-2">
                <span>Horizonte</span>
                <select
                    v-model="selected"
                    class="rounded border border-gray-300 px-2 py-1 text-xs focus:border-gray-900 focus:ring-gray-900"
                    :disabled="generating"
                >
                    <option v-for="opt in horizonOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
            </label>
        <label class="flex items-center gap-2">
                <span>Días a analizar</span>
                <input
                    type="number"
                    min="30"
                    max="365"
                    step="5"
                    v-model.number="lookbackDays"
                    class="w-24 rounded border border-gray-300 px-2 py-1 text-xs focus:border-emerald-600 focus:ring-emerald-600"
                >
            </label>
            <button
                type="button"
                class="inline-flex items-center rounded bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
                :disabled="generating"
                @click="handleGenerate"
            >
                <span v-if="generating">Calculando…</span>
                <span v-else>Generar propuesta</span>
            </button>
            
            <span class="text-[11px] text-gray-500">Se recalculará y guardará la propuesta para este horizonte con el lookback seleccionado.</span>
        </div>

        <div v-if="error" class="mt-3 rounded border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
            {{ error }}
        </div>

        <div class="mt-4">
            <p class="text-xs font-semibold text-gray-600 mb-2">Propuestas guardadas</p>
            <div v-if="loading" class="text-xs text-gray-500">Cargando…</div>
            <div v-else>
                <ul class="space-y-2 text-xs text-gray-600">
                    <li v-for="opt in horizonOptions" :key="opt.value"
                        class="flex items-center justify-between rounded border border-gray-100 px-3 py-2">
                        <div>
                            <p class="font-semibold text-gray-900">{{ opt.label }}</p>
                            <p class="text-[11px] text-gray-500">
                                <span v-if="proposalMap[opt.value]?.generated_at">
                                    Último cálculo: {{ proposalMap[opt.value]?.generated_at }}
                                </span>
                                <span v-else>Pendiente</span>
                            </p>
                        </div>
                        <button
                            type="button"
                            class="text-emerald-700 hover:text-emerald-800 disabled:text-gray-400 text-[11px] font-semibold"
                            :disabled="!proposalMap[opt.value]"
                            @click="openProposal(opt.value)"
                        >
                            Ver detalles →
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </div>
    <InventoryProposalModal
        :open="modalOpen"
        :proposal="modalData"
        :loading="modalLoading"
        :error="modalError"
        :horizon-label="modalHorizonLabel"
        @close="closeModal"
    />
</template>
