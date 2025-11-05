<script setup lang="ts">
import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue';
import AppLayout from '../components/layout/AppLayout.vue';
import {
    listClientes,
    createCliente,
    updateCliente,
    type Cliente
} from '../api/clientes';

const PER_PAGE = 20;

const loading = ref(false);
const saving = ref(false);
const message = ref('');
const error = ref('');
const search = ref('');
const clientes = ref<Cliente[]>([]);
const selectedId = ref<number | null>(null);

const pagination = reactive({
    page: 1,
    perPage: PER_PAGE,
    total: 0,
    lastPage: 1,
});

const form = reactive({
    id: null as number | null,
    nombre: '',
    email: '',
    telefono: '',
});

let searchTimer: number | undefined;

const hasSelection = computed(() => selectedId.value != null);

const pageInfo = computed(() => {
    if (!pagination.total) return null;
    const start = (pagination.page - 1) * pagination.perPage + 1;
    const end = Math.min(start + pagination.perPage - 1, pagination.total);
    return { start, end };
});

const canPrev = computed(() => pagination.page > 1);
const canNext = computed(() => pagination.page < pagination.lastPage);

function resetForm() {
    form.id = null;
    form.nombre = '';
    form.email = '';
    form.telefono = '';
    selectedId.value = null;
    message.value = '';
    error.value = '';
}

async function loadClientes() {
    loading.value = true;
    error.value = '';
    try {
        const params: Record<string, any> = {
            page: pagination.page,
            per_page: pagination.perPage,
        };
        if (search.value.trim()) params.search = search.value.trim();

        const resp = await listClientes(params);
        const rows = Array.isArray((resp as any)?.data)
            ? (resp as any).data
            : Array.isArray(resp)
                ? resp
                : [];

        clientes.value = rows;

        const meta = (resp as any)?.meta ?? null;
        const total = meta?.total ?? (resp as any)?.total ?? rows.length;
        const lastPage = meta?.last_page ?? (resp as any)?.last_page ?? (total ? Math.ceil(total / pagination.perPage) : 1);

        pagination.total = total ?? rows.length;
        pagination.lastPage = Math.max(1, lastPage || 1);

        if (pagination.page > pagination.lastPage) {
            const newPage = Math.max(1, pagination.lastPage);
            if (newPage !== pagination.page) {
                pagination.page = newPage;
            }
            return;
        }
    } catch (e: any) {
        error.value = e?.response?.data?.message || 'No se pudo cargar la lista de clientes';
        clientes.value = [];
        pagination.total = 0;
        pagination.lastPage = 1;
    } finally {
        loading.value = false;
    }
}

function scheduleFetch() {
    if (searchTimer) {
        clearTimeout(searchTimer);
        searchTimer = undefined;
    }
    searchTimer = window.setTimeout(loadClientes, 350);
}

function selectCliente(cliente: Cliente) {
    selectedId.value = cliente.id;
    form.id = cliente.id;
    form.nombre = cliente.nombre ?? '';
    form.email = cliente.email ?? '';
    form.telefono = cliente.telefono ?? '';
    message.value = '';
    error.value = '';
}

async function saveCliente() {
    error.value = '';
    message.value = '';
    const nombre = form.nombre.trim();
    const email = form.email.trim() || null;
    const telefono = form.telefono.trim() || null;

    if (!nombre) {
        error.value = 'El nombre es obligatorio';
        return;
    }

    saving.value = true;
    try {
        if (form.id) {
            await updateCliente(form.id, { nombre, email, telefono });
            message.value = 'Cliente actualizado';
        } else {
            await createCliente({ nombre, email, telefono });
            message.value = 'Cliente creado';
        }
        await loadClientes();
        resetForm();
    } catch (e: any) {
        error.value = e?.response?.data?.message || 'No se pudo guardar el cliente';
    } finally {
        saving.value = false;
    }
}

function goToPage(page: number) {
    if (page < 1 || page > pagination.lastPage || page === pagination.page) return;
    pagination.page = page;
}

watch(search, () => {
    pagination.page = 1;
    scheduleFetch();
});

watch(() => pagination.page, (newVal, oldVal) => {
    if (oldVal === undefined || newVal === oldVal) return;
    loadClientes();
});

onMounted(() => {
    resetForm();
    loadClientes();
});

onUnmounted(() => {
    if (searchTimer) {
        clearTimeout(searchTimer);
        searchTimer = undefined;
    }
});
</script>

<template>
    <AppLayout>
        <div class="space-y-6">
            <header class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-gray-900">Clientes</h1>
                    <p class="text-sm text-gray-500">Administra los clientes registrados en el sistema.</p>
                </div>
                <div class="flex items-center gap-2">
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Buscar por nombre, correo o teléfono…"
                        class="w-full sm:w-72 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                    />
                    <button
                        type="button"
                        class="hidden sm:inline-flex items-center justify-center rounded-lg border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50"
                        @click="resetForm"
                    >
                        Limpiar formulario
                    </button>
                </div>
            </header>

            <div class="grid gap-6 lg:grid-cols-[2fr,1fr]">
                <section class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                    <div class="border-b border-gray-200 px-4 py-3 flex items-center justify-between">
                        <div class="text-sm text-gray-600">
                            <span v-if="loading">Cargando clientes…</span>
                            <span v-else-if="pageInfo">Mostrando {{ pageInfo.start }} - {{ pageInfo.end }} de {{ pagination.total }}</span>
                            <span v-else>Sin registros</span>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <button
                                type="button"
                                class="inline-flex items-center rounded-lg border border-gray-300 px-2.5 py-1.5 hover:bg-gray-50 disabled:opacity-50"
                                :disabled="loading || !canPrev"
                                @click="goToPage(pagination.page - 1)"
                            >
                                Anterior
                            </button>
                            <div class="text-xs text-gray-500">
                                Página {{ pagination.page }} / {{ pagination.lastPage }}
                            </div>
                            <button
                                type="button"
                                class="inline-flex items-center rounded-lg border border-gray-300 px-2.5 py-1.5 hover:bg-gray-50 disabled:opacity-50"
                                :disabled="loading || !canNext"
                                @click="goToPage(pagination.page + 1)"
                            >
                                Siguiente
                            </button>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-gray-600 uppercase text-xs tracking-wide">
                                <tr>
                                    <th class="px-4 py-3 text-left font-medium">ID</th>
                                    <th class="px-4 py-3 text-left font-medium">Nombre</th>
                                    <th class="px-4 py-3 text-left font-medium">Correo</th>
                                    <th class="px-4 py-3 text-left font-medium">Teléfono</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-if="error">
                                    <td colspan="4" class="px-4 py-6 text-center text-rose-600">{{ error }}</td>
                                </tr>
                                <tr v-else-if="!loading && clientes.length === 0">
                                    <td colspan="4" class="px-4 py-6 text-center text-gray-500">No hay clientes que coincidan con la búsqueda.</td>
                                </tr>
                                <tr
                                    v-for="cliente in clientes"
                                    :key="cliente.id"
                                    class="cursor-pointer hover:bg-gray-50"
                                    :class="cliente.id === selectedId ? 'bg-gray-50' : ''"
                                    @click="selectCliente(cliente)"
                                >
                                    <td class="px-4 py-3 font-mono text-xs text-gray-500">#{{ cliente.id }}</td>
                                    <td class="px-4 py-3 font-medium text-gray-800">
                                        <div class="truncate max-w-[24ch]" :title="cliente.nombre">{{ cliente.nombre }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-gray-600">
                                        <span class="truncate max-w-[26ch] inline-block" :title="cliente.email ?? 'Sin correo'">
                                            {{ cliente.email ?? 'Sin correo' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-600">
                                        {{ cliente.telefono || 'Sin teléfono' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="bg-white border border-gray-200 rounded-xl shadow-sm p-5 space-y-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">{{ hasSelection ? 'Editar cliente' : 'Nuevo cliente' }}</h2>
                            <p class="text-xs text-gray-500 mt-1">Completa la información y guarda para registrar o actualizar.</p>
                        </div>
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-3 py-1.5 text-xs uppercase tracking-wide hover:bg-gray-50"
                            @click="resetForm"
                        >
                            Limpiar
                        </button>
                    </div>

                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Nombre</label>
                            <input
                                v-model="form.nombre"
                                type="text"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Correo electrónico</label>
                            <input
                                v-model="form.email"
                                type="email"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                                placeholder="cliente@email.com"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Teléfono</label>
                            <input
                                v-model="form.telefono"
                                type="tel"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                                placeholder="10 dígitos"
                            />
                        </div>
                    </div>

                    <div class="space-y-3">
                        <button
                            type="button"
                            class="w-full inline-flex items-center justify-center rounded-lg bg-gray-900 hover:bg-gray-800 text-white px-3 py-2 text-sm disabled:opacity-60"
                            :disabled="saving"
                            @click="saveCliente"
                        >
                            {{ saving ? 'Guardando…' : hasSelection ? 'Actualizar cliente' : 'Crear cliente' }}
                        </button>
                        <div v-if="message" class="rounded border border-emerald-200 bg-emerald-50 text-emerald-700 px-3 py-2 text-sm">
                            {{ message }}
                        </div>
                        <div v-if="error && !loading" class="rounded border border-rose-200 bg-rose-50 text-rose-700 px-3 py-2 text-sm">
                            {{ error }}
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
