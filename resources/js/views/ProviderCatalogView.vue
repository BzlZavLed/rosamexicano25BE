<script setup lang="ts">
import { ref, computed, onMounted, reactive } from 'vue';
import AppLayout from '../components/layout/AppLayout.vue';
import type { ProductoRow, InventarioRow } from '../api/reports';
import { getProductosReport, getInventarioReport } from '../api/reports';
import { useAuthStore } from '../stores/auth';
import { storeToRefs } from 'pinia';

type CatalogRow = {
    ident: string;
    nombre: string;
    descripcion: string;
    precio: number | null;
    existencia: number;
    costo_inventario: number | null;
};

const auth = useAuthStore();
const { provider } = storeToRefs(auth);
const providerName = computed(() => provider.value?.nombre || auth.displayName || 'proveedor');
const loading = ref(false);
const error = ref('');
const productos = ref<ProductoRow[]>([]);
const inventario = ref<InventarioRow[]>([]);
const lastUpdated = ref<Date | null>(null);
const pagination = reactive({
    page: 1,
    perPage: 25,
});
const perPageOptions = [10, 25, 50, 100, 200];

async function fetchAllProductos(perPage = 10) {
    const rows: ProductoRow[] = [];
    let page = 1;
    let lastPage: number | null = null;

    while (true) {
        const resp = await getProductosReport({
            page,
            per_page: perPage,
            sort: 'nombre',
            direction: 'asc',
        });
        rows.push(...resp.data);
        const paginationInfo = resp.pagination;
        lastPage = paginationInfo?.last_page ?? lastPage;
        const isLastPage =
            (paginationInfo && page >= paginationInfo.last_page) ||
            (!paginationInfo && resp.data.length < perPage);
        if (isLastPage) break;
        page++;
    }

    return rows;
}

async function fetchAllInventario(perPage = 200) {
    const rows: InventarioRow[] = [];
    let page = 1;
    let lastPage: number | null = null;

    while (true) {
        const resp = await getInventarioReport({
            page,
            per_page: perPage,
            sort: 'producto',
            direction: 'asc',
        });
        rows.push(...resp.data);
        const paginationInfo = resp.pagination;
        lastPage = paginationInfo?.last_page ?? lastPage;
        const isLastPage =
            (paginationInfo && page >= paginationInfo.last_page) ||
            (!paginationInfo && resp.data.length < perPage);
        if (isLastPage) break;
        page++;
    }

    return rows;
}

async function fetchData() {
    loading.value = true;
    error.value = '';
    try {
        const [allProductos, allInventario] = await Promise.all([
            fetchAllProductos(),
            fetchAllInventario(),
        ]);
        productos.value = allProductos;
        inventario.value = allInventario;
        pagination.page = 1;
        lastUpdated.value = new Date();
    } catch (err: any) {
        error.value = err?.response?.data?.message || 'No se pudo cargar el catálogo.';
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    if (!provider.value) {
        await auth.hydrateFromToken();
    }
    await fetchData();
});

const inventarioMap = computed(() => {
    const map = new Map<string, InventarioRow>();
    for (const row of inventario.value) {
        map.set(String(row.producto_ident), row);
    }
    return map;
});

const catalogRows = computed<CatalogRow[]>(() => {
    const rows: CatalogRow[] = [];
    const seen = new Set<string>();
    const invMap = inventarioMap.value;

    for (const producto of productos.value) {
        const ident = String(producto.ident);
        const inventarioRow = invMap.get(ident);
        const descripcion =
            (producto as ProductoRow & { descripcion?: string | null }).descripcion ?? '';
        rows.push({
            ident,
            nombre: producto.nombre,
            descripcion,
            precio: producto.precio ?? null,
            existencia: inventarioRow?.existencia ?? 0,
            costo_inventario: inventarioRow?.costo_inventario ?? null,
        });
        seen.add(ident);
    }

    for (const inventarioRow of inventario.value) {
        const ident = String(inventarioRow.producto_ident);
        if (seen.has(ident)) continue;
        rows.push({
            ident,
            nombre: inventarioRow.producto_nombre,
            descripcion: inventarioRow.producto_nombre ?? '',
            precio: inventarioRow.precio ?? null,
            existencia: inventarioRow.existencia ?? 0,
            costo_inventario: inventarioRow.costo_inventario ?? null,
        });
    }

    return rows.sort((a, b) => a.nombre.localeCompare(b.nombre));
});

const totalProductos = computed(() => catalogRows.value.length);
const totalExistencia = computed(() => catalogRows.value.reduce((acc, row) => acc + row.existencia, 0));
const paginatedRows = computed(() => {
    const start = (pagination.page - 1) * pagination.perPage;
    return catalogRows.value.slice(start, start + pagination.perPage);
});
const totalPages = computed(() => Math.max(1, Math.ceil(totalProductos.value / pagination.perPage)));
function nextPage() {
    if (pagination.page < totalPages.value) pagination.page++;
}
function prevPage() {
    if (pagination.page > 1) pagination.page--;
}
function resetPage() {
    pagination.page = 1;
}
</script>

<template>
    <AppLayout>
        <div class="p-6 space-y-6">
            <header class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-gray-900">
                        Bienvenido {{ providerName }}
                    </h1>
                    <p class="text-sm text-gray-600">Productos e inventario</p>
                    <p class="text-sm text-gray-500">
                        Visualiza el catálogo asignado a <span class="font-medium text-gray-700">{{ providerName }}</span>.
                        Estos datos son informativos; para ajustes contacta al administrador.
                    </p>
                </div>
                <div class="flex gap-2">
                    <button
                        type="button"
                        @click="fetchData"
                        class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                        :disabled="loading"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M21 12a9 9 0 0 1-9 9 9 9 0 1 1 6.36-15.36" />
                            <path d="M21 3v6h-6" stroke-linecap="round" />
                        </svg>
                        {{ loading ? 'Actualizando…' : 'Actualizar' }}
                    </button>
                </div>
            </header>

            <section v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {{ error }}
            </section>

            <section v-else class="space-y-4">
                <div class="flex flex-wrap items-center gap-4 text-sm text-gray-600">
                    <span>Total de productos: <strong class="text-gray-900">{{ totalProductos }}</strong></span>
                    <span>Inventario total: <strong class="text-gray-900">{{ totalExistencia }}</strong></span>
                    <span v-if="lastUpdated">Última actualización: {{ lastUpdated.toLocaleString() }}</span>
                </div>

                <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th scope="col" class="px-4 py-3 text-left">Producto</th>
                                <th scope="col" class="px-4 py-3 text-left">Descripción</th>
                                <th scope="col" class="px-4 py-3 text-left">Ident</th>
                                <th scope="col" class="px-4 py-3 text-right">Precio</th>
                                <th scope="col" class="px-4 py-3 text-right">Existencia</th>
                                <th scope="col" class="px-4 py-3 text-right">Valor inventario</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="loading">
                                <td colspan="6" class="px-4 py-6 text-center text-gray-500">
                                    Cargando catálogo…
                                </td>
                            </tr>
                            <tr v-else-if="catalogRows.length === 0">
                                <td colspan="6" class="px-4 py-6 text-center text-gray-500">
                                    No hay productos asignados en este momento.
                                </td>
                            </tr>
                            <tr v-for="row in paginatedRows" :key="row.ident" class="hover:bg-gray-50">
                                <td class="px-4 py-3 font-medium text-gray-900">{{ row.nombre }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ row.descripcion || '—' }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ row.ident }}</td>
                                <td class="px-4 py-3 text-right text-gray-700">
                                    <span v-if="row.precio !== null">${{ row.precio.toFixed(2) }}</span>
                                    <span v-else class="text-gray-400">—</span>
                                </td>
                                <td class="px-4 py-3 text-right text-gray-700">{{ row.existencia }}</td>
<td class="px-4 py-3 text-right text-gray-700">
    <span v-if="row.costo_inventario !== null">
        {{ new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(row.costo_inventario) }}
    </span>
    <span v-else class="text-gray-400">—</span>
</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3 text-sm text-gray-600">
                    <div class="flex items-center gap-2">
                        <label for="per-page" class="text-gray-600">Registros por página:</label>
                        <select
                            id="per-page"
                            v-model.number="pagination.perPage"
                            @change="resetPage"
                            class="rounded border border-gray-300 px-2 py-1 text-sm text-gray-700"
                        >
                            <option v-for="option in perPageOptions" :key="option" :value="option">
                                {{ option }}
                            </option>
                        </select>
                    </div>
                    <div>
                        Página {{ pagination.page }} de {{ totalPages }}
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" @click="prevPage" :disabled="pagination.page === 1"
                            class="rounded border px-3 py-1 disabled:opacity-50">Anterior</button>
                        <button type="button" @click="nextPage" :disabled="pagination.page === totalPages"
                            class="rounded border px-3 py-1 disabled:opacity-50">Siguiente</button>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
