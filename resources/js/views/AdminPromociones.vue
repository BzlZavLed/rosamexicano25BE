<!-- src/views/AdminPromociones.vue -->
<script setup lang="ts">
import AutocompleteRemote from '../components/AutocompleteRemote.vue';
import { fetchProductosBySearch, fetchProveedoresBySearch } from '../api/autocomplete';
import { ref, reactive, computed, onMounted, watch } from 'vue';
import AppLayout from '../components/layout/AppLayout.vue';
import { listPromos, createPromo, updatePromo, deletePromo, type Promocion, type PromoTipo } from '../api/promos';
import { listProductos } from '../api/products';
import { listProveedores } from '../api/proveedores';

type ProductoLite = { id: number; ident: number; nombre: string };
type ProveedorLite = { id: number; ident: number; nombre: string };

const loading = ref(false);
const saving = ref(false);
const error = ref('');
const message = ref('');

const search = ref('');
const activaOnly = ref(false);
const promos = ref<Promocion[]>([]);
const selectedId = ref<number | null>(null);

// helpers: product/provider quick-pickers (optional)
const productos = ref<ProductoLite[]>([]);
const proveedores = ref<ProveedorLite[]>([]);
const productoText = ref('');   // visible text in producto autocomplete
const proveedorText = ref('');
async function loadLists() {
    try {
        const [prodRes, provRes] = await Promise.all([
            listProductos({ page: 1, per_page: 100 }),
            listProveedores({ page: 1, per_page: 100 })
        ]);
        const prodData = Array.isArray(prodRes?.data) ? prodRes.data : (Array.isArray(prodRes) ? prodRes : []);
        const provData = Array.isArray(provRes?.data) ? provRes.data : (Array.isArray(provRes) ? provRes : []);
        productos.value = prodData.map((p: any) => ({ id: p.id, ident: p.ident, nombre: p.nombre }));
        proveedores.value = provData.map((p: any) => ({ id: p.id, ident: p.ident, nombre: p.nombre }));
    } catch { }
}

async function loadPromos() {
    loading.value = true; error.value = '';
    try {
        const data = await listPromos({
            search: search.value || undefined,
            activa: activaOnly.value ? 1 : undefined,
            per_page: 50,
        });
        promos.value = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
    } catch (e: any) {
        error.value = e?.response?.data?.message || 'Error listando promociones';
    } finally {
        loading.value = false;
    }
}
watch([search, activaOnly], () => loadPromos());

// --- Form state ---
type FormT = {
    id?: number | null;
    target: 'producto' | 'proveedor';
    producto: number | null;      // ident
    proveedor: number | null;     // ident
    tipo: PromoTipo;
    descuento: number | null;
    mincompra: number | null;
    gratis: number | null;
    inicia: string | null;        // YYYY-MM-DD
    vence: string | null;         // YYYY-MM-DD
    estado: boolean;
};
const form = reactive<FormT>({
    id: null,
    target: 'producto',
    producto: null,
    proveedor: null,
    tipo: 'descuento',
    descuento: null,
    mincompra: null,
    gratis: null,
    inicia: new Date().toISOString().slice(0, 10),
    vence: null,
    estado: true,
});

function resetForm() {
    form.id = null;
    form.target = 'producto';
    form.producto = null;
    form.proveedor = null;
    form.tipo = 'descuento';
    form.descuento = null;
    form.mincompra = null;
    form.gratis = null;
    form.inicia = new Date().toISOString().slice(0, 10);
    form.vence = null;
    form.estado = true;

      // clear autocomplete visible texts
    productoText.value = '';
    proveedorText.value = '';

    selectedId.value = null;
    error.value = ''; 
    message.value = '';
}

function selectRow(p: Promocion) {
    selectedId.value = p.id;
    form.id = p.id;
    // detect target
    const isProduct = p.producto != null;
    form.target = isProduct ? 'producto' : 'proveedor';
    form.producto = isProduct ? Number(p.producto) : null;
    form.proveedor = !isProduct ? Number(p.proveedor) : null;
    form.tipo = p.tipo as PromoTipo;
    form.descuento = p.descuento ?? null;
    form.mincompra = p.mincompra ?? null;
    form.gratis = p.gratis ?? null;
    form.inicia = p.inicia ?? null;
    form.vence = p.vence ?? null;
    form.estado = !!p.estado;
    error.value = ''; message.value = '';
}

function validate(): string | null {
    if (form.target === 'producto' && !form.producto) return 'Selecciona un producto';
    if (form.target === 'proveedor' && !form.proveedor) return 'Selecciona un proveedor';

    if (form.tipo === 'descuento') {
        const d = Number(form.descuento);
        if (!Number.isFinite(d) || d < 0 || d > 100) return 'Descuento inválido (0–100)';
    } else {
        const m = Number(form.mincompra), g = Number(form.gratis);
        if (!Number.isFinite(m) || m < 1) return 'Min. compra inválido (>=1)';
        if (!Number.isFinite(g) || g < 1) return 'Gratis inválido (>=1)';
    }
    if (!form.vence) return 'Selecciona fecha de vencimiento';
    return null;
}

async function submit() {
    error.value = ''; message.value = '';
    const v = validate();
    if (v) { error.value = v; return; }

    const payload: Partial<Promocion> = {
        producto: form.target === 'producto' ? Number(form.producto) : null,
        proveedor: form.target === 'proveedor' ? Number(form.proveedor) : null,
        tipo: form.tipo,
        descuento: form.tipo === 'descuento' ? Number(form.descuento) : null,
        mincompra: form.tipo === 'bundle' ? Number(form.mincompra) : null,
        gratis: form.tipo === 'bundle' ? Number(form.gratis) : null,
        inicia: form.inicia || null,
        vence: form.vence!,
        estado: !!form.estado,
    };

    saving.value = true;
    try {
        if (form.id) {
            await updatePromo(form.id, payload);
            message.value = 'Promoción actualizada';
        } else {
            const created = await createPromo(payload);
            message.value = 'Promoción creada';
            form.id = (created as any).id;
            selectedId.value = form.id!;
        }
        await loadPromos();
        resetForm();
    } catch (e: any) {
        if (e?.response?.status === 422) {
            const errs = e.response.data?.errors || {};
            error.value = Object.values(errs).flat().join(' • ');
        } else {
            error.value = e?.response?.data?.message || 'Error guardando promoción';
        }
    } finally {
        saving.value = false;
    }
}

async function removePromo() {
    if (!form.id) return;
    if (!confirm('¿Eliminar promoción?')) return;
    saving.value = true; error.value = ''; message.value = '';
    try {
        await deletePromo(form.id);
        message.value = 'Promoción eliminada';
        resetForm();
        await loadPromos();
    } catch (e: any) {
        error.value = e?.response?.data?.message || 'No se pudo eliminar';
    } finally {
        saving.value = false;
    }
}

const tipoIsDescuento = computed(() => form.tipo === 'descuento');
const tipoIsGratis = computed(() => form.tipo === 'bundle');

const tipoLabel = (tipo: PromoTipo) =>
    tipo === 'bundle' ? 'Producto gratis' : 'Descuento (%)';

onMounted(async () => {
    await Promise.all([loadPromos(), loadLists()]);
});
</script>

<template>
    <AppLayout>
        <div class="space-y-6">
            <!-- Form section -->
            <section class="bg-white border border-gray-200 rounded-xl shadow-sm p-5 md:p-6 space-y-5">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-xl font-semibold">Promociones</h2>
                        <p class="text-xs text-gray-500 mt-1">Configura promociones por producto o proveedor y define
                            la
                            vigencia.</p>
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

                <!-- Target & tipo -->
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="space-y-1">
                        <label class="block text-sm font-medium text-gray-700">Aplica a</label>
                        <select v-model="form.target"
                            class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2">
                            <option value="producto">Producto</option>
                            <option value="proveedor">Proveedor</option>
                        </select>
                        <p class="text-xs text-gray-500">Define si la promoción se asigna a un producto específico o a
                            todos los productos del proveedor.</p>
                    </div>
                    <div class="space-y-1">
                        <label class="block text-sm font-medium text-gray-700">Tipo de promoción</label>
                        <select v-model="form.tipo"
                            class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2">
                            <option value="descuento">Descuento (%)</option>
                            <option value="bundle">Producto gratis (3x2, 2x1…)</option>
                        </select>
                        <p class="text-xs text-gray-500">Selecciona si es un descuento directo o una promoción tipo
                            bundle.</p>
                    </div>
                    <label class="flex items-center gap-2 px-3 py-2 text-sm">
                        <input type="checkbox" v-model="form.estado"
                            class="rounded border-gray-300 text-gray-900 focus:ring-gray-900">
                        Promoción activa
                    </label>
                </div>

                <!-- Producto / proveedor selection -->
                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <div v-if="form.target === 'producto'" class="space-y-1">
                        <label class="block text-sm font-medium text-gray-700">Producto</label>
                        <AutocompleteRemote
                            :fetcher="async (q:string)=>({ items: await fetchProductosBySearch(q) })"
                            :labelKey="(it:any)=> it._label"
                            :valueKey="(it:any)=> it._value"
                            placeholder="Buscar por nombre/ident…"
                            :minChars="2"
                            :modelValue="form.producto"
                            :modelText="productoText"
                            @update:modelValue="(v:any)=> form.producto = v"
                            @update:modelText="(t:string)=> productoText = t"
                            @select="(it:any)=> { form.producto = it._value; productoText = it._label; }"
                        />
                        <p class="text-xs text-gray-500">Se guardará el <b>ident</b> del producto.</p>
                    </div>
                    <div v-if="form.target === 'proveedor'" class="space-y-1">
                        <label class="block text-sm font-medium text-gray-700">Proveedor</label>
                        <AutocompleteRemote
                            :fetcher="async (q:string)=>({ items: await fetchProveedoresBySearch(q) })"
                            :labelKey="(it:any)=> it._label"
                            :valueKey="(it:any)=> it._value"
                            placeholder="Buscar proveedor…"
                            :minChars="2"
                            :modelValue="form.proveedor"
                            :modelText="proveedorText"
                            @update:modelValue="(v:any)=> form.proveedor = v"
                            @update:modelText="(t:string)=> proveedorText = t"
                            @select="(it:any)=> { form.proveedor = it._value; proveedorText = it._label; }"
                        />
                        <p class="text-xs text-gray-500">Se guardará el <b>ident</b> del proveedor.</p>
                    </div>
                </div>

                <!-- Campos según tipo -->
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-if="tipoIsDescuento" class="space-y-1">
                        <label class="block text-sm font-medium text-gray-700">Descuento (%)</label>
                        <input v-model.number="form.descuento" type="number" min="0" max="100" step="0.01"
                            class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2"
                            placeholder="Ej. 20" />
                    </div>
                    <div v-if="tipoIsGratis" class="space-y-1">
                        <label class="block text-sm font-medium text-gray-700">Mínimo de compra</label>
                        <input v-model.number="form.mincompra" type="number" min="1" step="1"
                            class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2"
                            placeholder="Ej. 2 (para 3x2)" />
                    </div>
                    <div v-if="tipoIsGratis" class="space-y-1">
                        <label class="block text-sm font-medium text-gray-700">Gratis</label>
                        <input v-model.number="form.gratis" type="number" min="1" step="1"
                            class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2"
                            placeholder="Ej. 1 (para 3x2)" />
                    </div>
                </div>

                <!-- fechas -->
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="space-y-1">
                        <label class="block text-sm font-medium text-gray-700">Fecha de inicio</label>
                        <input v-model="form.inicia" type="date"
                            class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2" />
                    </div>
                    <div class="space-y-1">
                        <label class="block text-sm font-medium text-gray-700">Fecha de vencimiento</label>
                        <input v-model="form.vence" type="date"
                            class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2" />
                    </div>
                    <div class="space-y-1 text-xs text-gray-500">
                        <span class="font-medium text-gray-700">Resumen</span>
                        <p class="bg-gray-50 border border-gray-200 rounded-lg px-3 py-2">
                            {{ form.target === 'producto' ? 'Promoción aplicada a producto.' : 'Promoción aplicada a proveedor.' }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button :disabled="saving" @click="submit"
                        class="rounded-lg bg-[#E4007C] hover:bg-[#cc006f] text-white px-4 py-2 text-sm disabled:opacity-60">
                        {{ form.id ? 'Actualizar promoción' : 'Crear promoción' }}
                    </button>
                    <button :disabled="!form.id || saving" @click="removePromo"
                        class="rounded-lg bg-rose-500 hover:bg-rose-600 text-white px-4 py-2 text-sm disabled:opacity-60">
                        Eliminar
                    </button>
                    <button type="button" @click="resetForm"
                        class="rounded-lg border px-4 py-2 text-sm hover:bg-gray-50">
                        Limpiar
                    </button>
                </div>
            </section>

            <!-- List section -->
            <section class="bg-white border border-gray-200 rounded-xl shadow-sm p-5 md:p-6 space-y-4">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Listado de promociones</h3>
                        <p class="text-xs text-gray-500">Haz clic para editar una promoción existente.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" v-model="activaOnly"
                                class="rounded border-gray-300 text-gray-900 focus:ring-gray-900">
                            Solo activas
                        </label>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-[1fr_auto]">
                    <div class="space-y-1">
                        <label class="block text-sm font-medium text-gray-700">Buscar</label>
                        <input v-model="search" type="text" placeholder="Producto/proveedor…"
                            class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2" />
                    </div>
                    <div class="hidden sm:flex items-end text-xs text-gray-500">
                        {{ promos.length }} resultados
                    </div>
                </div>

                <div class="border border-gray-200 rounded-lg overflow-hidden">
                    <!-- Desktop table -->
                    <table class="hidden min-w-full text-sm md:table">
                        <thead class="bg-gray-50 text-gray-500">
                            <tr>
                                <th class="text-left font-medium px-3 py-2">ID</th>
                                <th class="text-left font-medium px-3 py-2">Producto</th>
                                <th class="text-left font-medium px-3 py-2">Proveedor</th>
                                <th class="text-left font-medium px-3 py-2">Tipo</th>
                                <th class="text-left font-medium px-3 py-2">Descuento</th>
                                <th class="text-left font-medium px-3 py-2">Min. compra</th>
                                <th class="text-left font-medium px-3 py-2">Gratis</th>
                                <th class="text-left font-medium px-3 py-2">Inicia</th>
                                <th class="text-left font-medium px-3 py-2">Vence</th>
                                <th class="text-left font-medium px-3 py-2">Estado</th>
                                <th class="text-left font-medium px-3 py-2">Activa</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in promos" :key="p.id" @click="selectRow(p)"
                                :class="['cursor-pointer hover:bg-gray-50 transition', selectedId === p.id ? 'bg-gray-100' : '']">
                                <td class="px-3 py-2">{{ p.id }}</td>
                                <td class="px-3 py-2">
                                    <div class="text-gray-900">{{ p.producto ?? '—' }}</div>
                                    <div v-if="p.producto_nombre" class="text-xs text-gray-500">{{ p.producto_nombre
                                        }}</div>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="text-gray-900">{{ p.proveedor ?? '—' }}</div>
                                    <div v-if="p.proveedor_nombre" class="text-xs text-gray-500">{{ p.proveedor_nombre
                                        }}</div>
                                </td>
                                <td class="px-3 py-2 capitalize">{{ tipoLabel(p.tipo as PromoTipo) }}</td>
                                <td class="px-3 py-2">{{ p.descuento ?? '—' }}</td>
                                <td class="px-3 py-2">{{ p.mincompra ?? '—' }}</td>
                                <td class="px-3 py-2">{{ p.gratis ?? '—' }}</td>
                                <td class="px-3 py-2">{{ p.inicia ?? '—' }}</td>
                                <td class="px-3 py-2">{{ p.vence ?? '—' }}</td>
                                <td class="px-3 py-2">{{ p.estado ? 'Activa' : 'Inactiva' }}</td>
                                <td class="px-3 py-2">{{ p.activa ? 'Sí' : 'No' }}</td>
                            </tr>
                            <tr v-if="!loading && promos.length === 0">
                                <td colspan="11" class="px-3 py-3 text-center text-gray-500">Sin resultados</td>
                            </tr>
                            <tr v-if="loading">
                                <td colspan="11" class="px-3 py-3 text-center text-gray-500">Cargando…</td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Mobile cards -->
                    <div class="md:hidden divide-y divide-gray-100 max-h-[360px] overflow-auto">
                        <button v-for="p in promos" :key="p.id" @click="selectRow(p)"
                            class="w-full text-left p-3 space-y-2 transition hover:bg-gray-50"
                            :class="selectedId === p.id ? 'bg-gray-100' : 'bg-white'">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-semibold text-gray-800">Promo #{{ p.id }}</span>
                                <span class="text-xs text-gray-500">{{ p.vence || 'Sin vencimiento' }}</span>
                            </div>
                            <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-gray-600">
                                <div><span class="font-medium text-gray-700">Producto:</span> {{
                                    p.producto_nombre ||
                                    p.producto || '—' }}</div>
                                <div><span class="font-medium text-gray-700">Proveedor:</span> {{
                                    p.proveedor_nombre || p.proveedor || '—' }}</div>
                                <div><span class="font-medium text-gray-700">Tipo:</span> {{
                                    tipoLabel(p.tipo as PromoTipo) }}</div>
                                <div><span class="font-medium text-gray-700">Estado:</span> {{
                                    p.estado ? 'Activa' : 'Inactiva' }}</div>
                                <div><span class="font-medium text-gray-700">Descuento:</span> {{
                                    p.descuento ?? '—' }}</div>
                                <div><span class="font-medium text-gray-700">Bundle:</span> {{
                                    p.mincompra && p.gratis ? `${p.mincompra} x ${Number(p.mincompra) + Number(p.gratis)}` : '—'
                                    }}</div>
                            </div>
                        </button>
                        <div v-if="!loading && promos.length === 0" class="p-4 text-center text-sm text-gray-500">
                            Sin resultados
                        </div>
                        <div v-if="loading" class="p-4 text-center text-sm text-gray-500">Cargando…</div>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
