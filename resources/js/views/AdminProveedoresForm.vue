<script setup lang="ts">
import { ref, reactive, onMounted, watch, computed } from 'vue';
import AppLayout from '../components/layout/AppLayout.vue';
import {
    listProveedores,
    createProveedor,
    updateProveedor,
    deleteProveedor,
    importProveedoresCsv,
    type Proveedor,
    type ProveedorImportSummary,
} from '../api/proveedores';

const loading = ref(false);
const saving = ref(false);
const importing = ref(false);
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
const filterTipo = ref<'all' | 'normal' | 'consigna' | 'porcentaje'>('all');
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
        if (filterTipo.value !== 'all') {
            const tipo = (p.tipo ?? 'normal') as 'normal' | 'consigna' | 'porcentaje';
            if (tipo !== filterTipo.value)
                return false;
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

type ProveedorTipo = 'normal' | 'consigna' | 'porcentaje';
type BulkTipoDraft = {
    tipo: ProveedorTipo;
    importe: number | null;
    porcentaje: number | null;
    dirty: boolean;
};

const bulkTipoDrafts = reactive<Record<number, BulkTipoDraft>>({});
const bulkSaving = ref(false);
const bulkMessage = ref('');
const bulkError = ref('');
const bulkDirtyCount = computed(() => Object.values(bulkTipoDrafts)
    .filter((draft) => draft?.dirty).length);
const hasBulkDirty = computed(() => bulkDirtyCount.value > 0);

const tipoOptions = [
    { value: 'normal', label: 'Normal' },
    { value: 'consigna', label: 'Consigna' },
    { value: 'porcentaje', label: 'Por porcentaje' },
] as const;
const porcentajeOptions = [20, 30];

const importResult = ref<ProveedorImportSummary | null>(null);
const importOptions = reactive({
    updateExisting: true,
});
const MAX_IMPORT_ERRORS = 20;
const displayedImportErrors = computed(() => {
    if (!importResult.value) return [];
    return importResult.value.errors.slice(0, MAX_IMPORT_ERRORS);
});
const hiddenImportErrorCount = computed(() => {
    if (!importResult.value) return 0;
    return Math.max(0, importResult.value.errors.length - MAX_IMPORT_ERRORS);
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
    tipo: 'normal' | 'consigna' | 'porcentaje';
    porcentaje_comision: number | null;
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
    tipo: 'normal',
    porcentaje_comision: null,
});

function randIdent(): number { return Math.floor(100000 + Math.random() * 900000); }

const showImporteField = computed(() => form.tipo === 'normal');
const showPorcentajeField = computed(() => form.tipo === 'porcentaje');
const selectedProveedor = computed(() => proveedores.value.find((p) => p.id === selectedId.value) || null);
const selectedRecommendation = computed(() => selectedProveedor.value?.recommendation ?? null);


watch(() => form.tipo, (value) => {
    if (value !== 'porcentaje') {
        form.porcentaje_comision = null;
    }
    if (value !== 'normal') {
        form.importe = null;
    }
});

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
    form.tipo = 'normal';
    form.porcentaje_comision = null;
    selectedId.value = null;
    message.value = '';
    error.value = '';
}

function buildDraftFromProveedor(p: Proveedor): BulkTipoDraft {
    return {
        tipo: (p.tipo || 'normal') as ProveedorTipo,
        importe: p.importe != null ? Number(p.importe) : null,
        porcentaje: p.porcentaje_comision != null ? Number(p.porcentaje_comision) : null,
        dirty: false,
    };
}

function ensureDraft(proveedor: Proveedor): BulkTipoDraft {
    if (!bulkTipoDrafts[proveedor.id]) {
        bulkTipoDrafts[proveedor.id] = buildDraftFromProveedor(proveedor);
    }
    // Fallback to a new draft if still undefined (should not happen, but for type safety)
    return bulkTipoDrafts[proveedor.id] ?? buildDraftFromProveedor(proveedor);
}

function syncDrafts(rows: Proveedor[]) {
    const ids = new Set(rows.map((p) => p.id));
    rows.forEach((p) => {
        if (!bulkTipoDrafts[p.id] || !(bulkTipoDrafts[p.id]?.dirty)) {
            bulkTipoDrafts[p.id] = buildDraftFromProveedor(p);
        }
    });
    Object.keys(bulkTipoDrafts).forEach((key) => {
        const id = Number(key);
        if (!ids.has(id)) {
            delete bulkTipoDrafts[id];
        }
    });
}

function markDraftDirty(draft: BulkTipoDraft) {
    draft.dirty = true;
    bulkMessage.value = '';
    bulkError.value = '';
}

function handleBulkTipoChange(proveedor: Proveedor, raw: string) {
    const draft = ensureDraft(proveedor);
    const value = (raw || 'normal') as ProveedorTipo;
    draft.tipo = value;
    if (value !== 'porcentaje') {
        draft.porcentaje = null;
    }
    if (value !== 'normal') {
        draft.importe = null;
    }
    markDraftDirty(draft);
}

function handleBulkImporteChange(proveedor: Proveedor, raw: string) {
    const draft = ensureDraft(proveedor);
    const amount = raw === '' ? null : Number(raw);
    draft.importe = amount != null && Number.isFinite(amount) ? amount : null;
    markDraftDirty(draft);
}

function handleBulkPorcentajeChange(proveedor: Proveedor, raw: string) {
    const draft = ensureDraft(proveedor);
    const pct = raw === '' ? null : Number(raw);
    draft.porcentaje = pct != null && Number.isFinite(pct) ? pct : null;
    markDraftDirty(draft);
}

function resetBulkDrafts() {
    proveedores.value.forEach((p) => {
        bulkTipoDrafts[p.id] = buildDraftFromProveedor(p);
    });
    bulkMessage.value = '';
    bulkError.value = '';
}

async function saveBulkTipos() {
    const dirtyEntries = proveedores.value
        .map((provider) => {
            const draft = bulkTipoDrafts[provider.id];
            if (!draft || !draft.dirty) return null;
            return { provider, draft };
        })
        .filter((entry): entry is { provider: Proveedor; draft: BulkTipoDraft } => Boolean(entry));

    if (!dirtyEntries.length) {
        bulkError.value = 'No hay cambios pendientes.';
        return;
    }

    for (const { provider, draft } of dirtyEntries) {
        if (draft.tipo === 'normal') {
            if (draft.importe == null || !Number.isFinite(draft.importe) || draft.importe <= 0) {
                bulkError.value = `El importe es obligatorio para ${provider.nombre}.`;
                return;
            }
        }
        if (draft.tipo === 'porcentaje') {
            if (draft.porcentaje == null || !Number.isFinite(draft.porcentaje) || draft.porcentaje <= 0) {
                bulkError.value = `Define el porcentaje para ${provider.nombre}.`;
                return;
            }
        }
    }

    bulkSaving.value = true;
    bulkError.value = '';
    bulkMessage.value = '';

    try {
        for (const { provider, draft } of dirtyEntries) {
            const payload: Partial<Proveedor> = {
                tipo: draft.tipo,
                importe: draft.tipo === 'normal'
                    ? Number(draft.importe)
                    : undefined,
                porcentaje_comision: draft.tipo === 'porcentaje'
                    ? Number(draft.porcentaje)
                    : null,
            };
            await updateProveedor(provider.id, payload);
            draft.dirty = false;
        }
        bulkMessage.value = `Actualizados ${dirtyEntries.length} proveedores.`;
        await loadList();
    } catch (e: any) {
        bulkError.value = e?.response?.data?.message || 'No se pudo actualizar el tipo.';
    } finally {
        bulkSaving.value = false;
    }
}

async function loadList() {
    loading.value = true;
    try {
        const params = q.value ? { search: q.value, page: pagination.page, per_page: pagination.perPage } : { page: pagination.page, per_page: pagination.perPage };
        const data = await listProveedores(params);
        const rows = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
        proveedores.value = rows;
        syncDrafts(rows);
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
    form.tipo = (row as any).tipo || 'normal';
    form.porcentaje_comision = (row as any).porcentaje_comision ?? null;
    message.value = ''; error.value = '';
}

function useRecommendedImporte() {
    if (selectedRecommendation.value) {
        form.importe = Number(selectedRecommendation.value.recommended_importe ?? 0);
    }
}

function applyRecommendationToProveedor(proveedor: Proveedor) {
    const recommended = proveedor.recommendation?.recommended_importe;
    if (recommended == null) return;
    const draft = ensureDraft(proveedor);
    draft.importe = Number(recommended);
    draft.dirty = true;
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
            tipo: form.tipo,
            porcentaje_comision: form.porcentaje_comision,
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

async function handleImportFile(file: File) {
    const formData = new FormData();
    formData.append('file', file);
    if (!importOptions.updateExisting) {
        formData.append('update_existing', '0');
    }

    importing.value = true;
    message.value = '';
    error.value = '';

    try {
        const result = await importProveedoresCsv(formData);
        importResult.value = result;
        message.value = `Importación completada: ${result.created} nuevos, ${result.updated} actualizados, ${result.skipped} omitidos.`;
        if (result.errors.length) {
            error.value = `Se encontraron ${result.errors.length} filas con errores. Revisa el detalle debajo.`;
        }
        pagination.page = 1;
        await loadList();
    } catch (e: any) {
        importResult.value = null;
        error.value = e?.response?.data?.errors?.file?.[0]
            || e?.response?.data?.message
            || 'No se pudo importar el archivo CSV.';
    } finally {
        importing.value = false;
    }
}

async function onImportChange(event: Event) {
    const input = event.target as HTMLInputElement | null;
    const file = input?.files?.[0];
    if (!file) return;

    await handleImportFile(file);

    if (input) {
        input.value = '';
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
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" @click="resetForm"
                            class="inline-flex items-center justify-center rounded-lg border px-3 py-1.5 text-xs font-medium uppercase tracking-wide hover:bg-gray-50">
                            Limpiar formulario
                        </button>
                    </div>
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
                            <label class="block text-sm font-medium text-gray-700">Tipo de proveedor</label>
                            <select v-model="form.tipo"
                                class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2">
                                <option v-for="opt in tipoOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>
                            <p class="text-xs text-gray-500">
                                Normal: cuota mensual. Consigna: costo base diferenciado. Por porcentaje: comisión 20/30%.
                            </p>
                        </div>
                        <div class="space-y-1" v-if="showPorcentajeField">
                            <label class="block text-sm font-medium text-gray-700">Porcentaje</label>
                            <select v-model.number="form.porcentaje_comision"
                                class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2">
                                <option :value="null" disabled>Selecciona %</option>
                                <option v-for="pct in porcentajeOptions" :key="pct" :value="pct">{{ pct }}%</option>
                            </select>
                            <p class="text-xs text-gray-500">Usaremos este porcentaje para calcular el pago al proveedor.</p>
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
                                :disabled="!showImporteField"
                                class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2 disabled:bg-gray-100 disabled:text-gray-400"
                                placeholder="Solo aplica a proveedores normales" />
                            <p class="text-xs text-gray-500" v-if="!showImporteField">
                                Solo los proveedores tipo <b>normal</b> generan cobros mensuales.
                            </p>
                            <div v-else-if="selectedRecommendation" class="rounded-lg border border-dashed border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800 space-y-1">
                                <p class="font-semibold text-sm text-emerald-900">
                                    Importe sugerido: {{ formatCurrency(selectedRecommendation.recommended_importe) }}
                                </p>
                                <p class="text-[11px]">
                                    Promedio mensual {{ formatCurrency(selectedRecommendation.avg_monthly_sales) }}
                                    (ventas {{ selectedRecommendation.months }} meses · {{ selectedRecommendation.period_start ?? '?' }} — {{ selectedRecommendation.period_end ?? '?' }})
                                </p>
                                <button type="button"
                                    class="inline-flex items-center gap-1 rounded border border-emerald-600 px-3 py-1 text-[11px] font-semibold text-emerald-700 hover:bg-white"
                                    @click="useRecommendedImporte">
                                    Usar importe recomendado
                                </button>
                            </div>
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
                        <div class="flex items-center gap-2 flex-wrap">
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
                            <span class="font-medium text-gray-700">Tipo</span>
                            <select v-model="filterTipo"
                                class="rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-gray-900 focus:ring-gray-900">
                                <option value="all">Todos</option>
                                <option value="normal">Normal</option>
                                <option value="consigna">Consigna</option>
                                <option value="porcentaje">Por porcentaje</option>
                            </select>
                        </label>
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
                    </div>
                </div>

                <div class="rounded-2xl border border-rose-100 bg-rose-50/70 p-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold text-gray-900">Actualizar múltiples proveedores</p>
                            <p class="text-xs text-gray-600">Cambia el tipo y guarda todos los ajustes en un solo paso.</p>
                            <p v-if="hasBulkDirty" class="mt-1 text-[13px] font-semibold text-rose-600">{{ bulkDirtyCount }} cambios pendientes</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button"
                                class="rounded-full border border-white/70 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-60"
                                @click="resetBulkDrafts" :disabled="bulkSaving">
                                Descartar cambios
                            </button>
                            <button type="button"
                                class="rounded-full bg-rose-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-rose-500 disabled:opacity-60"
                                :disabled="!hasBulkDirty || bulkSaving" @click="saveBulkTipos">
                                <span v-if="bulkSaving">Guardando…</span>
                                <span v-else>Guardar cambios</span>
                            </button>
                        </div>
                    </div>
                    <p v-if="bulkError" class="mt-2 text-xs font-semibold text-rose-600">{{ bulkError }}</p>
                    <p v-if="bulkMessage" class="mt-2 text-xs font-semibold text-emerald-600">{{ bulkMessage }}</p>
                </div>

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
                                <th class="text-left font-medium px-3 py-2">Tipo proveedor</th>
                                <th class="text-left font-medium px-3 py-2">Importe mensual</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in visibleProveedores" :key="p.id" @click="selectRow(p)"
                                :class="['cursor-pointer hover:bg-gray-50 transition', selectedId === p.id ? 'bg-gray-100' : '', bulkTipoDrafts[p.id]?.dirty ? 'ring-1 ring-rose-200' : '']">
                                <td class="px-3 py-2">{{ p.id }}</td>
                                <td class="px-3 py-2">{{ p.ident }}</td>
                                <td class="px-3 py-2">{{ p.nombre }}</td>
                                <td class="px-3 py-2">{{ p.tel || '—' }}</td>
                                <td class="px-3 py-2">{{ p.email || '—' }}</td>
                                <td class="px-3 py-2">{{ p.ciudad || '—' }}</td>
                                <td class="px-3 py-2 text-xs">
                                    <select class="w-full rounded-lg border-gray-300 text-sm capitalize focus:border-gray-800 focus:ring-gray-800"
                                        :value="ensureDraft(p).tipo"
                                        @click.stop
                                        @change="handleBulkTipoChange(p, ($event.target as HTMLSelectElement).value)">
                                        <option value="normal">Normal</option>
                                        <option value="consigna">Consigna</option>
                                        <option value="porcentaje">Por porcentaje</option>
                                    </select>
                                    <div v-if="ensureDraft(p).tipo === 'porcentaje'" class="mt-2 flex items-center gap-1 text-[11px] text-gray-600">
                                        <input type="number" min="1" max="100" step="0.5"
                                            class="w-20 rounded border-gray-300 text-xs focus:border-gray-800 focus:ring-gray-800"
                                            :value="ensureDraft(p).porcentaje ?? ''"
                                            @click.stop
                                            @input="handleBulkPorcentajeChange(p, ($event.target as HTMLInputElement).value)" />
                                        <span>% comisión</span>
                                    </div>
                                    <p v-else-if="ensureDraft(p).tipo === 'consigna'" class="mt-1 text-[11px] text-gray-500">
                                        Margen configurado en productos.
                                    </p>
                                </td>
                                <td class="px-3 py-2 text-xs sm:text-[12px] whitespace-nowrap">
                                    <div v-if="ensureDraft(p).tipo === 'normal'" class="flex items-center gap-1">
                                        <input type="number" min="0" step="0.01"
                                            class="w-28 rounded border-gray-300 text-xs focus:border-gray-800 focus:ring-gray-800"
                                            :value="ensureDraft(p).importe ?? ''"
                                            @click.stop
                                            @input="handleBulkImporteChange(p, ($event.target as HTMLInputElement).value)" />
                                        <span class="text-gray-500">MXN</span>
                                        <button type="button"
                                            v-if="p.recommendation?.recommended_importe != null"
                                            class="group relative inline-flex h-6 w-6 items-center justify-center rounded-full border border-emerald-200 text-emerald-600 hover:bg-emerald-50"
                                            :title="`Usar ${formatCurrency(Number(p.recommendation?.recommended_importe ?? 0))}`"
                                            @click.stop="applyRecommendationToProveedor(p)">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3 w-3">
                                                <path d="M10 3.25a.75.75 0 0 1 .75.75v9.19l3.22-3.22a.75.75 0 1 1 1.06 1.06l-4.5 4.5a.75.75 0 0 1-1.06 0l-4.5-4.5a.75.75 0 1 1 1.06-1.06l3.22 3.22V4a.75.75 0 0 1 .75-.75Z" />
                                            </svg>
                                            <div class="pointer-events-none absolute z-10 hidden transform translate-y-full right-0 px-3 py-1 text-[11px] text-left text-white bg-gray-900 rounded-md shadow-lg group-hover:block">
                                                Importe basado en {{ p.recommendation?.percentage_used ?? 5 }}% de las ventas de los últimos. Calculado solo para proveedores existentes y con ventas en los ultimos {{ p.recommendation?.months_window ?? p.recommendation?.months ?? 12 }}
                                            </div>
                                        </button>
                                    </div>
                                    <span v-else class="text-gray-400">No requerido</span>
                                </td>
                            </tr>
                            <tr v-if="!loading && visibleProveedores.length === 0">
                                <td colspan="8" class="px-3 py-3 text-center text-gray-500">Sin resultados</td>
                            </tr>
                            <tr v-if="loading">
                                <td colspan="8" class="px-3 py-3 text-center text-gray-500">Cargando…</td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Mobile cards -->
                    <div class="md:hidden divide-y divide-gray-100 max-h-80 overflow-auto">
                        <div v-for="p in visibleProveedores" :key="p.id"
                            class="w-full text-left p-3 space-y-3 transition hover:bg-gray-50"
                            :class="[selectedId === p.id ? 'bg-gray-100' : 'bg-white', bulkTipoDrafts[p.id]?.dirty ? 'ring-1 ring-rose-200' : '']">
                            <button type="button" class="flex w-full items-center justify-between text-left"
                                @click="selectRow(p)">
                                <span class="text-sm font-semibold text-gray-800">{{ p.nombre }}</span>
                                <span class="text-xs text-gray-500">#{{ p.ident }}</span>
                            </button>
                            <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-gray-600">
                                <div><span class="font-medium text-gray-700">Tel:</span> {{ p.tel || '—' }}</div>
                                <div><span class="font-medium text-gray-700">Email:</span> {{ p.email || '—' }}</div>
                                <div><span class="font-medium text-gray-700">Ciudad:</span> {{ p.ciudad || '—' }}</div>
                                <div><span class="font-medium text-gray-700">Banco:</span> {{ p.sucursal || '—' }}</div>
                            </div>
                            <div class="space-y-2 text-xs text-gray-700">
                                <label class="block text-[11px] font-semibold uppercase text-gray-500">Tipo de proveedor</label>
                                <select class="w-full rounded-lg border-gray-300 text-sm capitalize focus:border-gray-800 focus:ring-gray-800"
                                    :value="ensureDraft(p).tipo"
                                    @change="handleBulkTipoChange(p, ($event.target as HTMLSelectElement).value)">
                                    <option value="normal">Normal</option>
                                    <option value="consigna">Consigna</option>
                                    <option value="porcentaje">Por porcentaje</option>
                                </select>
                                <div v-if="ensureDraft(p).tipo === 'porcentaje'" class="flex items-center gap-2">
                                    <input type="number" min="1" max="100" step="0.5"
                                        class="w-24 rounded border-gray-300 text-xs focus:border-gray-800 focus:ring-gray-800"
                                        :value="ensureDraft(p).porcentaje ?? ''"
                                        @input="handleBulkPorcentajeChange(p, ($event.target as HTMLInputElement).value)" />
                                    <span>% comisión</span>
                                </div>
                                <p v-else-if="ensureDraft(p).tipo === 'consigna'" class="text-[11px] text-gray-500">Margen definido en productos.</p>
                            </div>
                            <div class="space-y-1 text-xs text-gray-700">
                                <label class="block text-[11px] font-semibold uppercase text-gray-500">Importe mensual</label>
                                <div v-if="ensureDraft(p).tipo === 'normal'" class="flex items-center gap-2">
                                    <input type="number" min="0" step="0.01"
                                        class="w-28 rounded border-gray-300 text-xs focus:border-gray-800 focus:ring-gray-800"
                                        :value="ensureDraft(p).importe ?? ''"
                                        @input="handleBulkImporteChange(p, ($event.target as HTMLInputElement).value)" />
                                    <span>MXN</span>
                                    <button type="button"
                                        v-if="p.recommendation?.recommended_importe != null"
                                        class="group relative inline-flex h-6 w-6 items-center justify-center rounded-full border border-emerald-200 text-emerald-600 hover:bg-emerald-50"
                                        :title="`Usar ${formatCurrency(Number(p.recommendation?.recommended_importe ?? 0))}`"
                                        @click="applyRecommendationToProveedor(p)">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3 w-3">
                                            <path d="M10 3.25a.75.75 0 0 1 .75.75v9.19l3.22-3.22a.75.75 0 1 1 1.06 1.06l-4.5 4.5a.75.75 0 0 1-1.06 0l-4.5-4.5a.75.75 0 1 1 1.06-1.06l3.22 3.22V4a.75.75 0 0 1 .75-.75Z" />
                                        </svg>
                                        <div class="pointer-events-none absolute z-10 hidden transform translate-y-full right-0 px-3 py-1 text-[11px] text-left text-white bg-gray-900 rounded-md shadow-lg group-hover:block">
                                            Importe basado en {{ p.recommendation?.percentage_used ?? 5 }}% de las ventas de los últimos
                                            {{ p.recommendation?.months_window ?? p.recommendation?.months ?? 12 }} meses.
                                        </div>
                                    </button>
                                </div>
                                <span v-else class="text-gray-500">No requerido</span>
                            </div>
                        </div>
                        <div v-if="!loading && visibleProveedores.length === 0" class="p-4 text-center text-sm text-gray-500">
                            Sin resultados
                        </div>
                        <div v-if="loading" class="p-4 text-center text-sm text-gray-500">Cargando…</div>
                    </div>
                </div>
            </section>

            <section class="bg-white border border-gray-200 rounded-xl shadow-sm p-5 md:p-6 space-y-4">
                <label class="block text-sm font-medium text-gray-700">Subir archivo de proveedores (CSV)</label>
                <div class="flex flex-col gap-3 text-xs text-gray-600 sm:flex-row sm:items-center sm:justify-between">
                    <span>
                        Importa un CSV para crear o actualizar proveedores en lote. Usa la misma estructura exportada desde el sistema.
                    </span>
                    <label class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 text-xs text-gray-600">
                        <input type="checkbox" v-model="importOptions.updateExisting"
                            class="h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-gray-900">
                        <span>Actualizar existentes</span>
                    </label>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <label
                        class="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 cursor-pointer disabled:opacity-60"
                        :class="{ 'opacity-60': importing }">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="h-4 w-4 text-gray-500"
                            aria-hidden="true">
                            <path fill="currentColor"
                                d="M10 2a1 1 0 0 1 .96.73L12 6h4a1 1 0 0 1 .78 1.63l-8 10a1 1 0 0 1-1.75-.8L7.9 12H4a1 1 0 0 1-.78-1.63l5.68-7.36A1 1 0 0 1 10 2Z" />
                        </svg>
                        <span>{{ importing ? 'Importando…' : 'Seleccionar archivo CSV' }}</span>
                        <input type="file" accept=".csv,text/csv" class="sr-only" @change="onImportChange">
                    </label>
                    <span class="text-xs text-gray-500">El archivo se procesa automáticamente al seleccionarlo. Máx. 5MB.</span>
                </div>
                <div v-if="importResult && importResult.errors.length"
                    class="rounded-lg border border-amber-200 bg-amber-50 text-amber-800 px-4 py-3 text-xs space-y-1">
                    <p class="font-semibold">Errores en la importación (mostrando {{ displayedImportErrors.length }} de {{ importResult.errors.length }}):</p>
                    <ul class="list-disc list-inside space-y-1">
                        <li v-for="err in displayedImportErrors" :key="`${err.line}-${err.message}`">
                            Fila {{ err.line }}: {{ err.message }}
                        </li>
                    </ul>
                    <p v-if="hiddenImportErrorCount > 0" class="text-amber-700">
                        …y {{ hiddenImportErrorCount }} errores adicionales.
                    </p>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
