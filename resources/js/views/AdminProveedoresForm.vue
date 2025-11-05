<script setup lang="ts">
import { ref, reactive, onMounted, watch, computed } from 'vue';
import AppLayout from '../components/layout/AppLayout.vue';
import {
    listProveedores, createProveedor, updateProveedor, deleteProveedor, type Proveedor
} from '../api/proveedores';

const loading = ref(false);
const saving = ref(false);
const message = ref('');
const error = ref('');

const q = ref('');
const proveedores = ref<Proveedor[]>([]);
const selectedId = ref<number | null>(null);
const totalProveedores = computed(() => pagination.total || proveedores.value.length);
const hasSelection = computed(() => selectedId.value != null);
const pagination = reactive({
    page: 1,
    perPage: 20,
    lastPage: 1,
    total: 0,
});
const pageNumbers = computed(() => {
    const pages = Math.max(1, pagination.lastPage || 1);
    return Array.from({ length: pages }, (_, idx) => idx + 1);
});
const filterEmailMode = ref<'all' | 'with' | 'without'>('all');
const filterImporteConValor = ref(false);
const visibleProveedores = computed(() => {
    return proveedores.value.filter((p) => {
        if (filterEmailMode.value === 'with') {
            const email = (p.email ?? '').trim();
            if (!email) return false;
        } else if (filterEmailMode.value === 'without') {
            const email = (p.email ?? '').trim();
            if (email) return false;
        }
        if (filterImporteConValor.value) {
            const amount = Number(p.importe ?? 0);
            if (!Number.isFinite(amount) || amount === 0) return false;
        }
        return true;
    });
});
const filteredCountLabel = computed(() => `${visibleProveedores.value.length} / ${proveedores.value.length}`);
const pageInfo = computed(() => {
    if (!pagination.total) return null;
    const start = (pagination.page - 1) * pagination.perPage + 1;
    const end = Math.min(start + pagination.perPage - 1, pagination.total);
    return { start, end };
});

type FormT = {
    id?: number | null;
    ident: number | null;
    nombre: string;
    tel: string;
    email: string;
    fecha: string;        // YYYY-MM-DD
    ciudad: string;
    bancaria: string;     // cuenta
    sucursal: string;     // banco
    importe: number | null; // cobro mensual
};
const form = reactive<FormT>({
    id: null,
    ident: null,
    nombre: '',
    tel: '',
    email: '',
    fecha: new Date().toISOString().slice(0, 10),
    ciudad: '',
    bancaria: '',
    sucursal: '',
    importe: null,
});

function randIdent(): number { return Math.floor(100000 + Math.random() * 900000); }

function formatCurrency(amount: number, currencyCode = 'MXN', locale = 'es-MX') {
    if (!Number.isFinite(amount)) return '$0.00';
    try {
        return new Intl.NumberFormat(locale, { style: 'currency', currency: currencyCode }).format(amount);
    } catch {
        return `$${amount.toFixed(2)}`;
    }
}

function resetForm() {
    form.id = null;
    form.ident = randIdent();
    form.nombre = '';
    form.tel = '';
    form.email = '';
    form.fecha = new Date().toISOString().slice(0, 10);
    form.ciudad = '';
    form.bancaria = '';
    form.sucursal = '';
    form.importe = null;
    selectedId.value = null;
    message.value = '';
    error.value = '';
}

async function loadList() {
    loading.value = true;
    try {
        const params = q.value ? { search: q.value, page: pagination.page, per_page: pagination.perPage } : { page: pagination.page, per_page: pagination.perPage };
        const data = await listProveedores(params);
        const rows = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
        proveedores.value = rows;
        const meta = data?.meta ?? null;
        const total = meta?.total ?? data?.total ?? rows.length;
        const lastPage = meta?.last_page ?? meta?.lastPage ?? (total ? Math.ceil(total / pagination.perPage) : 1);
        pagination.total = total;
        pagination.lastPage = Math.max(1, lastPage || 1);
        if (pagination.page > pagination.lastPage) pagination.page = pagination.lastPage;
    } catch (e: any) {
        error.value = e?.response?.data?.message || 'Error listando proveedores';
    } finally {
        loading.value = false;
    }
}

async function selectRow(row: Proveedor) {
    selectedId.value = row.id;
    form.id = row.id;
    form.ident = row.ident;
    form.nombre = row.nombre || '';
    form.tel = row.tel || '';
    form.email = row.email || '';
    form.fecha = row.fecha || new Date().toISOString().slice(0, 10);
    form.ciudad = row.ciudad || '';
    form.bancaria = row.bancaria || '';
    form.sucursal = row.sucursal || '';   // banco
    form.importe = (row.importe as any) != null ? Number(row.importe) : null;
    message.value = ''; error.value = '';
}

async function submitCreateOrUpdate() {
    error.value = ''; message.value = '';
    if (!form.ident) form.ident = randIdent();
    if (!form.nombre) { error.value = 'El nombre es obligatorio'; return; }
    let payload = {};
    saving.value = true;
    try {
        let saved: Proveedor;
        payload = {
            ident: form.ident!,
            nombre: form.nombre,
            tel: form.tel || null,
            email: form.email || null,
            fecha: form.fecha || null,
            ciudad: form.ciudad || null,
            bancaria: form.bancaria || null,
            sucursal: form.sucursal || null,
            importe: form.importe != null ? Number(form.importe) : null,
        };

        if (form.id) {
            saved = await updateProveedor(form.id, payload);
            message.value = 'Proveedor actualizado';
        } else {
            saved = await createProveedor(payload);
            message.value = 'Proveedor creado';
            form.id = saved.id; selectedId.value = saved.id;
        }
        await loadList();
        resetForm();
    } catch (e: any) {
        error.value = e?.response?.data?.message || 'Error guardando proveedor';
    } finally {
        saving.value = false;
    }
}

async function removeProveedor() {
    if (!form.id) return;
    if (!confirm('¿Eliminar proveedor?')) return;
    saving.value = true; error.value = ''; message.value = '';
    try {
        await deleteProveedor(form.id);
        message.value = 'Proveedor eliminado';
        resetForm();
        await loadList();
    } catch (e: any) {
        error.value = e?.response?.data?.message || 'No se pudo eliminar';
    } finally {
        saving.value = false;
    }
}

watch(q, () => { pagination.page = 1; loadList(); });

watch(() => pagination.perPage, (newVal, oldVal) => {
    if (oldVal === undefined || newVal === oldVal) return;
    pagination.page = 1;
    loadList();
});

watch(() => pagination.page, (newVal, oldVal) => {
    if (oldVal === undefined || newVal === oldVal) return;
    loadList();
});

watch([filterEmailMode, filterImporteConValor], () => {
    if (selectedId.value != null) {
        const stillVisible = visibleProveedores.value.some((p) => p.id === selectedId.value);
        if (!stillVisible) {
            resetForm();
        }
    }
});

onMounted(async () => {
    resetForm();
    await loadList();
});
</script>

<template>
    <AppLayout>
        <div class="space-y-6">
            <!-- Form section -->
            <section class="bg-white border border-gray-200 rounded-xl shadow-sm p-5 md:p-6 space-y-5">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-xl font-semibold">Dar de alta proveedores</h2>
                        <p class="text-xs text-gray-500 mt-1">Captura la información del proveedor y mantenla actualizada
                            con la ficha inferior.</p>
                    </div>
                    <button type="button" @click="resetForm"
                        class="inline-flex items-center justify-center rounded-lg border px-3 py-1.5 text-xs font-medium uppercase tracking-wide hover:bg-gray-50">
                        Limpiar formulario
                    </button>
                </div>

                <div v-if="message"
                    class="rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-700 px-4 py-2 text-sm">
                    {{ message }}
                </div>
                <div v-if="error"
                    class="rounded-lg border border-rose-200 bg-rose-50 text-rose-700 px-4 py-2 text-sm">
                    {{ error }}
                </div>

                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 space-y-3">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div class="space-y-1">
                            <label class="block text-sm font-medium text-gray-700"># Identificador</label>
                            <div class="flex gap-2">
                                <input v-model.number="form.ident" type="number" inputmode="numeric"
                                    class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2"
                                    placeholder="IDENTIFICADOR" />
                                <button type="button" @click="form.ident = randIdent()"
                                    class="shrink-0 rounded-lg border px-3 py-2 text-sm hover:bg-gray-100 transition">Generar</button>
                            </div>
                            <p class="text-xs text-gray-500">Este identificador se comparte con el proveedor.</p>
                        </div>
                        <div class="space-y-1">
                            <label class="block text-sm font-medium text-gray-700">Nombre</label>
                            <input v-model="form.nombre" type="text"
                                class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2"
                                placeholder="Nombre del proveedor" />
                        </div>
                        <div class="space-y-1">
                            <label class="block text-sm font-medium text-gray-700">Fecha de alta</label>
                            <input v-model="form.fecha" type="date"
                                class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2" />
                        </div>
                        <div class="space-y-1">
                            <label class="block text-sm font-medium text-gray-700">Tel contacto</label>
                            <input v-model="form.tel" type="tel"
                                class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2"
                                placeholder="(xxx) xxx xxxx" />
                        </div>
                        <div class="space-y-1">
                            <label class="block text-sm font-medium text-gray-700">Email proveedor</label>
                            <input v-model="form.email" type="email"
                                class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2"
                                placeholder="email@proveedor.com" />
                        </div>
                        <div class="space-y-1">
                            <label class="block text-sm font-medium text-gray-700">Cobro mensual</label>
                            <input v-model.number="form.importe" type="number" min="0" step="0.01"
                                class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2"
                                placeholder="Ej. 1500.00" />
                        </div>
                        <div class="space-y-1">
                            <label class="block text-sm font-medium text-gray-700">Ciudad / Municipio</label>
                            <input v-model="form.ciudad" type="text"
                                class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2"
                                placeholder="Ciudad" />
                        </div>
                        <div class="space-y-1">
                            <label class="block text-sm font-medium text-gray-700">Cuenta bancaria</label>
                            <input v-model="form.bancaria" type="text"
                                class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2"
                                placeholder="# Cuenta" />
                        </div>
                        <div class="space-y-1">
                            <label class="block text-sm font-medium text-gray-700">Banco</label>
                            <input v-model="form.sucursal" type="text"
                                class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2"
                                placeholder="Banco" />
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-4 text-xs text-gray-600">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-gray-500 uppercase tracking-wide text-[11px]">Estado del formulario</p>
                            <p class="text-sm font-medium text-gray-800">
                                {{ hasSelection ? 'Editando proveedor existente.' : 'Creando nuevo proveedor.' }}
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-4">
                            <div>
                                <span class="block text-[11px] uppercase text-gray-400">Total registrados</span>
                                <span class="text-sm font-semibold text-gray-800">{{ totalProveedores }}</span>
                            </div>
                            <div>
                                <span class="block text-[11px] uppercase text-gray-400">Seleccionado</span>
                                <span class="text-sm font-semibold text-gray-800">
                                    {{ form.nombre || 'Nuevo proveedor' }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button :disabled="saving" @click="submitCreateOrUpdate"
                        class="rounded-lg bg-[#E4007C] hover:bg-[#cc006f] text-white px-4 py-2 text-sm disabled:opacity-60">
                        {{ form.id ? 'Actualizar proveedor' : 'Crear proveedor' }}
                    </button>
                    <button :disabled="!form.id || saving" @click="removeProveedor"
                        class="rounded-lg bg-rose-500 hover:bg-rose-600 text-white px-4 py-2 text-sm disabled:opacity-60">
                        Eliminar proveedor
                    </button>
                </div>
            </section>

            <!-- List section -->
            <section class="bg-white border border-gray-200 rounded-xl shadow-sm p-5 md:p-6 space-y-4">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Listado de proveedores</h3>
                        <p class="text-xs text-gray-500 mt-1">Selecciona un proveedor para cargar sus datos en el
                            formulario.</p>
                    </div>
                    <div class="text-xs text-gray-500 text-right sm:text-left">
                        Filtrados en esta página:
                        <span class="font-semibold text-gray-800">{{ filteredCountLabel }}</span>
                        <div>
                            Total catálogo: <span class="font-semibold text-gray-800">{{ pagination.total }}</span>
                        </div>
                    </div>
                </div>

                <div class="space-y-1">
                    <label class="block text-sm font-medium text-gray-700">Buscar</label>
                    <input v-model="q" type="text" placeholder="Nombre, email, teléfono, ciudad…"
                        class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2" />
                
                    <div class="flex flex-wrap items-center gap-3 text-xs text-gray-600">
                        <div class="flex items-center gap-2">
                            <span>Email:</span>
                            <label class="inline-flex items-center gap-1">
                                <input type="radio" value="all" v-model="filterEmailMode"
                                    class="h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-gray-900">
                                <span>Todos</span>
                            </label>
                            <label class="inline-flex items-center gap-1">
                                <input type="radio" value="with" v-model="filterEmailMode"
                                    class="h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-gray-900">
                                <span>Con email</span>
                            </label>
                            <label class="inline-flex items-center gap-1">
                                <input type="radio" value="without" v-model="filterEmailMode"
                                    class="h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-gray-900">
                                <span>Sin email</span>
                            </label>
                        </div>
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" v-model="filterImporteConValor"
                                class="h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-gray-900">
                            <span>Importe mensual &gt; 0</span>
                        </label>
                    </div>

                    <div class="flex flex-col gap-3 text-xs text-gray-600 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex flex-wrap items-center gap-2">
                            <span>Filas por página:</span>
                            <select v-model.number="pagination.perPage"
                                class="rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-gray-900 focus:ring-gray-900">
                                <option v-for="option in [10, 20, 50, 100]" :key="option" :value="option">{{ option }}</option>
                            </select>
                            <span v-if="pageInfo">Mostrando {{ pageInfo.start }} – {{ pageInfo.end }} de {{ pagination.total }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button @click="pagination.page = Math.max(1, pagination.page - 1)" :disabled="pagination.page <= 1"
                                class="rounded-lg border px-3 py-1.5 text-sm hover:bg-gray-50 disabled:opacity-50">Anterior</button>
                            <select v-model.number="pagination.page"
                                class="rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-gray-900 focus:ring-gray-900">
                                <option v-for="pageNumber in pageNumbers" :key="pageNumber" :value="pageNumber">Página {{ pageNumber }}</option>
                            </select>
                            <button @click="pagination.page = Math.min(pagination.lastPage, pagination.page + 1)" :disabled="pagination.page >= pagination.lastPage"
                                class="rounded-lg border px-3 py-1.5 text-sm hover:bg-gray-50 disabled:opacity-50">Siguiente</button>
                        </div>
                    </div></div>

                <div class="border border-gray-200 rounded-lg overflow-hidden">
                    <!-- Desktop table -->
                    <table class="hidden min-w-full text-sm md:table">
                        <thead class="bg-gray-50 text-gray-500">
                            <tr>
                                <th class="text-left font-medium px-3 py-2">ID</th>
                                <th class="text-left font-medium px-3 py-2">Ident</th>
                                <th class="text-left font-medium px-3 py-2">Nombre</th>
                                <th class="text-left font-medium px-3 py-2">Tel</th>
                                <th class="text-left font-medium px-3 py-2">Email</th>
                                <th class="text-left font-medium px-3 py-2">Ciudad</th>
                                <th class="text-left font-medium px-3 py-2">Importe</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in visibleProveedores" :key="p.id" @click="selectRow(p)"
                                :class="['cursor-pointer hover:bg-gray-50 transition', selectedId === p.id ? 'bg-gray-100' : '']">
                                <td class="px-3 py-2">{{ p.id }}</td>
                                <td class="px-3 py-2">{{ p.ident }}</td>
                                <td class="px-3 py-2">{{ p.nombre }}</td>
                                <td class="px-3 py-2">{{ p.tel || '—' }}</td>
                                <td class="px-3 py-2">{{ p.email || '—' }}</td>
                                <td class="px-3 py-2">{{ p.ciudad || '—' }}</td>
                                <td class="px-3 py-2 text-xs sm:text-[12px] whitespace-nowrap">{{ p.importe != null ? formatCurrency(Number(p.importe)) : '—' }}</td>
                            </tr>
                            <tr v-if="!loading && visibleProveedores.length === 0">
                                <td colspan="6" class="px-3 py-3 text-center text-gray-500">Sin resultados</td>
                            </tr>
                            <tr v-if="loading">
                                <td colspan="6" class="px-3 py-3 text-center text-gray-500">Cargando…</td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Mobile cards -->
                    <div class="md:hidden divide-y divide-gray-100 max-h-80 overflow-auto">
                        <button v-for="p in visibleProveedores" :key="p.id" @click="selectRow(p)"
                            class="w-full text-left p-3 space-y-2 transition hover:bg-gray-50"
                            :class="selectedId === p.id ? 'bg-gray-100' : 'bg-white'">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-semibold text-gray-800">{{ p.nombre }}</span>
                                <span class="text-xs text-gray-500">#{{ p.ident }}</span>
                            </div>
                            <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-gray-600">
                                <div><span class="font-medium text-gray-700">Tel:</span> {{ p.tel || '—' }}</div>
                                <div><span class="font-medium text-gray-700">Email:</span> {{ p.email || '—' }}</div>
                                <div><span class="font-medium text-gray-700">Ciudad:</span> {{ p.ciudad || '—' }}</div>
                                <div><span class="font-medium text-gray-700">Banco:</span> {{ p.sucursal || '—' }}</div>
                                <div class="col-span-2"><span class="font-medium text-gray-700">Importe:</span> {{ p.importe != null ? formatCurrency(Number(p.importe)) : '—' }}</div>
                            </div>
                        </button>
                        <div v-if="!loading && visibleProveedores.length === 0" class="p-4 text-center text-sm text-gray-500">
                            Sin resultados
                        </div>
                        <div v-if="loading" class="p-4 text-center text-sm text-gray-500">Cargando…</div>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
