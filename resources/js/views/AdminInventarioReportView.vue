<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import AppLayout from '../components/layout/AppLayout.vue';
import {
    getInventarioReport,
    type InventarioReportResponse,
    type InventarioRow,
    type ProductosPagination,
} from '../api/reports';

type SortDirection = 'asc' | 'desc';
type InventarioSort = 'producto' | 'existencia' | 'proveedor';

const inventarioItems = ref<InventarioRow[]>([]);
const inventarioPagination = ref<ProductosPagination | null>(null);
const inventarioLoading = ref(false);
const inventarioError = ref('');
const inventarioSearch = ref('');
const inventarioPage = ref(1);
const inventarioPerPage = ref(50);
const inventarioSort = ref<InventarioSort>('producto');
const inventarioDirection = ref<SortDirection>('asc');
const providerTipoFilter = ref<'all' | 'normal' | 'consigna' | 'porcentaje'>('all');

const tableClasses = {
    wrapper: 'overflow-x-auto rounded-xl border border-gray-200 shadow-sm',
    table: 'min-w-full divide-y divide-gray-200 text-left text-xs',
    head: 'bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500',
    body: 'divide-y divide-gray-100 bg-white text-sm',
    row: 'hover:bg-gray-50 transition',
    emptyRow: 'px-3 py-6 text-center text-gray-500',
};

const providerTypeLabels: Record<string, string> = {
    normal: 'Normal',
    consigna: 'Consigna',
    porcentaje: 'Por porcentaje',
};

const providerTypeClasses: Record<string, string> = {
    normal: 'bg-blue-50 text-blue-700 border-blue-100',
    consigna: 'bg-amber-50 text-amber-700 border-amber-100',
    porcentaje: 'bg-emerald-50 text-emerald-700 border-emerald-100',
};

function getProviderTypeLabel(tipo?: string | null) {
    if (!tipo) return 'Normal';
    return providerTypeLabels[tipo] ?? tipo;
}

function getProviderTypeClass(tipo?: string | null) {
    if (!tipo) return providerTypeClasses.normal;
    return providerTypeClasses[tipo] ?? providerTypeClasses.normal;
}

function formatCurrency(value: number | string | null | undefined): string {
    const num = typeof value === 'string' ? Number(value) : value;
    if (!Number.isFinite(num)) return '--';
    return Number(num).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' });
}

async function loadInventario() {
    inventarioLoading.value = true;
    inventarioError.value = '';
    try {
        const response: InventarioReportResponse = await getInventarioReport({
            q: inventarioSearch.value.trim() || undefined,
            page: inventarioPage.value,
            per_page: inventarioPerPage.value,
            sort: inventarioSort.value,
            direction: inventarioDirection.value,
            provider_tipo: providerTipoFilter.value !== 'all' ? providerTipoFilter.value : undefined,
        });
        inventarioItems.value = response.data;
        inventarioPagination.value = response.pagination;
        if (response.totals) {
            inventarioTotals.value = {
                total_productos: response.totals.total_productos ?? (response.pagination?.total ?? inventarioItems.value.length),
                total_existencia: response.totals.total_existencia ?? 0,
                valor_publico: response.totals.valor_publico ?? 0,
                valor_proveedor: response.totals.valor_proveedor ?? 0,
            };
        } else {
            inventarioTotals.value = computeLocalTotals(inventarioItems.value);
        }
    } catch (err: any) {
        inventarioError.value = err?.response?.data?.message || err?.message || 'No se pudo cargar el inventario.';
    } finally {
        inventarioLoading.value = false;
    }
}

function submitSearch() {
    inventarioPage.value = 1;
    loadInventario();
}

function toggleInventarioSort(column: InventarioSort) {
    if (inventarioSort.value === column) {
        inventarioDirection.value = inventarioDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        inventarioSort.value = column;
        inventarioDirection.value = 'asc';
    }
    inventarioPage.value = 1;
    loadInventario();
}

function goToFirstPage() {
    if (!inventarioPagination.value) return;
    if (inventarioPagination.value.current_page > 1) {
        inventarioPage.value = 1;
        loadInventario();
    }
}

function goToPrevPage() {
    if (!inventarioPagination.value) return;
    if (inventarioPagination.value.prev_page_url) {
        inventarioPage.value = inventarioPagination.value.current_page - 1;
        loadInventario();
    }
}

function goToNextPage() {
    if (!inventarioPagination.value) return;
    if (inventarioPagination.value.next_page_url) {
        inventarioPage.value = inventarioPagination.value.current_page + 1;
        loadInventario();
    }
}

function goToLastPage() {
    if (!inventarioPagination.value) return;
    if (inventarioPagination.value.current_page < inventarioPagination.value.last_page) {
        inventarioPage.value = inventarioPagination.value.last_page;
        loadInventario();
    }
}

watch(inventarioPerPage, () => {
    inventarioPage.value = 1;
    loadInventario();
});

watch(providerTipoFilter, () => {
    inventarioPage.value = 1;
    loadInventario();
});

onMounted(() => {
    loadInventario();
});

const inventarioTotals = ref({
    total_productos: 0,
    total_existencia: 0,
    valor_publico: 0,
    valor_proveedor: 0,
});

function computeLocalTotals(items: InventarioRow[]) {
    const summary = {
        total_productos: items.length,
        total_existencia: 0,
        valor_publico: 0,
        valor_proveedor: 0,
    };
    for (const item of items) {
        const existencia = Number(item.existencia ?? 0);
        summary.total_existencia += existencia;
        summary.valor_publico += Number(item.costo_inventario ?? 0);
        if (item.precio_proveedor !== null) {
            summary.valor_proveedor += Number(item.precio_proveedor ?? 0) * existencia;
        }
    }
    summary.valor_publico = Math.round(summary.valor_publico * 100) / 100;
    summary.valor_proveedor = Math.round(summary.valor_proveedor * 100) / 100;
    return summary;
}
</script>

<template>
    <AppLayout>
        <div class="space-y-6 p-6">
            <header class="space-y-1">
                <p class="text-xs uppercase tracking-wide text-gray-500">Inventario</p>
                <h1 class="text-xl font-semibold text-gray-900">Reporte de inventario</h1>
                <p class="text-sm text-gray-500">Consulta existencias, costos y proveedores desde una vista dedicada.</p>
            </header>

            <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <form class="flex items-center gap-2" @submit.prevent="submitSearch">
                        <label class="text-xs font-semibold text-gray-600" for="inventario-search">Buscar</label>
                        <input
                            id="inventario-search"
                            v-model="inventarioSearch"
                            type="text"
                            placeholder="Producto, proveedor, ident..."
                            class="w-60 rounded border border-gray-300 px-3 py-1.5 text-sm focus:border-gray-900 focus:ring-gray-900"
                        />
                        <button type="submit" class="rounded bg-gray-900 px-3 py-1.5 text-xs font-semibold text-white">
                            Buscar
                        </button>
                    </form>
                    <label class="flex items-center gap-2 text-xs text-gray-600">
                        <span>Por página</span>
                        <select
                            v-model.number="inventarioPerPage"
                            class="rounded border border-gray-300 px-2 py-1 text-xs focus:border-gray-900 focus:ring-gray-900"
                        >
                            <option :value="25">25</option>
                            <option :value="50">50</option>
                            <option :value="100">100</option>
                            <option :value="200">200</option>
                        </select>
                    </label>
                    <label class="flex items-center gap-2 text-xs text-gray-600">
                        <span>Tipo proveedor</span>
                        <select
                            v-model="providerTipoFilter"
                            class="rounded border border-gray-300 px-2 py-1 text-xs focus:border-gray-900 focus:ring-gray-900"
                        >
                            <option value="all">Todos</option>
                            <option value="normal">Normal</option>
                            <option value="consigna">Consigna</option>
                            <option value="porcentaje">Por porcentaje</option>
                        </select>
                    </label>
                </div>

                <div v-if="inventarioError" class="mt-4 rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                    {{ inventarioError }}
                </div>
                <div v-else-if="inventarioLoading" class="mt-4 text-sm text-gray-500">Cargando inventario…</div>

                <div v-else class="mt-4">
                    <div :class="tableClasses.wrapper">
                        <table :class="tableClasses.table">
                            <thead :class="tableClasses.head">
                                <tr>
                                    <th class="px-3 py-2">Inventario ID</th>
                                    <th class="px-3 py-2">Ident</th>
                                    <th
                                        class="px-3 py-2 cursor-pointer select-none"
                                        @click="toggleInventarioSort('producto')"
                                    >
                                        Producto
                                        <span v-if="inventarioSort === 'producto'" class="ml-1">
                                            {{ inventarioDirection === 'asc' ? '▲' : '▼' }}
                                        </span>
                                    </th>
                                    <th class="px-3 py-2 text-right">Precio</th>
                                    <th class="px-3 py-2 text-right">Precio proveedor</th>
                                    <th
                                        class="px-3 py-2 text-right cursor-pointer select-none"
                                        @click="toggleInventarioSort('existencia')"
                                    >
                                        Existencia
                                        <span v-if="inventarioSort === 'existencia'" class="ml-1">
                                            {{ inventarioDirection === 'asc' ? '▲' : '▼' }}
                                        </span>
                                    </th>
                                    <th class="px-3 py-2 text-right">Costo total</th>
                                    <th
                                        class="px-3 py-2 cursor-pointer select-none"
                                        @click="toggleInventarioSort('proveedor')"
                                    >
                                        Proveedor
                                        <span v-if="inventarioSort === 'proveedor'" class="ml-1">
                                            {{ inventarioDirection === 'asc' ? '▲' : '▼' }}
                                        </span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody :class="tableClasses.body">
                                <tr v-for="item in inventarioItems" :key="item.inventario_id" :class="tableClasses.row">
                                    <td class="px-3 py-2 text-gray-900">{{ item.inventario_id }}</td>
                                    <td class="px-3 py-2">{{ item.producto_ident }}</td>
                                    <td class="px-3 py-2">{{ item.producto_nombre }}</td>
                                    <td class="px-3 py-2 text-right">
                                        <span v-if="item.precio !== null">
                                            {{ formatCurrency(item.precio) }}
                                        </span>
                                        <span v-else>—</span>
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <span v-if="item.precio_proveedor !== null">
                                            {{ formatCurrency(item.precio_proveedor) }}
                                        </span>
                                        <span v-else>—</span>
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        {{ item.existencia }}
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <span v-if="item.costo_inventario !== null">
                                            {{ formatCurrency(item.costo_inventario) }}
                                        </span>
                                        <span v-else>—</span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <template v-if="item.proveedor">
                                            <div class="font-medium text-gray-900 flex items-center gap-2 flex-wrap">
                                                <span>{{ item.proveedor.nombre }}</span>
                                                <span
                                                    class="rounded-full border px-2 py-0.5 text-[11px] font-medium"
                                                    :class="getProviderTypeClass(item.proveedor.tipo ?? null)"
                                                >
                                                    {{ getProviderTypeLabel(item.proveedor.tipo ?? null) }}
                                                </span>
                                            </div>
                                            <div class="text-[11px] text-gray-500">{{ item.proveedor.ident }}</div>
                                        </template>
                                        <template v-else>—</template>
                                    </td>
                                </tr>
                                <tr v-if="inventarioItems.length === 0">
                                    <td colspan="8" :class="tableClasses.emptyRow">
                                        No se encontraron registros.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-700 shadow-sm">
                        <div class="flex flex-wrap items-center gap-4">
                            <span>Total productos: <strong class="text-gray-900">{{ inventarioTotals.total_productos }}</strong></span>
                            <span>Total existencia: <strong class="text-gray-900">{{ inventarioTotals.total_existencia }}</strong></span>
                            <span>
                                Valor público:
                                <strong class="text-gray-900">{{ formatCurrency(inventarioTotals.valor_publico) }}</strong>
                            </span>
                            <span>
                                Valor proveedor:
                                <strong class="text-gray-900">{{ formatCurrency(inventarioTotals.valor_proveedor) }}</strong>
                            </span>
                        </div>
                    </div>

                    <div v-if="inventarioPagination" class="mt-3 flex flex-wrap items-center justify-between gap-3 text-[11px] text-gray-600">
                        <div>
                            Página {{ inventarioPagination.current_page }} de {{ inventarioPagination.last_page }}
                            · {{ inventarioPagination.total }} registros
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" class="rounded border border-gray-300 px-2 py-1" @click="goToFirstPage">Primera</button>
                            <button type="button" class="rounded border border-gray-300 px-2 py-1" @click="goToPrevPage">Anterior</button>
                            <button type="button" class="rounded border border-gray-300 px-2 py-1" @click="goToNextPage">Siguiente</button>
                            <button type="button" class="rounded border border-gray-300 px-2 py-1" @click="goToLastPage">Última</button>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
