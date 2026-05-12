<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import {
    getHostingServicePayments,
    updateHostingServicePayment,
    type HostingServicePayment,
    type HostingServicePaymentsResponse,
} from '../../api/widgets';

type MonthsOption = 3 | 6 | 9 | 12;

const monthsOptions: MonthsOption[] = [3, 6, 9, 12];
const selectedMonths = ref<MonthsOption>(3);
const loading = ref(false);
const savingIds = ref<Record<number, boolean>>({});
const error = ref('');
const data = ref<HostingServicePaymentsResponse | null>(null);
const currentDate = ref(todayIso());
let currentDateRunner: ReturnType<typeof setInterval> | null = null;

function todayIso() {
    return new Date().toISOString().slice(0, 10);
}

function formatCurrency(value: number) {
    if (!Number.isFinite(value)) return '$0.00';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
}

function formatDate(value?: string | null) {
    if (!value) return '--';
    const [year, month, day] = value.split('-').map(Number);
    if (!year || !month || !day) return value;
    return new Intl.DateTimeFormat('es-MX', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(year, month - 1, day));
}

function dateToDayNumber(value?: string | null) {
    if (!value) return Number.NaN;
    const [year, month, day] = value.split('-').map(Number);
    if (!year || !month || !day) return Number.NaN;
    return Math.floor(Date.UTC(year, month - 1, day) / 86_400_000);
}

function daysUntilDue(value?: string | null) {
    const dueDay = dateToDayNumber(value);
    const today = dateToDayNumber(currentDate.value);
    if (!Number.isFinite(dueDay) || !Number.isFinite(today)) return Number.NaN;
    return dueDay - today;
}

const pendingAmount = computed(() => {
    return (data.value?.items ?? []).reduce((sum, month) => {
        return sum + month.implementations.reduce((innerSum, payment) => {
            return innerSum + (payment.paid ? 0 : Number(payment.amount ?? 0));
        }, 0);
    }, 0);
});

const visiblePaymentsCount = computed(() => {
    return (data.value?.items ?? []).reduce((sum, month) => sum + month.implementations.length, 0);
});

const paidPaymentsCount = computed(() => {
    return (data.value?.items ?? []).reduce((sum, month) => {
        return sum + month.implementations.filter((payment) => payment.paid).length;
    }, 0);
});

const nextExpectedDueDate = computed(() => {
    const unpaid = (data.value?.items ?? [])
        .flatMap((month) => month.implementations)
        .filter((payment) => !payment.paid)
        .sort((a, b) => dateToDayNumber(a.due_date) - dateToDayNumber(b.due_date));

    return unpaid[0]?.due_date ?? null;
});

function paymentUrgency(payment: HostingServicePayment) {
    if (payment.paid) return 'paid';
    const diff = daysUntilDue(payment.due_date);
    if (!Number.isFinite(diff)) return 'normal';
    if (diff < 0) return 'overdue';
    if (diff === 0) return 'due-today';
    if (diff <= 10) return 'warning';
    return 'normal';
}

function rowClass(payment: HostingServicePayment) {
    switch (paymentUrgency(payment)) {
        case 'paid':
            return 'bg-emerald-50/40 text-gray-700';
        case 'overdue':
            return 'bg-rose-100 text-rose-950';
        case 'due-today':
            return 'bg-yellow-100 text-yellow-950';
        case 'warning':
            return 'bg-orange-100 text-orange-950';
        default:
            return 'bg-white text-gray-800';
    }
}

function paymentStatusLabel(payment: HostingServicePayment) {
    switch (paymentUrgency(payment)) {
        case 'paid':
            return 'Pagado';
        case 'overdue':
            return 'Vencido';
        case 'due-today':
            return 'Vence hoy';
        case 'warning':
            return 'Por vencer';
        default:
            return 'Pendiente';
    }
}

function paymentStatusClass(payment: HostingServicePayment) {
    switch (paymentUrgency(payment)) {
        case 'paid':
            return 'text-emerald-700';
        case 'overdue':
            return 'text-rose-800';
        case 'due-today':
            return 'text-yellow-800';
        case 'warning':
            return 'text-orange-800';
        default:
            return 'text-gray-500';
    }
}

function isNextExpected(payment: HostingServicePayment) {
    return !payment.paid && payment.due_date === nextExpectedDueDate.value;
}

function updateCurrentDate() {
    currentDate.value = todayIso();
}

async function loadPayments() {
    loading.value = true;
    error.value = '';
    try {
        data.value = await getHostingServicePayments({ months: selectedMonths.value });
    } catch (err: any) {
        error.value = err?.response?.data?.message || err?.message || 'No se pudo cargar el recordatorio de hosting.';
        data.value = null;
    } finally {
        loading.value = false;
    }
}

function replacePayment(updated: HostingServicePayment) {
    if (!data.value) return;
    data.value = {
        ...data.value,
        items: data.value.items.map((month) => {
            const implementations = month.implementations.map((payment) => {
                return payment.id === updated.id ? updated : payment;
            });
            const paidAmount = implementations
                .filter((payment) => payment.paid)
                .reduce((sum, payment) => sum + Number(payment.amount ?? 0), 0);

            return {
                ...month,
                implementations,
                paid_amount: Math.round(paidAmount * 100) / 100,
                all_paid: implementations.every((payment) => payment.paid),
            };
        }),
    };
}

async function savePayment(payment: HostingServicePayment, paid: boolean, paidAt?: string | null) {
    savingIds.value = { ...savingIds.value, [payment.id]: true };
    error.value = '';
    try {
        const updated = await updateHostingServicePayment(payment.id, {
            paid,
            paid_at: paid ? (paidAt || payment.paid_at || todayIso()) : null,
        });
        replacePayment(updated);
    } catch (err: any) {
        error.value = err?.response?.data?.message || err?.message || 'No se pudo guardar el pago de hosting.';
    } finally {
        savingIds.value = { ...savingIds.value, [payment.id]: false };
    }
}

function togglePaid(payment: HostingServicePayment, event: Event) {
    const checked = Boolean((event.target as HTMLInputElement | null)?.checked);
    savePayment(payment, checked, checked ? payment.paid_at || todayIso() : null);
}

function changePaidDate(payment: HostingServicePayment, event: Event) {
    const value = (event.target as HTMLInputElement | null)?.value || todayIso();
    savePayment(payment, true, value);
}

onMounted(() => {
    loadPayments();
    currentDateRunner = window.setInterval(updateCurrentDate, 60_000);
});

onUnmounted(() => {
    if (currentDateRunner) {
        window.clearInterval(currentDateRunner);
        currentDateRunner = null;
    }
});
</script>

<template>
    <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="space-y-1">
                <h2 class="text-base font-semibold text-gray-900">Hosting mensual</h2>
                <p class="text-sm text-gray-500">
                    Dos implementaciones activas con cuota mensual de
                    <span class="font-semibold text-gray-700">{{ formatCurrency(data?.monthly_amount_per_implementation ?? 200) }}</span>
                    cada una.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-2 text-sm">
                    <span class="text-gray-500">Total mensual</span>
                    <span class="ml-2 font-semibold text-gray-900">{{ formatCurrency(data?.monthly_total ?? 400) }}</span>
                </div>
                <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-2 text-sm">
                    <span class="text-gray-500">Pendiente visible</span>
                    <span class="ml-2 font-semibold text-rose-700">{{ formatCurrency(pendingAmount) }}</span>
                </div>
                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <span>Meses</span>
                    <select
                        v-model="selectedMonths"
                        class="rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-gray-900 focus:ring-gray-900"
                        @change="loadPayments"
                    >
                        <option v-for="months in monthsOptions" :key="months" :value="months">
                            {{ months }}
                        </option>
                    </select>
                </label>
            </div>
        </div>

        <div class="mt-4">
            <div v-if="loading" class="rounded-xl border border-gray-100 bg-gray-50 px-4 py-5 text-sm text-gray-600">
                Cargando recordatorio…
            </div>
            <div v-else-if="error" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {{ error }}
            </div>
            <div v-else-if="data?.items?.length" class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
                    <span>
                        Pagos marcados: <b class="text-gray-900">{{ paidPaymentsCount }}</b> / {{ visiblePaymentsCount }}
                    </span>
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="inline-flex items-center gap-1">
                            <span class="h-2 w-2 rounded-full bg-orange-300"></span>
                            10 dias
                        </span>
                        <span class="inline-flex items-center gap-1">
                            <span class="h-2 w-2 rounded-full bg-yellow-300"></span>
                            Hoy
                        </span>
                        <span class="inline-flex items-center gap-1">
                            <span class="h-2 w-2 rounded-full bg-rose-400"></span>
                            Vencido
                        </span>
                    </div>
                </div>

                <div class="overflow-x-auto rounded-xl border border-gray-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-3 py-2">Fecha limite</th>
                                <th class="px-3 py-2">Servicio</th>
                                <th class="px-3 py-2">Implementacion</th>
                                <th class="px-3 py-2 text-right">Monto</th>
                                <th class="px-3 py-2">Pagado</th>
                                <th class="px-3 py-2">Fecha pago</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <template v-for="month in data.items" :key="month.due_date">
                                <tr
                                    v-for="payment in month.implementations"
                                    :key="payment.id"
                                    :class="rowClass(payment)"
                                >
                                    <td class="px-3 py-2 font-medium text-gray-900">
                                        <div class="flex items-center gap-2">
                                            <span
                                                v-if="isNextExpected(payment)"
                                                class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-gray-900 text-white"
                                                title="Siguiente pago esperado"
                                            >
                                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 4v16M6 5h10l-1.5 4L16 13H6" />
                                                </svg>
                                            </span>
                                            <span>{{ formatDate(payment.due_date) }}</span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2">{{ month.service_month_label }}</td>
                                    <td class="px-3 py-2">{{ payment.implementation_name }}</td>
                                    <td class="px-3 py-2 text-right font-medium">{{ formatCurrency(payment.amount) }}</td>
                                    <td class="px-3 py-2">
                                        <label class="inline-flex items-center gap-2">
                                            <input
                                                type="checkbox"
                                                class="h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-gray-900"
                                                :checked="payment.paid"
                                                :disabled="savingIds[payment.id]"
                                                @change="togglePaid(payment, $event)"
                                            />
                                            <span class="text-xs" :class="paymentStatusClass(payment)">
                                                {{ paymentStatusLabel(payment) }}
                                            </span>
                                        </label>
                                    </td>
                                    <td class="px-3 py-2">
                                        <input
                                            type="date"
                                            class="w-36 rounded-lg border border-gray-300 px-2 py-1 text-xs focus:border-gray-900 focus:ring-gray-900 disabled:bg-gray-100"
                                            :value="payment.paid_at || ''"
                                            :disabled="savingIds[payment.id]"
                                            @change="changePaidDate(payment, $event)"
                                        />
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-else class="rounded-xl border border-gray-100 bg-gray-50 px-4 py-5 text-sm text-gray-500">
                No hay fechas programadas.
            </div>
        </div>
    </section>
</template>
