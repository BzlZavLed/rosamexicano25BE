<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { getCashierSummary, type CashierSummaryResponse } from '../../api/widgets';

const loading = ref(false);
const error = ref('');
const summary = ref<CashierSummaryResponse | null>(null);

const todayISO = () => new Date().toISOString().slice(0, 10);
const fechaFiltro = ref<string>(todayISO());

const formatCurrency = (value: number, locale = 'es-MX', currency = 'MXN') => {
    try {
        return new Intl.NumberFormat(locale, { style: 'currency', currency }).format(value);
    } catch {
        return `$${value.toFixed(2)}`;
    }
};

const hasData = computed(() => Boolean(summary.value));

async function loadSummary() {
    loading.value = true;
    error.value = '';
    try {
        const data = await getCashierSummary(fechaFiltro.value || undefined);
        summary.value = data;
    } catch (err: any) {
        error.value = err?.response?.data?.message || err?.message || 'No se pudo cargar el resumen de caja.';
        summary.value = null;
    } finally {
        loading.value = false;
    }
}

function onFechaChange() {
    loadSummary();
}

onMounted(loadSummary);
</script>

<template>
    <div class="sm:col-span-2 xl:col-span-2 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="space-y-1">
                <h3 class="text-base font-semibold text-gray-900">Resumen de caja</h3>
                <p class="text-sm text-gray-500">Entradas, salidas y métodos de pago.</p>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3 w-full sm:w-auto">
                <input type="date" v-model="fechaFiltro" @change="onFechaChange"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900 sm:w-40"
                    aria-label="Fecha de resumen" />
            </div>
        </div>

        <div class="mt-5 space-y-3">
            <div v-if="loading" class="rounded-xl border border-gray-100 bg-gray-50 px-4 py-5 text-sm text-gray-600">
                Cargando resumen…
            </div>

            <div v-else-if="error" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-5 text-sm text-rose-700">
                {{ error }}
            </div>

            <div v-else-if="hasData && summary" class="grid gap-3 sm:gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <!-- Fecha -->
                <div class="rounded-xl border border-gray-100 bg-gray-50 p-4">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Fecha</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900">
                        {{ summary.fecha }}
                    </p>
                </div>

                <!-- Entradas -->
                <div class="rounded-xl border border-gray-100 bg-gray-50 p-4">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Entradas</p>
                    <p class="mt-1 text-sm font-semibold text-emerald-700">
                        {{ formatCurrency(summary.entradas_total) }}
                    </p>
                    <p class="text-xs text-gray-500">
                        Transacciones: {{ summary.transacciones?.entradas ?? 0 }}
                    </p>
                </div>

                <!-- Salidas -->
                <div class="rounded-xl border border-gray-100 bg-gray-50 p-4">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Salidas</p>
                    <p class="mt-1 text-sm font-semibold text-rose-600">
                        {{ formatCurrency(summary.salidas_total) }}
                    </p>
                    <p class="text-xs text-gray-500">
                        Transacciones: {{ summary.transacciones?.salidas ?? 0 }}
                    </p>
                </div>

                <!-- Productos vendidos -->
                <div class="rounded-xl border border-gray-100 bg-gray-50 p-4">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Productos vendidos</p>
                    <p class="mt-1 text-lg font-semibold text-gray-900">
                        {{ summary.productos_vendidos }}
                    </p>
                </div>

                <!-- Métodos de pago -->
                <div class="rounded-xl border border-gray-100 bg-gray-50 p-4 sm:col-span-2">
                    <p class="text-xs uppercase tracking-wide text-gray-500">
                        Métodos de pago
                    </p>
                    <div
                        class="mt-3 grid grid-cols-3 gap-2 text-xs text-gray-600"
                        v-if="(summary.metodos || []).length"
                    >
                        <div
                            v-for="m in summary.metodos"
                            :key="m.metodo"
                            class="flex flex-col gap-1 rounded-lg border border-gray-200 bg-white/70 p-3"
                        >
                            <p class="truncate text-xs font-semibold uppercase tracking-wide text-gray-500">
                                {{ m.metodo }}
                            </p>
                            <p class="text-sm font-semibold text-gray-900 break-words">
                                {{ formatCurrency(m.total) }}
                            </p>
                            <p class="text-[11px] text-gray-500 break-words">
                                {{ m.transacciones ?? 0 }} transacciones
                            </p>
                        </div>
                    </div>
                    <p v-else class="mt-2 text-xs text-gray-500">
                        Sin detalle de métodos de pago.
                    </p>
                </div>

                <!-- Saldo inicial (opcional si a veces viene) -->
                <div v-if="summary.saldo_inicial != null" class="rounded-xl border border-gray-100 bg-gray-50 p-4">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Saldo inicial</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900">
                        {{ formatCurrency(summary.saldo_inicial) }}
                    </p>
                </div>
            </div>

            <div v-else class="rounded-xl border border-gray-100 bg-gray-50 px-4 py-5 text-sm text-gray-500">
                Sin datos para la fecha seleccionada.
            </div>
        </div>
    </div>
</template>
