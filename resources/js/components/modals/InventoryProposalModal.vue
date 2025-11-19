<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { jsPDF } from 'jspdf'
import type { InventoryProposalResponse } from '../../api/reports'
import { notifyInventoryProposal } from '../../api/reports'

interface Props {
    open: boolean
    proposal: InventoryProposalResponse | null
    loading: boolean
    error: string
    horizonLabel: string
}

const props = defineProps<Props>()
const emit = defineEmits<{
    close: []
}>()

const items = computed(() => props.proposal?.items ?? [])
const generatedAt = computed(() => props.proposal?.generated_at ?? null)
const totalItems = computed(() => filteredItems.value.length)
const recommendedTotal = computed(() =>
    filteredItems.value.reduce((sum, item) => sum + item.recommended_inventory, 0)
)
const recommendedTotalLabel = computed(() =>
    recommendedTotal.value.toLocaleString('es-MX')
)
const exporting = ref(false)
const notifying = ref(false)
const search = ref('')
const selectedProvider = ref<'all' | string>('all')
const notifySuccess = ref('')
const notifyError = ref('')
const filteredItems = computed(() => {
    const term = search.value.trim().toLowerCase()
    if (!term) return items.value
    return items.value.filter((item) => {
        const product = `${item.producto_nombre ?? ''} ${item.producto_ident}`.toLowerCase()
        const provider = `${item.provider_name ?? ''} ${item.provider_ident ?? ''}`.toLowerCase()
        return product.includes(term) || provider.includes(term)
    })
})
const providerOptions = computed(() => {
    const map = new Map<string, string>()
    items.value.forEach((item) => {
        const ident = item.provider_ident ? String(item.provider_ident) : null
        if (!ident) return
        if (!map.has(ident)) {
            const label = item.provider_name ? `${item.provider_name} (${ident})` : `Proveedor ${ident}`
            map.set(ident, label)
        }
    })
    return Array.from(map.entries()).map(([value, label]) => ({ value, label }))
})

watch(
    () => props.open,
    (open) => {
        if (!open) {
            search.value = ''
            selectedProvider.value = 'all'
            notifySuccess.value = ''
            notifyError.value = ''
        }
    }
)

watch(providerOptions, (options) => {
    if (options.length === 0) {
        selectedProvider.value = 'all'
        return
    }
    if (
        selectedProvider.value !== 'all' &&
        !options.some((option) => option.value === selectedProvider.value)
    ) {
        selectedProvider.value = 'all'
    }
})

function formatNumber(value: number | null | undefined, digits = 2) {
    if (value === null || value === undefined) return '—'
    return Number(value).toFixed(digits)
}

async function exportPdf() {
    if (!props.proposal || exporting.value) return
    exporting.value = true
    try {
        const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'letter' })
        const marginX = 36
        let y = 48
        const pageWidth = doc.internal.pageSize.getWidth()
        const pageHeight = doc.internal.pageSize.getHeight()
        const horizonLabel = props.horizonLabel || props.proposal.horizon

        doc.setFontSize(16)
        doc.text(`Propuesta de inventario - ${horizonLabel}`, marginX, y)
        y += 18
        doc.setFontSize(10)
        doc.text(
            `Generada: ${generatedAt.value ?? 'pendiente'} · Productos: ${totalItems.value} · Stock total recomendado: ${recommendedTotalLabel.value}`,
            marginX,
            y
        )
        y += 24

        const columns: Array<{ label: string; width: number; getter: (item: InventoryProposalResponse['items'][number]) => string }> = [
            { label: 'Producto', width: 200, getter: (item) => `${item.producto_nombre ?? 'Producto ' + item.producto_ident} (ID: ${item.producto_ident})` },
            { label: 'Proveedor', width: 140, getter: (item) => item.provider_name ?? item.provider_ident ?? 'Sin proveedor' },
            { label: 'Promedio diario', width: 90, getter: (item) => formatNumber(item.avg_daily_sales) },
            { label: 'Stock recomendado', width: 110, getter: (item) => String(item.recommended_inventory) },
            { label: 'Inventario actual', width: 110, getter: (item) => item.inventory_on_hand !== null ? String(item.inventory_on_hand) : '—' },
            { label: 'Unidades totales', width: 110, getter: (item) => formatNumber(item.total_units) },
        ]

        const header = () => {
            doc.setFontSize(10)
            let x = marginX
            columns.forEach((col) => {
                doc.text(col.label, x, y)
                x += col.width
            })
            y += 8
            doc.setLineWidth(0.5)
            doc.line(marginX, y, pageWidth - marginX, y)
            y += 12
        }

        const addPageIfNeeded = () => {
            if (y > pageHeight - 40) {
                doc.addPage()
                y = 40
                doc.setFontSize(12)
                doc.text(`Propuesta de inventario - ${horizonLabel} (continuación)`, marginX, y)
                y += 20
                header()
            }
        }

        header()
        doc.setFontSize(9)

        filteredItems.value.forEach((item) => {
            addPageIfNeeded()
            let x = marginX
            columns.forEach((col) => {
                const text = col.getter(item)
                doc.text(text, x, y, { maxWidth: col.width - 4 })
                x += col.width
            })
            y += 12
        })

        const filename = `propuesta-${props.proposal.horizon}-${new Date().toISOString().slice(0, 10)}.pdf`
        doc.save(filename)
    } catch (err) {
        console.error('No se pudo exportar el PDF', err)
    } finally {
        exporting.value = false
    }
}

async function notifySelectedProviders() {
    if (!props.proposal || notifying.value) {
        return
    }
    notifying.value = true
    notifySuccess.value = ''
    notifyError.value = ''
    try {
        const providers =
            selectedProvider.value === 'all' ? undefined : [selectedProvider.value]
        const response = await notifyInventoryProposal({
            horizon: props.proposal.horizon,
            providers,
        })
        notifySuccess.value = response.message ?? 'Notificaciones enviadas.'
    } catch (err: any) {
        notifyError.value =
            err?.response?.data?.message || err?.message || 'No se pudieron enviar las notificaciones.'
    } finally {
        notifying.value = false
    }
}

</script>

<template>
    <teleport to="body">
        <transition name="fade">
            <div v-if="open" class="fixed inset-0 z-40 flex items-center justify-center bg-black/30 px-4">
                <div class="max-h-[90vh] w-full max-w-5xl overflow-hidden rounded-2xl bg-white shadow-xl">
                    <header class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                        <div>
                            <p class="text-base font-semibold text-gray-900">Propuesta de inventario · {{ horizonLabel }}</p>
                            <p class="text-xs text-gray-500">
                                <span v-if="generatedAt">Generada el {{ generatedAt }}</span>
                                <span v-else>En proceso…</span>
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button"
                                class="rounded border border-gray-300 px-3 py-1 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                                :disabled="!proposal || loading || exporting"
                                @click="exportPdf">
                                <span v-if="exporting">Exportando…</span>
                                <span v-else>Exportar PDF</span>
                            </button>
                            <button type="button" class="rounded border border-gray-300 px-3 py-1 text-sm text-gray-700 hover:bg-gray-50"
                                @click="emit('close')">Cerrar</button>
                        </div>
                    </header>
                    <div class="px-6 py-4 text-sm">
                        <div v-if="loading" class="text-gray-500">Calculando propuesta…</div>
                        <div v-else-if="error" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-rose-700">{{ error }}</div>
                        <div v-else-if="!proposal" class="text-gray-500">No hay datos para mostrar.</div>
                        <div v-else>
                            <p class="mb-3 text-[11px] text-gray-500">
                                <strong>Stock recomendado</strong> es la meta sugerida con base en ventas históricas y el horizonte elegido; <strong>Inventario actual</strong> refleja lo que hay hoy en almacén (si aplica); <strong>Unidades totales</strong> indica cuánto se vendió durante el periodo analizado.
                            </p>
                            <div class="mb-3 grid gap-4 text-xs text-gray-600 sm:grid-cols-3">
                                <div class="flex flex-col text-[11px] text-gray-700">
                                    <label for="inventory-proposal-search" class="font-semibold text-gray-900">Buscar producto / proveedor</label>
                                    <input
                                        id="inventory-proposal-search"
                                        type="text"
                                        v-model="search"
                                        placeholder="Escribe nombre o ID…"
                                        class="mt-1 w-64 rounded border border-gray-300 px-2 py-1 text-xs focus:border-emerald-600 focus:ring-emerald-600"
                                    >
                                </div>
                                <div class="flex flex-col text-[11px] text-gray-700">
                                    <label class="font-semibold text-gray-900" for="notify-provider-select">Enviar por correo</label>
                                    <div class="mt-1 flex flex-wrap items-center gap-2">
                                        <select
                                            id="notify-provider-select"
                                            v-model="selectedProvider"
                                            class="rounded border border-gray-300 px-2 py-1 text-xs focus:border-emerald-600 focus:ring-emerald-600"
                                            :disabled="notifying || providerOptions.length === 0"
                                        >
                                            <option value="all">Todos los proveedores</option>
                                            <option v-for="option in providerOptions" :key="option.value" :value="option.value">
                                                {{ option.label }}
                                            </option>
                                        </select>
                                        <button
                                            type="button"
                                            class="inline-flex items-center rounded border border-emerald-600 px-3 py-1.5 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-50 disabled:opacity-60"
                                            :disabled="notifying || (!providerOptions.length && selectedProvider !== 'all')"
                                            @click="notifySelectedProviders"
                                        >
                                            <span v-if="notifying">Enviando…</span>
                                            <span v-else>Notificar</span>
                                        </button>
                                    </div>
                                </div>
                                <div class="flex flex-col text-[11px] text-gray-700">
                                    <p class="mt-1 text-[11px] text-gray-500">
                                        Se enviará un correo con el stock recomendado a todos los proveedores o al seleccionado.
                                    </p>
                                </div>
                            </div>
                            <div v-if="notifySuccess" class="mb-3 rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-[11px] text-emerald-700">
                                {{ notifySuccess }}
                            </div>
                            <div v-if="notifyError" class="mb-3 rounded border border-rose-200 bg-rose-50 px-3 py-2 text-[11px] text-rose-700">
                                {{ notifyError }}
                            </div>
                            <div class="mb-3 flex flex-wrap items-center gap-4 text-xs text-gray-600">
                                <span>Productos: <strong class="text-gray-900">{{ totalItems }}</strong></span>
                                <span>Stock total recomendado:
                                    <strong class="text-gray-900">{{ recommendedTotalLabel }}</strong>
                                </span>
                                <span>Lookback: {{ proposal.lookback_days }} días</span>
                                <span>Horizonte: {{ proposal.lead_time_days }} días</span>
                            </div>
                            <div class="max-h-[60vh] overflow-auto">
                                <table class="min-w-full divide-y divide-gray-200 text-left text-xs">
                                    <thead class="sticky top-0 z-10 bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
                                        <tr>
                                            <th class="px-3 py-2">Producto</th>
                                            <th class="px-3 py-2">Proveedor</th>
                                            <th class="px-3 py-2 text-right">Promedio diario</th>
                                            <th class="px-3 py-2 text-right">Stock recomendado</th>
                                            <th class="px-3 py-2 text-right">Inventario actual</th>
                                            <th class="px-3 py-2 text-right">Unidades totales</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <tr v-for="item in filteredItems" :key="item.producto_ident" class="align-top">
                                            <td class="px-3 py-2">
                                                <p class="font-semibold text-gray-900">{{ item.producto_nombre ?? ('Producto ' + item.producto_ident) }}</p>
                                                <p class="text-[11px] text-gray-500">ID: {{ item.producto_ident }}</p>
                                            </td>
                                            <td class="px-3 py-2 text-gray-700">
                                                {{ item.provider_name ?? item.provider_ident ?? 'Sin proveedor' }}
                                            </td>
                                            <td class="px-3 py-2 text-right text-gray-700">{{ item.avg_daily_sales.toFixed(2) }}</td>
                                            <td class="px-3 py-2 text-right font-semibold text-gray-900">{{ item.recommended_inventory }}</td>
                                            <td class="px-3 py-2 text-right text-gray-700">
                                                {{ item.inventory_on_hand !== null ? item.inventory_on_hand : '—' }}
                                            </td>
                                            <td class="px-3 py-2 text-right text-gray-700">{{ item.total_units.toFixed(2) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
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
