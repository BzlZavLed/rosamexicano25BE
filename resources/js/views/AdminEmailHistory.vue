<script setup lang="ts">
import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue';
import AppLayout from '../components/layout/AppLayout.vue';
import { listMailerEntries, resendMailerEmail, type MailerEntry } from '../api/mailer';
import ResendEmailModal from '../components/modals/ResendEmailModal.vue';
 
const PER_PAGE = 20;

const loading = ref(false);
const error = ref('');
const search = ref('');
const mailers = ref<MailerEntry[]>([]);
const filterStatus = ref<'all' | 'sent'>('all');
const pagination = reactive({
    page: 1,
    perPage: PER_PAGE,
    total: 0,
    lastPage: 1,
});

let searchTimer: number | undefined;

const pageInfo = computed(() => {
    if (!pagination.total) return null;
    const start = (pagination.page - 1) * pagination.perPage + 1;
    const end = Math.min(start + pagination.perPage - 1, pagination.total);
    return { start, end };
});

const filteredMailers = computed(() => {
    if (filterStatus.value === 'sent') {
        return mailers.value.filter((m) => Number(m.status) === 1);
    }
    return mailers.value;
});

const hasResults = computed(() => filteredMailers.value.length > 0);
const canPrev = computed(() => pagination.page > 1);
const canNext = computed(() => pagination.page < pagination.lastPage);

const resendModalOpen = ref(false);
const resendSaving = ref(false);
const resendError = ref('');
const resendMessage = ref('');
const resendAttachmentUrl = ref<string | null>(null);
const resendEmail = ref('');
const resendSubject = ref('');
const resendBody = ref('');

function statusLabel(status: number) {
    if (status === 1) return 'Enviado';
    if (status === 0) return 'Pendiente';
    return `Estado ${status}`;
}

function statusBadgeClass(status: number) {
    if (status === 1) return 'bg-emerald-100 text-emerald-700 border-emerald-200';
    if (status === 0) return 'bg-amber-100 text-amber-700 border-amber-200';
    return 'bg-gray-100 text-gray-600 border-gray-200';
}

function formatFecha(fecha: string) {
    if (!fecha) return '—';
    const trimmed = fecha.trim();
    if (!trimmed) return '—';

    const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;
    let normalized = trimmed.replace(' ', 'T');

    if (DATE_ONLY.test(trimmed)) {
        normalized = `${trimmed}T00:00:00`;
    } else if (!normalized.includes('T')) {
        normalized = `${normalized}T00:00:00`;
    }

    const parsed = new Date(normalized);
    if (Number.isNaN(parsed.getTime())) return fecha;

    const hasTime = /[T]\d{2}:\d{2}/.test(normalized) && !/T00:00:00$/.test(normalized);
    return parsed.toLocaleString('es-MX', hasTime
        ? { dateStyle: 'medium', timeStyle: 'short' }
        : { dateStyle: 'medium' });
}

function isValidMailUrl(mail?: string | null) {
    if (!mail) return false;
    const trimmed = mail.trim();
    if (!trimmed || trimmed.toLowerCase() === 'cobro-sin-comprobante') return false;
    try {
        const url = new URL(trimmed);
        return url.protocol === 'http:' || url.protocol === 'https:';
    } catch {
        return false;
    }
}

function scheduleFetch() {
    if (searchTimer) {
        clearTimeout(searchTimer);
        searchTimer = undefined;
    }
    searchTimer = window.setTimeout(loadMailers, 350);
}

async function loadMailers() {
    loading.value = true;
    error.value = '';
    try {
        const params: Record<string, any> = {
            page: pagination.page,
            per_page: pagination.perPage,
            sort: 'fecha_desc',
        };
        if (search.value.trim()) params.search = search.value.trim();

        const resp = await listMailerEntries(params);
        const rows = Array.isArray((resp as any)?.data)
            ? (resp as any).data
            : Array.isArray(resp)
                ? resp
                : [];

        mailers.value = [...rows].sort((a, b) => {
            const da = new Date(a.fecha).getTime();
            const db = new Date(b.fecha).getTime();
            return Number.isNaN(db - da) ? 0 : db - da;
        });

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
        error.value = e?.response?.data?.message || 'No se pudo cargar el historial de emails';
        mailers.value = [];
        pagination.total = 0;
        pagination.lastPage = 1;
    } finally {
        loading.value = false;
    }
}

function goToPage(page: number) {
    if (page < 1 || page > pagination.lastPage || page === pagination.page) return;
    pagination.page = page;
}

function openLink(url: string) {
    if (!url) return;
    window.open(url, '_blank', 'noopener,noreferrer');
}

function openResendModal(entry: MailerEntry) {
    resendEmail.value = (entry.email ?? '').trim();
    resendSubject.value = entry.asunto?.trim() || 'Documento solicitado';
    resendBody.value = entry.mensaje?.trim() || 'Adjunto documento.';
    resendAttachmentUrl.value = isValidMailUrl(entry.mail) ? entry.mail : null;
    resendError.value = '';
    resendMessage.value = '';
    resendModalOpen.value = true;
}

function closeResendModal() {
    if (resendSaving.value) return;
    resendModalOpen.value = false;
    resendAttachmentUrl.value = null;
    resendError.value = '';
    resendMessage.value = '';
}

async function submitResend(payload: { email: string; subject: string; body: string }) {
    const email = payload.email.trim();
    if (!email) {
        resendError.value = 'Ingresa un correo válido.';
        return;
    }

    resendSaving.value = true;
    resendError.value = '';
    resendMessage.value = '';
    try {
        await resendMailerEmail({
            email,
            subject: payload.subject || undefined,
            body: payload.body || undefined,
            url: resendAttachmentUrl.value || undefined,
        });
        resendMessage.value = 'Correo reenviado.';
        await loadMailers();
        if (typeof window !== 'undefined') {
            window.setTimeout(() => {
                closeResendModal();
            }, 700);
        } else {
            closeResendModal();
        }
    } catch (err: any) {
        resendError.value = err?.response?.data?.message || err?.message || 'No se pudo reenviar el correo.';
    } finally {
        resendSaving.value = false;
    }
}

watch(search, () => {
    pagination.page = 1;
    scheduleFetch();
});

watch(() => pagination.page, (newVal, oldVal) => {
    if (oldVal === undefined || newVal === oldVal) return;
    loadMailers();
});

watch(filterStatus, () => {
    pagination.page = 1;
});
onMounted(() => {
    loadMailers();
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
                    <h1 class="text-xl font-semibold text-gray-900">Historial de emails</h1>
                    <p class="text-sm text-gray-500">Consulta los comprobantes enviados por correo y descarga el PDF asociado.</p>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Filtrar por correo, asunto o mensaje…"
                        class="w-full sm:w-72 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                    />
                    <div class="flex items-center gap-2 text-xs sm:text-sm text-gray-600">
                        <label class="font-medium text-gray-700">Estado:</label>
                        <select
                            v-model="filterStatus"
                            class="rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-gray-900 focus:ring-gray-900"
                        >
                            <option value="all">Todos</option>
                            <option value="sent">Enviados</option>
                        </select>
                    </div>
                </div>
            </header>

            <section class="bg-white border border-gray-200 rounded-xl shadow-sm">
                <div class="border-b border-gray-200 px-4 py-3 flex items-center justify-between">
                    <div class="text-sm text-gray-600">
                        <span v-if="loading">Cargando historial…</span>
                        <span v-else-if="pageInfo">
                            Mostrando {{ pageInfo.start }} - {{ pageInfo.end }} de {{ pagination.total }}
                            <span class="text-xs text-gray-400">| Filtrados: {{ filteredMailers.length }}</span>
                        </span>
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
                <div class="overflow-hidden rounded-xl border border-gray-200">
                    <div class="hidden lg:block overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-gray-600 uppercase text-xs tracking-wide">
                                <tr>
                                    <th class="px-4 py-3 text-left font-medium">ID</th>
                                    <th class="px-4 py-3 text-left font-medium">Correo</th>
                                    <th class="px-4 py-3 text-left font-medium">Asunto</th>
                                    <th class="px-4 py-3 text-left font-medium">Estado</th>
                                    <th class="px-4 py-3 text-left font-medium">Fecha</th>
                                    <th class="px-4 py-3 text-left font-medium">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-if="error">
                                    <td colspan="6" class="px-4 py-6 text-center text-rose-600">{{ error }}</td>
                                </tr>
                                <tr v-else-if="!loading && !hasResults">
                                    <td colspan="6" class="px-4 py-6 text-center text-gray-500">No hay coincidencias para la búsqueda.</td>
                                </tr>
                                <tr
                                    v-for="item in filteredMailers"
                                    :key="item.id"
                                    :class="[
                                        'hover:bg-gray-50',
                                        !isValidMailUrl(item.mail) ? 'bg-amber-50/60' : ''
                                    ]"
                                >
                                    <td class="px-4 py-3 font-mono text-xs text-gray-500 whitespace-nowrap">#{{ item.id }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-700 whitespace-nowrap">{{ item.email || '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-800">
                                        <div class="max-w-[18rem] truncate" :title="item.asunto">{{ item.asunto }}</div>
                                        <div class="text-xs text-gray-500 max-w-[18rem] truncate" :title="item.mensaje">{{ item.mensaje }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span
                                            class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium"
                                            :class="statusBadgeClass(Number(item.status))"
                                        >
                                            {{ statusLabel(Number(item.status)) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">{{ formatFecha(item.fecha) }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-1 text-xs uppercase tracking-wide text-gray-500 hover:text-gray-800"
                                                :disabled="!isValidMailUrl(item.mail)"
                                                @click="isValidMailUrl(item.mail) && openLink(item.mail)"
                                            >
                                                Abrir
                                                <span aria-hidden="true">↗</span>
                                            </button>
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-1 text-xs uppercase tracking-wide text-gray-500 hover:text-gray-800"
                                                @click="openResendModal(item)"
                                            >
                                                Reenviar
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="lg:hidden divide-y divide-gray-200 bg-white">
                        <div v-if="error" class="px-4 py-4 text-sm text-rose-600">{{ error }}</div>
                        <div v-else-if="!loading && !hasResults" class="px-4 py-4 text-sm text-gray-500">No hay coincidencias para la búsqueda.</div>
                        <div
                            v-for="item in filteredMailers"
                            :key="item.id"
                            class="px-4 py-3 space-y-2 transition hover:bg-gray-50"
                            :class="!isValidMailUrl(item.mail) ? 'bg-amber-50/60' : 'bg-white'"
                        >
                            <div class="flex items-center justify-between text-xs text-gray-500">
                                <span class="font-mono">#{{ item.id }}</span>
                                <span>{{ formatFecha(item.fecha) }}</span>
                            </div>
                            <div class="text-sm font-medium text-gray-900">{{ item.asunto }}</div>
                            <div class="text-xs text-gray-500 truncate">{{ item.mensaje }}</div>
                            <div class="text-xs text-gray-600">{{ item.email || 'Sin correo registrado' }}</div>
                            <div class="flex items-center gap-2 pt-2">
                                <span
                                    class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium"
                                    :class="statusBadgeClass(Number(item.status))"
                                >
                                    {{ statusLabel(Number(item.status)) }}
                                </span>
                                <div class="ml-auto flex gap-2">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 text-xs uppercase tracking-wide text-gray-500 hover:text-gray-800"
                                        :disabled="!isValidMailUrl(item.mail)"
                                        @click="isValidMailUrl(item.mail) && openLink(item.mail)"
                                    >
                                        Abrir
                                        <span aria-hidden="true">↗</span>
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 text-xs uppercase tracking-wide text-gray-500 hover:text-gray-800"
                                        @click="openResendModal(item)"
                                    >
                                        Reenviar
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div v-if="loading" class="px-4 py-4 text-center text-sm text-gray-500">Cargando…</div>
                    </div>
                </div>
            </section>
        </div>

        <ResendEmailModal
            :open="resendModalOpen"
            :saving="resendSaving"
            :error="resendError"
            :message="resendMessage"
            :initial-email="resendEmail"
            :initial-subject="resendSubject"
            :initial-body="resendBody"
            :attachment-url="resendAttachmentUrl"
            attachment-label="Abrir comprobante"
            @close="closeResendModal"
            @submit="submitResend"
            @open-attachment="resendAttachmentUrl && openLink(resendAttachmentUrl)"
        />
    </AppLayout>
</template>
