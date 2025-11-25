<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import type { DaySale } from '../../api/adminSales';
import { listSalesByDate, cancelSale } from '../../api/adminSales';

const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ (e: 'close'): void }>();

const form = reactive({
    date: new Date().toISOString().slice(0, 10),
    adminPassword: '',
});

const sales = ref<DaySale[]>([]);
const loading = ref(false);
const error = ref('');
const cancelingId = ref<number | null>(null);
const expanded = ref<Record<number, boolean>>({});

const canQuery = computed(() => !!form.date && form.adminPassword.trim().length >= 4);

watch(
    () => props.open,
    (open) => {
        if (open) {
            form.date = new Date().toISOString().slice(0, 10);
            sales.value = [];
            error.value = '';
            cancelingId.value = null;
            expanded.value = {};
        }
    }
);

function close() {
    emit('close');
}

async function fetchSales() {
    if (!canQuery.value) return;
    loading.value = true;
    error.value = '';
    try {
        const data = await listSalesByDate({
            date: form.date,
            admin_password: form.adminPassword,
        });
        sales.value = data.sales;
        expanded.value = {};
    } catch (err: any) {
        error.value = err?.response?.data?.message || 'No se pudieron obtener las ventas.';
        sales.value = [];
    } finally {
        loading.value = false;
    }
}

function toggleExpanded(id: number) {
    expanded.value = { ...expanded.value, [id]: !expanded.value[id] };
}

async function handleCancel(sale: DaySale) {
    if (cancelingId.value) return;
    if (!window.confirm(`¿Cancelar la venta ${sale.idventa ?? sale.id}?`)) return;
    const reason = window.prompt('Motivo de la cancelación (opcional):', '') || undefined;
    cancelingId.value = sale.id;
    try {
        await cancelSale(sale.id, {
            admin_password: form.adminPassword,
            reason,
        });
        sales.value = sales.value.filter((s) => s.id !== sale.id);
    } catch (err: any) {
        window.alert(err?.response?.data?.message || 'No se pudo cancelar la venta.');
    } finally {
        cancelingId.value = null;
    }
}
</script>

<template>
    <transition name="fade">
        <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40" @click="close"></div>
            <div
                class="relative z-10 w-full max-w-5xl max-h-[90vh] rounded-2xl bg-white shadow-2xl flex flex-col overflow-hidden"
            >
                <header class="border-b border-gray-200 px-5 py-4 flex items-center justify-between">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Herramientas administrativas</p>
                        <h2 class="text-lg font-semibold text-gray-900">Cancelar ventas</h2>
                    </div>
                    <button
                        class="inline-flex items-center rounded border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50"
                        @click="close"
                    >
                        Cerrar
                    </button>
                </header>

                <div class="flex-1 overflow-y-auto px-5 py-4 space-y-4">
                    <div class="grid gap-3 md:grid-cols-3">
                        <label class="flex flex-col text-sm text-gray-700">
                            <span class="font-semibold text-gray-800">Fecha</span>
                            <input
                                v-model="form.date"
                                type="date"
                                class="mt-1 rounded border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                            />
                        </label>
                        <label class="flex flex-col text-sm text-gray-700 md:col-span-2">
                            <span class="font-semibold text-gray-800">Contraseña de administrador</span>
                            <input
                                v-model="form.adminPassword"
                                type="password"
                                class="mt-1 rounded border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                                placeholder="Ingresa tu contraseña para desbloquear"
                            />
                        </label>
                    </div>
                    <div class="flex items-center gap-3 text-sm">
                        <button
                            type="button"
                            class="inline-flex items-center rounded bg-gray-900 px-4 py-2 font-semibold text-white transition disabled:opacity-50"
                            :disabled="!canQuery || loading"
                            @click="fetchSales"
                        >
                            {{ loading ? 'Consultando…' : 'Consultar ventas' }}
                        </button>
                        <span v-if="error" class="text-sm text-rose-600">{{ error }}</span>
                        <span v-else-if="sales.length" class="text-xs text-gray-500">
                            {{ sales.length }} ventas encontradas
                        </span>
                    </div>

                    <div v-if="loading" class="text-sm text-gray-500">Cargando ventas…</div>
                    <div v-else-if="!sales.length" class="text-sm text-gray-500">
                        Selecciona fecha y contraseña para ver ventas.
                    </div>

                    <div v-else class="space-y-3">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-3 py-2 text-left">Hora</th>
                                    <th class="px-3 py-2 text-left">Ticket</th>
                                    <th class="px-3 py-2 text-right">Total</th>
                                    <th class="px-3 py-2 text-left">Método</th>
                                    <th class="px-3 py-2 text-left">Vendedor</th>
                                    <th class="px-3 py-2 text-left">Productos</th>
                                    <th class="px-3 py-2 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <template v-for="sale in sales" :key="sale.id">
                                    <tr>
                                        <td class="px-3 py-2 text-left whitespace-nowrap">
                                            {{ sale.hora ?? '--' }}
                                        </td>
                                    <td class="px-3 py-2">{{ sale.idventa ?? sale.id }}</td>
                                    <td class="px-3 py-2 text-right">{{ sale.total.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) }}</td>
                                    <td class="px-3 py-2 capitalize">{{ sale.metodo ?? '—' }}</td>
                                    <td class="px-3 py-2">{{ sale.vendedor ?? '—' }}</td>
                                    <td class="px-3 py-2">
                                        {{ sale.line_items.length }}
                                        <button
                                            type="button"
                                            class="ml-2 text-xs text-gray-500 underline"
                                            @click="toggleExpanded(sale.id)"
                                        >
                                            {{ expanded[sale.id] ? 'Ocultar' : 'Ver' }} detalle
                                        </button>
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <button
                                            type="button"
                                            class="rounded border border-rose-200 bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-100 disabled:opacity-50"
                                            :disabled="!!cancelingId && cancelingId !== sale.id"
                                            @click="handleCancel(sale)"
                                        >
                                            {{ cancelingId === sale.id ? 'Cancelando…' : 'Cancelar venta' }}
                                        </button>
                                    </td>
                                    </tr>
                                    <tr v-if="expanded[sale.id]" :key="`detail-${sale.id}`" class="bg-gray-50">
                                        <td colspan="7" class="px-4 py-3">
                                            <div class="space-y-2 text-xs text-gray-600">
                                                <p class="font-semibold text-gray-800">Productos vendidos</p>
                                                <div class="space-y-1">
                                                    <div
                                                        v-for="line in sale.line_items"
                                                        :key="line.id"
                                                        class="flex flex-wrap items-center justify-between rounded border border-gray-200 bg-white px-3 py-2 text-[12px]"
                                                    >
                                                        <div class="flex-1">
                                                            <p class="font-medium text-gray-800">{{ line.producto_nombre ?? 'Producto sin nombre' }}</p>
                                                            <p class="text-[11px] text-gray-500">Ident: {{ line.producto_ident ?? '—' }}</p>
                                                        </div>
                                                        <div class="text-right">
                                                            <p>Cant. {{ line.cantidad }}</p>
                                                            <p>Total {{ line.public_total.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) }}</p>
                                                            <p>P Uni {{ line.unit_price.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) }}</p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </transition>
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
