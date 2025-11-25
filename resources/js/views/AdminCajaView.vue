<script setup lang="ts">
/**
 * AdminCajaView renders the point-of-sale interface for cashiers.
 * It centralizes cart management, promotions, checkout, and manual expense logging.
 */
import { fetchActivePromosFor, type Promo } from '../api/promocionescaja';
import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue';
import AppLayout from '../components/layout/AppLayout.vue';
import { getSystemSettings } from '../api/settings';
import {
    cajaStatus,
    cajaOpen,
    cajaClose,
    findProduct,
    checkout,
    sendSaleTicket,
    registerExpense,
    type CashMethod,
    type RegisterExpensePayload,
    type CheckoutLinePayload,
    type CheckoutPayload
} from '../api/cashier';
import { searchClientes, createCliente, type Cliente } from '../api/clientes';
import { jsPDF } from 'jspdf';

type Producto = {
    id: number;
    ident: number;
    nombre: string;
    descripcion?: string;
    precio: number;
    precio_proveedor?: number;
    proveedorid: number;
    proveedor?: {
        id: number;
        ident?: number;
        nombre: string;
        tipo?: 'normal' | 'consigna' | 'porcentaje';
        porcentaje_comision?: number;
    };
    inventario?: { existencia: number; importe: number };
};

type CartRow = {
    ident: number;
    nombre: string;
    precio: number;
    existencia: number;
    qty: number;
    proveedorname?: string;
    proveedorid?: number;
    proveedorTipo?: 'normal' | 'consigna' | 'porcentaje';
    proveedorPct?: number | null;
    precioProveedor?: number;
    promoDiscountPct?: number;
    promoFreeQty?: number;
    promoNote?: string;
    manualDiscount?: number;
    manualDiscountPercent?: number;
};

type SaleSnapshot = {
    idventa: number;
    fecha: string;
    rows: CartRow[];
    subtotal: number;
    promoDiscount: number;
    manualDiscount: number;
    manualItemDiscount: number;
    totalDiscount: number;
    afterDiscount: number;
    surchargePercent: number;
    surchargeAmount: number;
    total: number;
    paymentMethod: CashMethod;
    cashReceived: number;
    change: number;
    providerSurcharge: Array<{ proveedor_id: number; nombre: string; amount: number; percent: number }>;
    providerNetTotals: Array<{
        proveedor_id: number;
        nombre: string;
        proveedor_tipo: 'normal' | 'consigna' | 'porcentaje';
        proveedor_pct: number | null;
        total: number;
        public_total: number;
        proveedor_bruto: number;
        proveedor_descuento: number;
        provider_card_charge: number;
        admin_ganancia: number;
        line_items: Array<{
            nombre: string;
            qty: number;
            precio_publico: number;
            precio_proveedor: number;
        }>;
    }>;
};

// Reactive state -------------------------------------------------
const loading = ref(false);
const saving = ref(false);
const message = ref('');
const error = ref('');
const ALERT_TIMEOUT_MS = 5000;
let messageTimer: number | undefined;
let errorTimer: number | undefined;

const caja = ref<{ open: boolean; caja: any | null }>({ open: false, caja: null });

const query = ref('');
const results = ref<Producto[]>([]);
const SHOW_RESULTS = ref(false);
let searchTimer: number | undefined;

const scanActive = ref(false);
const scanBuffer = ref('');
let scanTimer: number | undefined;

const cart = ref<CartRow[]>([]);

// Expense modal state -------------------------------------------------------
const showExpenseModal = ref(false);
const expenseSaving = ref(false);
const expenseError = ref('');
const expenseForm = reactive({
    concepto: '',
    total: null as number | null,
    fecha: todayISO(),
});

const lastSaleSnapshot = ref<SaleSnapshot | null>(null);
const showTicketModal = ref(false);
const ticketSendChannel = ref<'email' | 'sms'>('email');
const ticketSending = ref(false);
const clientSearch = ref('');
const clientSuggestions = ref<Cliente[]>([]);
const clientSearchLoading = ref(false);
const clientSelection = ref<Cliente | null>(null);
const clientForm = reactive({ nombre: '', email: '', telefono: '' });
const clientSaving = ref(false);
const clientMessage = ref('');
const clientError = ref('');
let clientSearchTimer: number | undefined;
const clientSearchNoResults = ref(false);

const paymentMethod = ref<CashMethod>('efectivo');
const cashReceived = ref<number | null>(null);

// Cash drawer amounts -------------------------------------------------------
const openAmount = ref<number | null>(null);
const lastClosingBalance = ref<number | null>(null);
const openAmountPlaceholder = computed(() => {
    if (lastClosingBalance.value !== null) {
        return `Saldo inicial (último ${currency(Number(lastClosingBalance.value))})`;
    }
    return 'Saldo inicial';
});
const closeAmount = ref<number | null>(null);
const closeAmountSuggestion = ref<number | null>(null);
const closeAmountTouched = ref(false);

// Derived state & constants --------------------------------------
/** Memoizes promotions per product to avoid redundant API requests during cart edits. */
const promoCache = new Map<number, Promo[]>();
const clamp = (n: number, min: number, max: number) => Math.max(min, Math.min(max, n));

const linePromoDiscount = (row: CartRow) => {
    const percent = (row.promoDiscountPct ?? 0) / 100;
    const percentDiscount = row.precio * row.qty * percent;
    const bundleDiscount = row.precio * (row.promoFreeQty ?? 0);
    return Math.round((percentDiscount + bundleDiscount) * 100) / 100;
};

const lineGross = (row: CartRow) => Math.max(0, row.precio * row.qty);

function computeManualPercent(row: CartRow) {
    const base = lineGross(row);
    if (base <= 0) return 0;
    const percent = ((Number(row.manualDiscount ?? 0) || 0) / base) * 100;
    const clamped = Math.min(80, Math.max(0, percent));
    return Math.round(clamped * 10) / 10;
}

function clampManualDiscount(row: CartRow) {
    const promoDiscount = linePromoDiscount(row);
    const maxDiscount = Math.max(0, lineGross(row) - promoDiscount);
    const manual = Math.max(0, Math.min(Number(row.manualDiscount ?? 0) || 0, maxDiscount));
    row.manualDiscount = Math.round(manual * 100) / 100;
    const percent = computeManualPercent(row);
    row.manualDiscountPercent = percent > 0 ? percent : null;
    return row.manualDiscount;
}

const lineManualDiscount = (row: CartRow) => Math.max(0, Number(row.manualDiscount ?? 0));

function applyManualDiscountPercent(row: CartRow, percent: number) {
    if (!Number.isFinite(percent) || percent <= 0) {
        row.manualDiscountPercent = null;
        row.manualDiscount = 0;
        onManualDiscountChange(row);
        return;
    }
    const safePercent = Math.min(Math.max(percent, 0), 80);
    row.manualDiscountPercent = safePercent;
    const base = lineGross(row);
    if (base <= 0) {
        row.manualDiscountPercent = null;
        row.manualDiscount = 0;
        onManualDiscountChange(row);
        return;
    }
    row.manualDiscount = Math.round(base * (safePercent / 100) * 100) / 100;
    onManualDiscountChange(row);
}

function onManualPercentInput(row: CartRow) {
    const percent = Number(row.manualDiscountPercent ?? 0);
    if (!Number.isFinite(percent) || percent <= 0) {
        row.manualDiscountPercent = null;
        row.manualDiscount = 0;
        onManualDiscountChange(row);
        return;
    }
    applyManualDiscountPercent(row, percent);
}

const lineNet = (row: CartRow) =>
    Math.max(0, lineGross(row) - linePromoDiscount(row) - lineManualDiscount(row));

/** Raw total before discounts or surcharges. */
const subTotal = computed(() => cart.value.reduce((sum, row) => sum + row.precio * row.qty, 0));

/** Sum of all promo-related discounts (percent and freebies). */
const promoDiscountAmount = computed(() =>
    Math.round(cart.value.reduce((sum, row) => sum + linePromoDiscount(row), 0) * 100) / 100
);

const manualItemDiscountAmount = computed(() =>
    Math.round(cart.value.reduce((sum, row) => sum + lineManualDiscount(row), 0) * 100) / 100
);

const totalDiscountAmount = computed(() =>
    promoDiscountAmount.value + manualItemDiscountAmount.value
);

/** Net total after discounts but before card surcharge is applied. */
const afterDiscount = computed(() => Math.max(0, subTotal.value - totalDiscountAmount.value));
/** Surcharge only applies to tarjeta operations; currently a fixed 4.5%. */
const cardChargePercent = ref(4.5);
const cardChargeRate = computed(() => cardChargePercent.value / 100);
const surchargePercent = computed(() => (paymentMethod.value === 'tarjeta' ? cardChargePercent.value : 0));
const round2 = (value: number) => Math.round((value + Number.EPSILON) * 100) / 100;

function calculateProviderBruto(
    type: CartRow['proveedorTipo'],
    gross: number,
    qty: number,
    providerUnitCost: number,
    percent?: number | null
) {
    switch (type) {
        case 'consigna':
            return round2(providerUnitCost * qty);
        case 'porcentaje': {
            const pct = percent ?? 0;
            const share = 1 - Math.max(0, Math.min(100, pct)) / 100;
            return round2(gross * share);
        }
        default:
            return gross;
    }
}

function distributeSurchargeTotals(
    providerTotals: Map<number, number>,
    base: number,
    surchargeTotal: number
) {
    const charges = new Map<number, number>();
    if (base <= 0 || surchargeTotal <= 0) {
        return charges;
    }

    const entries = Array.from(providerTotals.entries());
    let remaining = surchargeTotal;
    entries.forEach(([providerId, total], index) => {
        if (total <= 0) {
            charges.set(providerId, 0);
            return;
        }
        if (index === entries.length - 1) {
            const portion = round2(remaining);
            charges.set(providerId, portion);
            remaining -= portion;
        } else {
            const portion = round2(surchargeTotal * (total / base));
            charges.set(providerId, portion);
            remaining -= portion;
        }
    });

    if (Math.abs(remaining) >= 0.01 && entries.length) {
        const lastEntry = entries[entries.length - 1];
        if (lastEntry) {
            const [lastProviderId] = lastEntry;
            charges.set(lastProviderId, round2((charges.get(lastProviderId) ?? 0) + remaining));
        }
    }

    return charges;
}

const providerFinancialSummary = computed(() => {
    const providers = new Map<number, {
        proveedor_id: number;
        nombre: string;
        proveedor_tipo: 'normal' | 'consigna' | 'porcentaje';
        proveedor_pct: number | null;
        public_total: number;
        proveedor_bruto: number;
        proveedor_descuento: number;
        admin_ganancia: number;
        cantidad: number;
        line_items: Array<{
            nombre: string;
            qty: number;
            precio_publico: number;
            precio_proveedor: number;
        }>;
    }>();
    let grossSubtotal = 0;

    for (const row of cart.value) {
        const proveedorId = Number(row.proveedorid ?? 0);
        if (!proveedorId) continue;
        const qty = Math.max(0, row.qty);
        if (!qty) continue;

        const gross = round2(row.precio * qty);
        const discount = Math.min(
            gross,
            round2(linePromoDiscount(row) + lineManualDiscount(row))
        );
        const providerType = row.proveedorTipo ?? 'normal';
        const providerPct = providerType === 'porcentaje' ? row.proveedorPct ?? 0 : null;
        const providerUnitCost = Number(row.precioProveedor ?? row.precio);
        const precioProveedor = providerUnitCost;
        const providerBruto = calculateProviderBruto(
            providerType,
            gross,
            qty,
            providerUnitCost,
            providerPct
        );
        const providerManual = Math.min(providerBruto, discount);
        const adminMarkup = Math.max(0, round2(gross - providerBruto));

        grossSubtotal += gross;

        const current = providers.get(proveedorId) ?? {
            proveedor_id: proveedorId,
            nombre: row.proveedorname ?? `Proveedor ${proveedorId}`,
            proveedor_tipo: providerType,
            proveedor_pct: providerPct,
            public_total: 0,
            proveedor_bruto: 0,
            proveedor_descuento: 0,
            admin_ganancia: 0,
            cantidad: 0,
            line_items: [],
        };

        current.public_total = round2(current.public_total + gross);
        current.proveedor_bruto = round2(current.proveedor_bruto + providerBruto);
        current.proveedor_descuento = round2(current.proveedor_descuento + providerManual);
        current.admin_ganancia = round2(current.admin_ganancia + adminMarkup);
        current.cantidad += qty;
        current.line_items.push({
            nombre: row.nombre,
            qty,
            precio_publico: Number(row.precio),
            precio_proveedor: precioProveedor,
        });
        if (providerType === 'porcentaje') {
            current.proveedor_pct = providerPct;
        }
        providers.set(proveedorId, current);
    }

    grossSubtotal = round2(grossSubtotal);
    const surchargeTotal =
        paymentMethod.value === 'tarjeta' ? round2(grossSubtotal * cardChargeRate.value) : 0;

    const publicTotals = new Map<number, number>();
    providers.forEach((entry, pid) => {
        publicTotals.set(pid, entry.public_total);
    });
    const chargesMap = distributeSurchargeTotals(publicTotals, grossSubtotal, surchargeTotal);

    const providerList = Array.from(providers.values())
        .map((entry) => {
            const cardCharge = round2(chargesMap.get(entry.proveedor_id) ?? 0);
            const providerNet = round2(
                Math.max(0, entry.proveedor_bruto - entry.proveedor_descuento - cardCharge)
            );
            const percent =
                grossSubtotal > 0
                    ? round2((entry.public_total / grossSubtotal) * 100)
                    : 0;
            return {
                ...entry,
                provider_card_charge: cardCharge,
                provider_net: providerNet,
                percent,
            };
        })
        .sort((a, b) => b.public_total - a.public_total);

    return {
        grossSubtotal,
        surchargeTotal,
        providers: providerList,
    };
});

const providerSurchargeList = computed(() => {
    const summary = providerFinancialSummary.value;
    return summary.providers
        .filter((item) => item.provider_card_charge > 0)
        .map((item) => ({
            proveedor_id: item.proveedor_id,
            nombre: item.nombre,
            amount: item.provider_card_charge,
            percent: item.percent,
        }));
});

const providerNetTotalsList = computed(() =>
    providerFinancialSummary.value.providers.map((item) => ({
        proveedor_id: item.proveedor_id,
        nombre: item.nombre,
        proveedor_tipo: item.proveedor_tipo,
        proveedor_pct: item.proveedor_pct ?? null,
        total: item.provider_net,
        public_total: item.public_total,
        proveedor_bruto: item.proveedor_bruto,
        proveedor_descuento: item.proveedor_descuento,
        provider_card_charge: item.provider_card_charge,
        admin_ganancia: item.admin_ganancia,
        line_items: item.line_items,
    }))
);

const surchargeAmount = computed(() => providerFinancialSummary.value.surchargeTotal);
const total = computed(() => {
    const base = Math.round(afterDiscount.value * 100) / 100;
    if (paymentMethod.value === 'tarjeta') {
        return base;
    }
    return Math.round((afterDiscount.value + surchargeAmount.value) * 100) / 100;
});

const changeDue = computed(() => {
    if (paymentMethod.value !== 'efectivo') return 0;
    const received = Number(cashReceived.value ?? 0);
    return Math.max(0, Math.round((received - total.value) * 100) / 100);
});

const expandedProviders = ref<Set<number>>(new Set());
function toggleProviderDetail(proveedorId: number) {
    const next = new Set(expandedProviders.value);
    if (next.has(proveedorId)) {
        next.delete(proveedorId);
    } else {
        next.add(proveedorId);
    }
    expandedProviders.value = next;
}
const isProviderExpanded = (proveedorId: number) => expandedProviders.value.has(proveedorId);

const showProviderBreakdown = ref(true);

const providerTypeStyles: Record<'normal' | 'consigna' | 'porcentaje', { icon: string; label: string; className: string }> = {
    normal: { icon: '🛒', label: 'Normal', className: 'bg-gray-100 text-gray-700' },
    consigna: { icon: '📦', label: 'Consigna', className: 'bg-amber-100 text-amber-700' },
    porcentaje: { icon: '％', label: 'Porcentaje', className: 'bg-indigo-100 text-indigo-700' },
};
function providerTypeInfo(type: CartRow['proveedorTipo'] = 'normal', percent?: number | null) {
    const base = providerTypeStyles[type ?? 'normal'] ?? providerTypeStyles.normal;
    const label =
        (type === 'porcentaje' && percent != null)
            ? `${base.label} (${percent}%)`
            : base.label;
    return { ...base, label };
}

// Functions ------------------------------------------------------
function todayISO() {
    return new Date().toISOString().slice(0, 10);
}

function resetExpenseForm() {
    expenseForm.concepto = '';
    expenseForm.total = null;
    expenseForm.fecha = todayISO();
}

function currency(amount: number, currencyCode = 'MXN', locale = 'es-MX') {
    try {
        return new Intl.NumberFormat(locale, { style: 'currency', currency: currencyCode }).format(amount);
    } catch {
        return `$${amount.toFixed(2)}`;
    }
}

/** Utility helpers to manage transient success/error banners. */
function clearMessage() {
    if (messageTimer) clearTimeout(messageTimer);
    messageTimer = undefined;
    message.value = '';
}

function clearError() {
    if (errorTimer) clearTimeout(errorTimer);
    errorTimer = undefined;
    error.value = '';
}

function resetAlerts() {
    clearMessage();
    clearError();
}

function showMessage(text: string) {
    clearMessage();
    message.value = text;
    messageTimer = window.setTimeout(() => {
        clearMessage();
    }, ALERT_TIMEOUT_MS);
}

function showError(text: string) {
    //clearError();
    error.value = text;
    errorTimer = window.setTimeout(() => {
        error.value = '';
        errorTimer = undefined;
    }, ALERT_TIMEOUT_MS);
}

function clearTicketState() {
    if (clientSearchTimer) {
        clearTimeout(clientSearchTimer);
        clientSearchTimer = undefined;
    }
    ticketSending.value = false;
    clientSearch.value = '';
    clientSuggestions.value = [];
    clientSelection.value = null;
    clientForm.nombre = '';
    clientForm.email = '';
    clientForm.telefono = '';
    clientMessage.value = '';
    clientError.value = '';
    clientSaving.value = false;
    clientSearchNoResults.value = false;
}

function openTicketModal(snapshot: SaleSnapshot) {
    lastSaleSnapshot.value = snapshot;
    ticketSendChannel.value = 'email';
    clearTicketState();
    showTicketModal.value = true;
}

function closeTicketModal() {
    showTicketModal.value = false;
    clearTicketState();
    lastSaleSnapshot.value = null;
}

function applyClientSuggestion(cliente: Cliente) {
    clientSelection.value = cliente;
    clientForm.nombre = cliente?.nombre ?? '';
    clientForm.email = cliente?.email ?? '';
    clientForm.telefono = cliente?.telefono ?? '';
    clientSearch.value = cliente?.nombre ?? '';
    clientSuggestions.value = [];
    clientSearchNoResults.value = false;
}

function resetClientForm() {
    clientSelection.value = null;
    clientForm.nombre = '';
    clientForm.email = '';
    clientForm.telefono = '';
    clientSearch.value = '';
    clientSuggestions.value = [];
    clientMessage.value = '';
    clientError.value = '';
    clientSearchNoResults.value = false;
}

function selectClientSuggestion(cliente: Cliente) {
    applyClientSuggestion(cliente);
}

function captureSaleSnapshot(ventaId: number, sourceRows: CartRow[] = cart.value): SaleSnapshot {
    const surchargeBreakdown = providerSurchargeList.value.map((item) => ({
        proveedor_id: item.proveedor_id,
        nombre: item.nombre,
        amount: item.amount,
        percent: item.percent,
    }));
const providerNet = providerNetTotalsList.value.map((item) => ({
        proveedor_id: item.proveedor_id,
        nombre: item.nombre,
        proveedor_tipo: item.proveedor_tipo,
        proveedor_pct: item.proveedor_pct,
        total: item.total,
        public_total: item.public_total,
        proveedor_bruto: item.proveedor_bruto,
        proveedor_descuento: item.proveedor_descuento,
        provider_card_charge: item.provider_card_charge,
        admin_ganancia: item.admin_ganancia,
        line_items: item.line_items,
    }));

    return {
        idventa: ventaId,
        fecha: new Date().toISOString().slice(0, 10),
        rows: sourceRows.map(row => ({ ...row })),
        subtotal: subTotal.value,
        promoDiscount: promoDiscountAmount.value,
        manualDiscount: manualItemDiscountAmount.value,
        manualItemDiscount: manualItemDiscountAmount.value,
        totalDiscount: totalDiscountAmount.value,
        afterDiscount: afterDiscount.value,
        surchargePercent: surchargePercent.value,
        surchargeAmount: surchargeAmount.value,
        total: total.value,
        paymentMethod: paymentMethod.value,
        cashReceived: paymentMethod.value === 'efectivo' ? Number(cashReceived.value ?? 0) : total.value,
        change: paymentMethod.value === 'efectivo' ? changeDue.value : 0,
        providerSurcharge: surchargeBreakdown,
        providerNetTotals: providerNet,
    };
}

async function saveCliente() {
    clientError.value = '';
    clientMessage.value = '';
    const nombre = clientForm.nombre.trim();
    const email = clientForm.email.trim();
    const telefono = clientForm.telefono.trim();

    if (!nombre) {
        clientError.value = 'Nombre del cliente requerido';
        return;
    }
    if (ticketSendChannel.value === 'email' && !email) {
        clientError.value = 'Correo electrónico requerido para enviar ticket por email';
        return;
    }
    if (ticketSendChannel.value === 'sms' && !telefono) {
        clientError.value = 'Teléfono requerido para enviar ticket por SMS';
        return;
    }

    clientSaving.value = true;
    try {
        const payload = { nombre, email: email || null, telefono: telefono || null };
        const saved = await createCliente(payload);
        const cliente = saved?.data ?? saved;
        applyClientSuggestion({
            id: cliente?.id ?? clientSelection.value?.id ?? 0,
            nombre: cliente?.nombre ?? nombre,
            email: cliente?.email ?? payload.email ?? '',
            telefono: cliente?.telefono ?? payload.telefono ?? '',
        });
        clientMessage.value = 'Cliente guardado';
    } catch (e: any) {
        clientError.value = e?.response?.data?.message || 'No se pudo guardar cliente';
    } finally {
        clientSaving.value = false;
    }
}

async function sendTicket() {
    clientError.value = '';
    clientMessage.value = '';
    const snapshot = lastSaleSnapshot.value;
    if (!snapshot) {
        clientError.value = 'No hay ticket para enviar';
        return;
    }

    const nombre = clientForm.nombre.trim();
    const email = clientForm.email.trim();
    const telefono = clientForm.telefono.trim();

    if (!nombre) {
        clientError.value = 'Nombre del cliente requerido';
        return;
    }

    if (ticketSendChannel.value === 'email') {
        if (!email) {
            clientError.value = 'Proporciona un correo electrónico válido';
            return;
        }
    } else {
        if (!telefono) {
            clientError.value = 'Proporciona un número de teléfono válido';
            return;
        }
    }

    ticketSending.value = true;
    try {
        let ticketPdfBase64: string | undefined;
        if (ticketSendChannel.value === 'email') {
            const doc = buildReceiptPDF(snapshot);
            const dataUri = doc.output('datauristring');
            ticketPdfBase64 = dataUri.includes(',') ? dataUri.split(',')[1] : dataUri;
        }
        await sendSaleTicket({
            venta_id: snapshot.idventa,
            canal: ticketSendChannel.value,
            cliente: {
                nombre,
                email: email || null,
                telefono: telefono || null,
            },
            ticket_pdf_base64: ticketPdfBase64,
        });

        const successText = ticketSendChannel.value === 'email'
            ? `Ticket enviado a ${email}`
            : `Ticket enviado vía SMS a ${telefono}`;
        showMessage(successText);
        closeTicketModal();
    } catch (e: any) {
        console.log('Error sending ticket:', e);
        clientError.value = e?.response?.data?.message || 'No se pudo enviar el ticket';
    } finally {
        ticketSending.value = false;
    }
}

function onPrintTicket() {
    const snapshot = lastSaleSnapshot.value;
    if (!snapshot) return;
    makeReceiptPDFFromSale(snapshot);
    closeTicketModal();
}

watch(clientSearch, (val) => {
    if (!showTicketModal.value) return;
    const raw = val ?? '';
    const trimmed = raw.trim();
    clientForm.nombre = raw;
    if (clientSearchTimer) {
        clearTimeout(clientSearchTimer);
        clientSearchTimer = undefined;
    }
    clientError.value = '';
    clientSearchNoResults.value = false;
    if (!trimmed) {
        clientSuggestions.value = [];
        return;
    }
    if (trimmed.length < 2) {
        clientSuggestions.value = [];
        return;
    }
    const term = trimmed;
    clientSearchTimer = window.setTimeout(async () => {
        clientSearchLoading.value = true;
        try {
            const resp = await searchClientes({ search: term, limit: 8 });
            const rows = Array.isArray(resp?.data) ? resp.data : (Array.isArray(resp) ? resp : []);
            if (clientSearch.value.trim() !== term) return;
            clientSuggestions.value = rows;
            clientSearchNoResults.value = term.length >= 5 && rows.length === 0;
        } catch (e: any) {
            clientError.value = e?.response?.data?.message || 'No se pudo buscar clientes';
            clientSearchNoResults.value = false;
        } finally {
            clientSearchLoading.value = false;
        }
    }, 350);
});

async function getPromosForRow(row: CartRow, proveedorIdent?: number) {
    if (promoCache.has(row.ident)) return promoCache.get(row.ident)!;
    const promos = await fetchActivePromosFor(row.ident, proveedorIdent);
    promoCache.set(row.ident, promos);
    return promos;
}

async function applyPromotionsToRow(row: CartRow, proveedorIdent?: number) {
    row.promoDiscountPct = 0;
    row.promoFreeQty = 0;
    row.promoNote = undefined;

    const promos = await getPromosForRow(row, proveedorIdent);
    if (!promos.length) return;

    const productPromos = promos.filter(p => p.proveedor === null);
    const providerPromos = promos.filter(p => p.proveedor != null);
    const candidates = productPromos.length ? productPromos : providerPromos;

    const pctPromo = candidates.find(p => p.tipo === 'descuento' && (p.descuento ?? 0) > 0);
    const bundle = candidates.find(p => p.tipo === 'bundle' && (p.mincompra ?? 0) > 0 && (p.gratis ?? 0) > 0);
    const noteParts: string[] = [];

    if (pctPromo?.descuento) {
        row.promoDiscountPct = Number(pctPromo.descuento) || 0;
        noteParts.push(`Descuento ${row.promoDiscountPct}%`);
    }

    if (bundle?.mincompra && bundle?.gratis) {
        const min = Number(bundle.mincompra);
        const freeEach = Number(bundle.gratis);

        if (row.qty >= min) {
            const groupsFromPaid = Math.floor(row.qty / min);
            let freebies = groupsFromPaid * freeEach;

            const maxExtra = Math.max(0, row.existencia - row.qty);
            freebies = clamp(freebies, 0, maxExtra);

            row.promoFreeQty = freebies;
            if (freebies > 0) {
                row.qty += freebies;
            }

            noteParts.push(`${min}x${min + freeEach} aplicado (+${row.promoFreeQty} gratis)`);
        } else {
            row.promoFreeQty = 0;
        }
    }

    if (noteParts.length) row.promoNote = noteParts.join(' · ');

    clampManualDiscount(row);
}

/** Runs the remote search and handles loading/errors for the quick lookup list. */
async function doSearch() {
    if (!query.value) { results.value = []; return; }
    loading.value = true;
    clearError();
    try {
        const isBarcode = /^\d+$/.test(query.value.trim());
        const data = await findProduct(isBarcode ? { barcode: Number(query.value) } : { search: query.value, per_page: 20 });
        results.value = data;
        SHOW_RESULTS.value = true;
    } catch (e: any) {
        showError(e?.response?.data?.message || 'No se pudo buscar');
    } finally {
        loading.value = false;
    }
}

/** Simple debounce wrapper for text input to limit server calls while typing. */
function debouncedSearch() {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = window.setTimeout(doSearch, 250);
}

/**
 * Global key listener that captures digits emitted by USB barcode scanners.
 * It collects digits until Enter is pressed, then dispatches a find-by-barcode.
 */
function handleKeydown(e: KeyboardEvent) {
    const tag = (e.target as HTMLElement)?.tagName?.toLowerCase();
    if (['input', 'textarea', 'select'].includes(tag)) return;

    if (!scanActive.value && /^\d$/.test(e.key)) {
        scanActive.value = true;
        scanBuffer.value = e.key;
        scanTimer = window.setTimeout(resetScan, 500);
        e.preventDefault();
        return;
    }

    if (scanActive.value) {
        if (e.key === 'Enter') {
            const code = scanBuffer.value;
            resetScan();
            query.value = code;
            addFirstMatchFromBarcode(code);
            e.preventDefault();
            return;
        }
        if (/^\d$/.test(e.key)) {
            scanBuffer.value += e.key;
            if (scanTimer) {
                clearTimeout(scanTimer);
                scanTimer = window.setTimeout(resetScan, 500);
            }
            e.preventDefault();
            return;
        }
    }
}

/** Resets any ongoing scan capture when the input stream is interrupted. */
function resetScan() {
    scanActive.value = false;
   scanBuffer.value = '';
   if (scanTimer) clearTimeout(scanTimer);
}

/** Finds the first product that matches a scanned barcode and sends it to the cart. */
async function addFirstMatchFromBarcode(code: string) {
    try {
        const rows = await findProduct({ barcode: Number(code) });
        if (rows?.length) addToCart(rows[0]);
    } catch {
        /* silent */
    }
}

/** Inserts or updates a cart row and reapplies promotions on every addition. */
async function addToCart(producto: Producto) {
    SHOW_RESULTS.value = false;
    query.value = '';

    const row = cart.value.find(r => r.ident === producto.ident);
    const max = Number(producto?.inventario?.existencia ?? 0);
    const proveedorIdent = producto.proveedor?.ident ?? producto.proveedorid;
    const proveedorTipo = (producto.proveedor?.tipo as CartRow['proveedorTipo']) ?? 'normal';
    const proveedorPct = producto.proveedor?.porcentaje_comision ?? null;
    const precioProveedor = Number(producto.precio_proveedor ?? producto.precio);

    if (row) {
        if (row.qty < max) row.qty++;
        if (!row.proveedorTipo) row.proveedorTipo = proveedorTipo;
        if (row.proveedorPct == null) row.proveedorPct = proveedorPct;
        if (row.precioProveedor == null) row.precioProveedor = precioProveedor;
        if (!row.proveedorid) row.proveedorid = proveedorIdent;
        if (!row.proveedorname && producto.proveedor?.nombre) {
            row.proveedorname = producto.proveedor.nombre;
        }
        await applyPromotionsToRow(row, proveedorIdent);
        clampManualDiscount(row);
        return;
    }

    const newRow: CartRow = {
        ident: producto.ident,
        nombre: producto.nombre,
        precio: Number(producto.precio),
        proveedorname: producto.proveedor?.nombre,
        existencia: max,
        qty: max > 0 ? 1 : 0,
        proveedorid: proveedorIdent,
        proveedorTipo,
        proveedorPct,
        precioProveedor,
        manualDiscount: 0,
        manualDiscountPercent: null,
    };

    await applyPromotionsToRow(newRow, proveedorIdent);
    cart.value.unshift(newRow);
}

/** Keeps quantities valid while reacting to manual edits on the cart table. */
async function onQtyChange(row: CartRow) {
    clampQty(row);
    await applyPromotionsToRow(row, row.proveedorid);
}

/** Removes an item entirely from the cart. */
function removeFromCart(ident: number) {
    cart.value = cart.value.filter(row => row.ident !== ident);
}

/** Ensures quantity stays within 0..existence and always an integer. */
function clampQty(row: CartRow) {
    row.qty = Math.trunc(Number(row.qty) || 0);
    if (row.qty < 0) row.qty = 0;
    if (row.qty > row.existencia) row.qty = row.existencia;
    clampManualDiscount(row);
}

function onManualDiscountChange(row: CartRow) {
    row.manualDiscount = Math.max(0, Number(row.manualDiscount ?? 0) || 0);
    clampManualDiscount(row);
}

/** Refreshes the caja status banner (open/closed, current user, balances). */
async function refreshCaja() {
    const data = await cajaStatus();
    caja.value = data ?? { open: false, caja: null };

    const summary = (data as any)?.cash_summary ?? (data as any)?.cashSummary;
    const net = summary?.neto ?? summary?.net ?? null;
    if (caja.value.open && net != null && !Number.isNaN(Number(net))) {
        const rounded = Math.round(Number(net) * 100) / 100;
        closeAmountSuggestion.value = rounded;
        if (!closeAmountTouched.value) {
            closeAmount.value = rounded;
        }
    } else if (!caja.value.open) {
        closeAmountSuggestion.value = null;
        if (!closeAmountTouched.value) {
            closeAmount.value = null;
        }
    }
}

/** Opens the cash drawer with an initial balance. */
async function openCaja() {
    resetAlerts();
    if (openAmount.value == null || openAmount.value < 0) {
        showError('Saldo inicial inválido');
        return;
    }
    saving.value = true;
    try {
        await cajaOpen({ saldoinicial: Number(openAmount.value) });
        clearError();
        showMessage('Caja abierta');
        closeAmountTouched.value = false;
        closeAmountSuggestion.value = null;
        await refreshCaja();
    } catch (e: any) {
        showError(e?.response?.data?.message || 'No se pudo abrir caja');
    } finally {
        saving.value = false;
    }
}

/** Closes the cash drawer sending the optional counted final balance. */
async function closeCaja() {
    resetAlerts();
    saving.value = true;
    try {
        const payload = closeAmount.value != null ? { saldofinal: Number(closeAmount.value) } : undefined;
        await cajaClose(payload);
        clearError();
        showMessage('Caja cerrada');
        closeAmountTouched.value = false;
        closeAmountSuggestion.value = null;
        await refreshCaja();
    } catch (e: any) {
        showError(e?.response?.data?.message || 'No se pudo cerrar caja');
    } finally {
        saving.value = false;
    }
}

/** Opens the modal with a clean state ready to capture a new expense. */
function openExpenseModal() {
    expenseError.value = '';
    resetExpenseForm();
    showExpenseModal.value = true;
}

/** Closes the expense modal unless a request is pending. */
function closeExpenseModal() {
    if (expenseSaving.value) return;
    showExpenseModal.value = false;
}

/** Persists a manual expense (egreso) tied to the current caja session. */
async function submitExpense() {
    expenseError.value = '';
    if (!caja.value.open) {
        expenseError.value = 'Abre caja antes de registrar un egreso';
        return;
    }
    if (!expenseForm.total || expenseForm.total <= 0) {
        expenseError.value = 'Monto inválido';
        return;
    }
    if (!expenseForm.concepto.trim()) {
        expenseError.value = 'Concepto requerido';
        return;
    }

    const fecha = (expenseForm.fecha && expenseForm.fecha.trim()) || todayISO();
    const payload: RegisterExpensePayload = {
        monto: Number(expenseForm.total),
        descripcion: expenseForm.concepto.trim(),
        fecha,
    };
    expenseSaving.value = true;
    try {
        await registerExpense(payload);
        clearError();
        showMessage('Egreso registrado');
        showExpenseModal.value = false;
        resetExpenseForm();
        await refreshCaja();
    } catch (e: any) {
        expenseError.value = e?.response?.data?.message || 'No se pudo registrar el egreso';
        showError(expenseError.value);
    } finally {
        expenseSaving.value = false;
    }
}

/** Sends the sale to the backend and resets local cart state upon success. */
async function onCheckout() {
    resetAlerts();
    if (!caja.value.open) {
        showError('Abre caja antes de vender');
        return;
    }

    const lineas: CheckoutLinePayload[] = cart.value
        .filter(row => row.qty > 0)
        .map(row => {
            const promo = Math.round(linePromoDiscount(row) * 100) / 100;
            const manual = Math.round(lineManualDiscount(row) * 100) / 100;
            const totalDiscount = Math.round((promo + manual) * 100) / 100;
            const line: CheckoutLinePayload = {
                idProd: row.ident,
                nombre: row.nombre,
                proveedor: Number(row.proveedorid ?? 0),
                pUni: Number(row.precio),
                cant: row.qty,
            };
            if (totalDiscount > 0) {
                line.product_desc = totalDiscount;
                line.totdesc = totalDiscount;
            }
            if (manual > 0) {
                line.manual_discount = manual;
            }
            return line;
        });
    if (!lineas.length) {
        showError('Carrito vacío');
        return;
    }

    if (paymentMethod.value === 'efectivo') {
        const received = Number(cashReceived.value ?? 0);
        if (received < total.value) {
            showError('Efectivo recibido insuficiente');
            return;
        }
    }

    saving.value = true;
    try {
        const ventaTotal = total.value;
        let recibo = ventaTotal;
        let cambio = 0;
        if (paymentMethod.value === 'efectivo') {
            recibo = Number(cashReceived.value ?? 0);
            cambio = changeDue.value;
        }

        const payload: CheckoutPayload = {
            metodo: paymentMethod.value,
            recibo,
            cambio,
            vendedor: caja.value?.caja?.usuario ?? 'Mostrador',
            concepto: 'VENTA MOSTRADOR',
            lineas,
        };

        const res = await checkout(payload);
        const ventaId = res?.data?.venta?.idventa;
        const snapshot = captureSaleSnapshot(ventaId ?? Date.now());
        openTicketModal(snapshot);
        clearError();
        showMessage('Venta realizada con promociones aplicadas');

        cart.value = [];
        cashReceived.value = null;
        await refreshCaja();
    } catch (e: any) {
        showError(e?.response?.data?.message || 'No se pudo terminar la venta');
    } finally {
        saving.value = false;
    }
}

function buildReceiptPDF(snapshot: SaleSnapshot) {
    const rows = snapshot.rows;
    const fecha = snapshot.fecha;

    const money = (amount: number, currencyCode = 'MXN', locale = 'es-MX') => {
        try { return new Intl.NumberFormat(locale, { style: 'currency', currency: currencyCode, minimumFractionDigits: 2 }).format(amount); }
        catch { return `$${amount.toFixed(2)}`; }
    };

    const doc = new jsPDF({ unit: 'mm', format: 'letter' });
    const PAGE_W = 215.9;
    const PAGE_H = 279.4;
    const MARGIN = 12;
    const INNER_W = PAGE_W - MARGIN * 2;
    const xL = MARGIN;
    const xMidRight = PAGE_W - MARGIN;
    const lineGap = 6;
    let y = 45;

    doc.setFont('helvetica', 'bold'); doc.setFontSize(14);
    doc.text('Ticket de venta', 15, 20);
    doc.setFont('helvetica', 'normal'); doc.setFontSize(10);
    doc.text(`Ticket #${snapshot.idventa}`, 15, 27);
    doc.text(`Fecha: ${fecha}`, 15, 33);
    if (caja.value?.caja?.usuario) {
        doc.text(`Vendedor: ${caja.value.caja.usuario}`, 15, 39);
    }

    const hr = () => { doc.setDrawColor(220); doc.line(MARGIN, y, PAGE_W - MARGIN, y); y += 2; };
    const rowR = (label: string, value: string, bold = false) => {
        pageBreakIfNeeded();
        if (bold) doc.setFont('helvetica', 'bold');
        doc.text(label, xL, y);
        doc.text(value, xMidRight, y, { align: 'right' });
        if (bold) doc.setFont('helvetica', 'normal');
        y += lineGap;
    };

    hr();
    doc.setFont('helvetica', 'bold');
    doc.text('Productos', xL, y); y += lineGap;
    doc.setFont('helvetica', 'normal');

    rows.forEach(row => {
        const nameLines = doc.splitTextToSize(row.nombre || 'Producto', INNER_W);
        nameLines.forEach((ln: string) => {
            pageBreakIfNeeded();
            doc.text(ln, xL, y);
            y += lineGap;
        });

        if (row.proveedorname) {
            pageBreakIfNeeded();
            doc.setFontSize(9);
            doc.setTextColor(120, 120, 120);
            doc.text(`Proveedor: ${row.proveedorname}`, xL, y);
            doc.setTextColor(0, 0, 0);
            doc.setFontSize(10);
            y += lineGap - 2;
        }

        if (row.promoNote) {
            pageBreakIfNeeded();
            doc.setFontSize(9);
            doc.setTextColor(0, 128, 96);
            doc.text(`• ${row.promoNote}`, xL, y);
            doc.setTextColor(0, 0, 0);
            doc.setFontSize(10);
            y += lineGap - 2;
        }

        const promoDiscount = linePromoDiscount(row);
        const manualDiscount = lineManualDiscount(row);
        const gross = row.precio * row.qty;
        const net = Math.max(0, gross - promoDiscount - manualDiscount);

        pageBreakIfNeeded();
        doc.text(`${row.qty} × ${money(row.precio)}`, xL, y);
        doc.text(money(gross), xMidRight, y, { align: 'right' });
        y += lineGap;

        if (promoDiscount > 0) {
            pageBreakIfNeeded();
            doc.setFontSize(9);
            doc.text('Promoción aplicada', xL, y);
            doc.text(`- ${money(promoDiscount)}`, xMidRight, y, { align: 'right' });
            doc.setFontSize(10);
            y += lineGap - 2;
        }

        if (manualDiscount > 0) {
            pageBreakIfNeeded();
            doc.setFontSize(9);
            doc.text('Desc. manual', xL, y);
            doc.text(`- ${money(manualDiscount)}`, xMidRight, y, { align: 'right' });
            doc.setFontSize(10);
            y += lineGap - 2;
        }

        if (promoDiscount > 0 || manualDiscount > 0) {
            pageBreakIfNeeded();
            doc.setFont('helvetica', 'bold');
            doc.text('Total línea', xL, y);
            doc.text(money(net), xMidRight, y, { align: 'right' });
            doc.setFont('helvetica', 'normal');
            y += lineGap;
        }

        y += 2;
    });

    hr();
    rowR('Subtotal', money(snapshot.subtotal));
    if (snapshot.promoDiscount) rowR('Promociones', `- ${money(snapshot.promoDiscount)}`);
    if (snapshot.manualDiscount) rowR('Desc. por producto', `- ${money(snapshot.manualDiscount)}`);
    if (snapshot.manualItemDiscount) rowR('Desc. por producto', `- ${money(snapshot.manualItemDiscount)}`);
    if (snapshot.surchargeAmount) rowR(`Recargo ${snapshot.surchargePercent}%`, money(snapshot.surchargeAmount));
    rowR('TOTAL', money(snapshot.total), true);

    if (snapshot.paymentMethod === 'tarjeta' && snapshot.providerSurcharge?.length) {
        hr();
        doc.setFont('helvetica', 'bold');
        doc.text('Distribución de recargo (tarjeta)', xL, y); y += lineGap;
        doc.setFont('helvetica', 'normal');
        snapshot.providerSurcharge.forEach((entry) => {
            const provider = snapshot.providerNetTotals.find((p) => p.proveedor_id === entry.proveedor_id);
            const label = provider ? provider.nombre ?? `Proveedor ${entry.proveedor_id}` : `Proveedor ${entry.proveedor_id}`;
            pageBreakIfNeeded();
            const percent = typeof entry.percent === 'number' ? ` (${entry.percent.toFixed(2)}%)` : '';
            doc.text(`- ${label}${percent}`, xL, y);
            doc.text(`-${money(entry.amount)}`, xMidRight, y, { align: 'right' });
            y += lineGap - 2;
        });
        const netTotals = snapshot.providerNetTotals ?? [];
        if (netTotals.length) {
            pageBreakIfNeeded();
            doc.setFont('helvetica', 'bold');
            doc.text('Neto por proveedor', xL, y); y += lineGap;
            doc.setFont('helvetica', 'normal');
            netTotals.forEach((entry) => {
                pageBreakIfNeeded();
                doc.text(`• ${entry.nombre ?? `Proveedor ${entry.proveedor_id}`}`, xL, y);
                doc.text(money(entry.total), xMidRight, y, { align: 'right' });
                y += lineGap - 2;
            });
        }
    }


    hr();
    rowR('Método', snapshot.paymentMethod.toUpperCase());
    rowR('Recibido', money(snapshot.cashReceived));
    rowR('Cambio', money(snapshot.change));

    y += 2; pageBreakIfNeeded();
    doc.setFontSize(9);
    doc.text('¡Gracias por su compra!', PAGE_W / 2, y, { align: 'center' });

    function pageBreakIfNeeded() {
        if (y > (PAGE_H - MARGIN - lineGap)) {
            doc.addPage('letter', 'portrait');
            y = MARGIN;
        }
    }

    return doc;
}

/** Builds and downloads the thermal ticket PDF summarizing the recent sale. */
async function makeReceiptPDFFromSale(snapshot: SaleSnapshot) {
    const doc = buildReceiptPDF(snapshot);
    doc.save(`ticket_${snapshot.idventa}.pdf`);
}

// Lifecycle hooks ------------------------------------------------
async function loadSystemSettings() {
    try {
        const data = await getSystemSettings();
        cardChargePercent.value = data.card_charge_percent ?? 4.5;
        lastClosingBalance.value = data.last_closing_balance ?? null;
        if (openAmount.value === null && lastClosingBalance.value !== null) {
            openAmount.value = Number(lastClosingBalance.value);
        }
    } catch (err) {
        // ignore
    }
}

/** Prime caja state and start listening for scanner input when view mounts. */
onMounted(async () => {
    await loadSystemSettings();
    await refreshCaja();
    window.addEventListener('keydown', handleKeydown);
});

/** Tear down timers and listeners to keep things tidy on navigation. */
onUnmounted(() => {
    if (searchTimer) clearTimeout(searchTimer);
    if (scanTimer) clearTimeout(scanTimer);
    if (messageTimer) clearTimeout(messageTimer);
    if (errorTimer) clearTimeout(errorTimer);
    if (clientSearchTimer) clearTimeout(clientSearchTimer);
    window.removeEventListener('keydown', handleKeydown);
});
</script>
<template>
    <AppLayout>
        <div class="space-y-4">

            <!-- Caja banner -->
            <div
                v-if="caja.open"
                class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 space-y-3 text-sm text-emerald-800 sm:flex sm:items-center sm:justify-between sm:space-y-0"
            >
                <div>
                    <b>Caja abierta</b>
                    <span v-if="caja.caja?.saldoinicial"> · Saldo inicial: {{ currency(Number(caja.caja.saldoinicial))
                    }}</span>
                    <span v-if="caja.caja?.usuario"> · Por: {{ caja.caja.usuario }}</span>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <input v-model.number="closeAmount" type="number" step="0.01" placeholder="Saldo sistema (opcional)"
                        @input="closeAmountTouched = true"
                        class="w-full rounded border px-3 py-2 text-sm sm:w-48">
                    <button :disabled="saving" @click="closeCaja"
                        class="w-full rounded bg-rose-600 text-white text-sm px-3 py-2 transition hover:bg-rose-700 disabled:opacity-60 sm:w-auto">Cerrar
                        caja</button>
                </div>
            </div>

            <div
                v-else
                class="rounded-lg border border-amber-200 bg-amber-50 p-3 space-y-3 text-sm text-amber-800 sm:flex sm:items-center sm:justify-between sm:space-y-0"
            >
                <div>
                    <b>Caja cerrada</b> · Abre para poder vender
                </div>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <input v-model.number="openAmount" type="number" step="0.01" :placeholder="openAmountPlaceholder" :value="lastClosingBalance ?? ''"
                        class="w-full rounded border px-3 py-2 text-sm sm:w-48">
                    <button :disabled="saving || openAmount == null" @click="openCaja"
                        class="w-full rounded bg-emerald-600 text-white text-sm px-3 py-2 transition hover:bg-emerald-700 disabled:opacity-60 sm:w-auto">Abrir
                        caja</button>
                </div>
            </div>

            <!-- Alerts -->
            <div v-if="message"
                class="rounded border border-emerald-200 bg-emerald-50 text-emerald-800 text-sm px-3 py-2">
                {{ message }}
            </div>
            <div v-if="error" class="rounded border border-rose-200 bg-rose-50 text-rose-700 text-sm px-3 py-2">
                {{ error }}
            </div>

            <section class="space-y-3 text-[13px] leading-tight">
                <!-- Search -->
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Buscar / Escanear</label>
                    <input v-model="query" @input="debouncedSearch" type="text" placeholder="Nombre, ident, proveedor…"
                        class="w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-2.5 py-1.5 text-[13px]" />
                    <p class="text-[11px] text-gray-500 mt-1">
                        Escáner USB: apunte al input o presione Enter al final del código.
                    </p>
                </div>

                <!-- Results (compact) -->
                <div v-if="SHOW_RESULTS" class="border rounded-md max-h-60 overflow-y-auto">
                    <div class="p-3 space-y-3 md:hidden">
                        <div
                            v-if="loading"
                            class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-3 text-xs text-gray-600"
                        >
                            Buscando…
                        </div>
                        <div
                            v-else-if="!results.length"
                            class="rounded-lg border border-gray-200 bg-white px-3 py-3 text-xs text-gray-500"
                        >
                            Sin resultados
                        </div>
                        <ul v-else class="space-y-3">
                            <li
                                v-for="p in results"
                                :key="p.id"
                                class="rounded-lg border border-gray-200 bg-white p-3 text-xs shadow-sm"
                            >
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <p class="font-semibold text-gray-900">{{ p.nombre }}</p>
                                        <p class="text-[11px] text-gray-500">Identificador: {{ p.ident }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-semibold text-gray-900">
                                            {{
                                                new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(
                                                    Number(p.precio)
                                                )
                                            }}
                                        </p>
                                        <p class="text-[11px] text-gray-500">
                                            Existencia: {{ Number(p?.inventario?.existencia ?? 0) }}
                                        </p>
                                    </div>
                                </div>
                                <div class="mt-3 flex justify-end">
                                    <button
                                        @click="addToCart(p)"
                                        class="inline-flex items-center rounded border border-gray-300 px-3 py-1.5 text-[12px] font-medium text-gray-700 hover:bg-gray-50"
                                    >
                                        Agregar
                                    </button>
                                </div>
                            </li>
                        </ul>
                    </div>
                    <div class="hidden md:block">
                        <table class="min-w-full text-xs">
                            <thead class="bg-gray-50 text-gray-500 sticky top-0 z-10">
                                <tr>
                                    <th class="text-left font-medium px-2.5 py-1.5">Ident</th>
                                    <th class="text-left font-medium px-2.5 py-1.5">Producto</th>
                                    <th class="text-right font-medium px-2.5 py-1.5">Precio</th>
                                    <th class="text-right font-medium px-2.5 py-1.5">Exist.</th>
                                    <th class="px-2.5 py-1.5"></th>
                                </tr>
                            </thead>
                            <tbody class="[&>tr:nth-child(even)]:bg-gray-50/60">
                                <tr v-for="p in results" :key="p.id" class="hover:bg-gray-50">
                                    <td class="px-2.5 py-1.5 whitespace-nowrap">{{ p.ident }}</td>
                                    <td class="px-2.5 py-1.5">
                                        <div class="truncate max-w-[28ch]">{{ p.nombre }}</div>
                                    </td>
                                    <td class="px-2.5 py-1.5 text-right whitespace-nowrap">
                                        {{
                                            new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(
                                                Number(p.precio)
                                            )
                                        }}
                                    </td>
                                    <td class="px-2.5 py-1.5 text-right whitespace-nowrap">
                                        {{ Number(p?.inventario?.existencia ?? 0) }}
                                    </td>
                                    <td class="px-2.5 py-1.5 text-right">
                                        <button
                                            @click="addToCart(p)"
                                            class="rounded border px-2 py-1 text-[12px] hover:bg-gray-100"
                                        >
                                            Agregar
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="!loading && results.length === 0">
                                    <td colspan="5" class="px-2.5 py-2.5 text-center text-gray-500">Sin resultados</td>
                                </tr>
                                <tr v-if="loading">
                                    <td colspan="5" class="px-2.5 py-2.5 text-center text-gray-500">Buscando…</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
                <!-- Cart -->
                <section class="flex-1 space-y-3 text-[13px] leading-tight">
                    <div class="border rounded-md overflow-hidden">
                        <div class="px-3 py-2 border-b text-xs font-semibold tracking-wide text-gray-700">Carrito</div>
                        <div class="p-3 space-y-3 md:hidden">
                            <div
                                v-if="cart.length === 0"
                                class="rounded-lg border border-gray-200 bg-white px-3 py-4 text-xs text-gray-500"
                            >
                                No hay productos en el carrito
                            </div>
                            <template v-else>
                                <article
                                    v-for="r in cart"
                                    :key="`mobile-${r.ident}`"
                                    class="space-y-3 rounded-lg border border-gray-200 bg-white p-3 text-xs shadow-sm"
                                >
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <p class="font-semibold text-gray-900">{{ r.nombre }}</p>
                                            <p class="text-[11px] text-gray-500">Identificador: {{ r.ident }}</p>
                                        <p
                                            v-if="r.proveedorname"
                                            class="text-[11px] text-gray-500 flex items-center gap-1"
                                        >
                                            <span
                                                class="inline-flex items-center justify-center rounded-full px-1.5 py-0.5 text-[10px]"
                                                :class="providerTypeInfo(r.proveedorTipo, r.proveedorPct).className"
                                            >
                                                {{ providerTypeInfo(r.proveedorTipo, r.proveedorPct).icon }}
                                            </span>
                                            <span>{{ r.proveedorname }}</span>
                                        </p>
                                            <p v-if="r.promoNote" class="text-[11px] text-emerald-700">
                                                {{ r.promoNote }}
                                            </p>
                                        </div>
                                        <div class="text-right">
                                            <p class="font-semibold text-gray-900">{{ currency(lineNet(r)) }}</p>
                                            <p class="text-[11px] text-gray-500">Existencia: {{ r.existencia }}</p>
                                        </div>
                                    </div>
                                    <div class="space-y-3 text-[12px] text-gray-600">
                                        <label class="flex flex-col gap-1">
                                            <span class="font-medium text-gray-700">Cantidad</span>
                                            <input
                                                v-model.number="r.qty"
                                                @change="onQtyChange(r)"
                                                type="number"
                                                min="0"
                                                :max="r.existencia"
                                                class="w-full rounded-md border px-2.5 py-1.5 text-right text-sm"
                                            />
                                            <span v-if="(r.promoFreeQty ?? 0) > 0" class="text-[11px] text-gray-500">
                                                Cobrar: {{ Math.max(0, r.qty - (r.promoFreeQty ?? 0)) }}
                                            </span>
                                        </label>
                                        <div class="grid grid-cols-2 gap-3">
                                            <div>
                                                <span class="font-medium text-gray-700">P. unitario</span>
                                                <div class="text-sm text-gray-900">{{ currency(r.precio) }}</div>
                                            </div>
                                            <div>
                                                <span class="font-medium text-gray-700">Desc. aplicado</span>
                                                <div class="text-sm text-emerald-700">
                                                    - {{
                                                        currency(
                                                            linePromoDiscount(r) + lineManualDiscount(r)
                                                        )
                                                    }}
                                                </div>
                                            </div>
                                        </div>
                                        <label class="flex flex-col gap-2">
                                            <span class="font-medium text-gray-700">Desc. (%)</span>
                                            <input
                                                v-model.number="r.manualDiscountPercent"
                                                type="number"
                                                min="0"
                                                step="0.1"
                                                placeholder="%"
                                                class="w-full rounded-md border px-2.5 py-1.5 text-right text-sm"
                                                @input="onManualPercentInput(r)"
                                            />
                                        </label>
                                        <label class="flex flex-col gap-2">
                                            <span class="font-medium text-gray-700">Desc. manual ($)</span>
                                            <input
                                                :value="(r.manualDiscount ?? 0).toFixed(2)"
                                                type="number"
                                                disabled
                                                class="w-full rounded-md border px-2.5 py-1.5 text-right text-sm bg-gray-100 text-gray-600"
                                            />
                                        </label>
                                    </div>
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <button
                                            @click="removeFromCart(r.ident)"
                                            class="inline-flex items-center rounded border border-gray-300 px-3 py-1.5 text-[12px] font-medium text-gray-700 hover:bg-gray-50"
                                        >
                                            Quitar
                                        </button>
                                    </div>
                                </article>
                            </template>
                        </div>
                        <div class="hidden md:block">
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-xs">
                                    <thead class="bg-gray-50 text-gray-500 sticky top-0 z-10">
                                        <tr>
                                            <th class="text-left font-medium px-2.5 py-1.5">Ident</th>
                                            <th class="text-left font-medium px-2.5 py-1.5">Producto</th>
                                            <th class="text-left font-medium px-2.5 py-1.5">Proveedor</th>
                                            <th class="text-right font-medium px-2.5 py-1.5">P. Unit.</th>
                                            <th class="text-right font-medium px-2.5 py-1.5">Exist.</th>
                                            <th class="text-right font-medium px-2.5 py-1.5">Cantidad</th>
                                            <th class="text-right font-medium px-2.5 py-1.5">Desc. (%)</th>
                                            <th class="text-right font-medium px-2.5 py-1.5">Desc. ($)</th>
                                            <th class="text-right font-medium px-2.5 py-1.5">Importe</th>
                                            <th class="px-2.5 py-1.5"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="[&>tr:nth-child(even)]:bg-gray-50/60">
                                        <tr v-for="r in cart" :key="r.ident" class="align-middle">
                                            <td class="px-2.5 py-1.5 whitespace-nowrap">{{ r.ident }}</td>
                                            <td class="px-2.5 py-1.5">
                                                <div class="truncate max-w-[28ch]">{{ r.nombre }}</div>
                                                <div v-if="r.promoNote" class="text-[11px] text-emerald-700">
                                                    {{ r.promoNote }}
                                                </div>
                                            </td>
                                            <td class="px-2.5 py-1.5 text-right">
                                                <div class="flex items-center justify-end gap-1">
                                                    <span
                                                        v-if="r.proveedorname"
                                                        class="inline-flex items-center justify-center rounded-full px-1.5 py-0.5 text-[10px]"
                                                        :class="providerTypeInfo(r.proveedorTipo, r.proveedorPct).className"
                                                    >
                                                        {{ providerTypeInfo(r.proveedorTipo, r.proveedorPct).icon }}
                                                    </span>
                                                    <span class="truncate inline-block max-w-[18ch]">
                                                        {{ r.proveedorname }}
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="px-2.5 py-1.5 text-right whitespace-nowrap">{{ currency(r.precio) }}</td>
                                            <td class="px-2.5 py-1.5 text-right">{{ r.existencia }}</td>
                                            <td class="px-2.5 py-1.5 text-right">
                                                <input v-model.number="r.qty" @change="onQtyChange(r)" type="number" min="0"
                                                    :max="r.existencia"
                                                    class="w-20 rounded border px-2 py-1 text-right text-[12px]" />
                                                <div v-if="(r.promoFreeQty ?? 0) > 0" class="text-[11px] text-gray-500">
                                                    Cobrar: {{ Math.max(0, r.qty - (r.promoFreeQty ?? 0)) }}
                                                </div>
                                            </td>
                                            <td class="px-2.5 py-1.5 text-right">
                                                <input
                                                    v-model.number="r.manualDiscountPercent"
                                                    type="number"
                                                    min="0"
                                                    step="0.1"
                                                    placeholder="%"
                                                    class="w-16 rounded border px-2 py-1 text-right text-[12px]"
                                                    @input="onManualPercentInput(r)"
                                                />
                                            </td>
                                            <td class="px-2.5 py-1.5 text-right">
                                                <input
                                                    :value="(r.manualDiscount ?? 0).toFixed(2)"
                                                    type="number"
                                                    disabled
                                                    class="w-20 rounded border px-2 py-1 text-right text-[12px] bg-gray-100 text-gray-600"
                                                />
                                            </td>
                                            <td class="px-2.5 py-1.5 text-right whitespace-nowrap">
                                                {{ currency(lineNet(r)) }}
                                            </td>
                                            <td class="px-2.5 py-1.5 text-right">
                                                <button @click="removeFromCart(r.ident)"
                                                    class="rounded border px-2 py-1 text-[12px] hover:bg-gray-100">
                                                    Quitar
                                                </button>
                                            </td>
                                        </tr>
                                        <tr v-if="cart.length === 0">
                                            <td colspan="10" class="px-2.5 py-4 text-center text-gray-500">No hay productos en
                                                el carrito</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Totals & Payment -->
                <section class="w-full space-y-3 text-[13px] leading-tight lg:w-80 lg:shrink-0">
                    <div class="border rounded-md p-3 space-y-3">
                        <div class="text-[13px] space-y-1">
                            <div class="flex justify-between"><span>Subtotal</span><b>{{ currency(subTotal) }}</b></div>
                            <div class="flex justify-between" v-if="promoDiscountAmount">
                                <span>Promociones</span>
                                <b class="text-emerald-700">- {{ currency(promoDiscountAmount) }}</b>
                            </div>
                            <div class="flex justify-between" v-if="manualItemDiscountAmount">
                                <span>Desc. por producto</span>
                                <b class="text-emerald-700">- {{ currency(manualItemDiscountAmount) }}</b>
                            </div>
                            <div class="flex justify-between">
                                <span>
                                    Recargo tarjeta
                                    <template v-if="paymentMethod === 'tarjeta'">
                                        ({{ surchargePercent }}% sobre {{ currency(subTotal) }})
                                    </template>
                                    <template v-else-if="surchargePercent">
                                        ({{ surchargePercent }}%)
                                    </template>
                                </span>
                                <b>{{ currency(surchargeAmount) }}</b>
                            </div>
                            <div
                                v-if="providerNetTotalsList.length"
                                class="rounded-md border border-gray-200 bg-gray-50 px-2.5 py-2 text-xs text-gray-600"
                            >
                                <div class="flex items-center justify-between">
                                    <p class="font-medium text-gray-700">Distribución del recargo</p>
                                    <label class="inline-flex items-center gap-1 text-[11px] text-gray-600 select-none">
                                        <input
                                            type="checkbox"
                                            v-model="showProviderBreakdown"
                                            class="rounded border-gray-300 text-[#E4007C] focus:ring-[#E4007C]"
                                        />
                                        <span>Mostrar desglose</span>
                                    </label>
                                </div>
                                <template v-if="showProviderBreakdown">
                                    <ul class="mt-1 space-y-1">
                                        <li
                                            v-for="item in providerSurchargeList"
                                            :key="item.proveedor_id"
                                            class="flex items-center justify-between gap-2"
                                        >
                                            <span class="truncate">{{ item.nombre }}</span>
                                            <span class="text-right text-gray-700">
                                                <span class="mr-2 text-[11px] text-gray-500">{{ item.percent.toFixed(2) }}%</span>
                                                <b class="font-semibold text-gray-900">
                                                    {{ paymentMethod === 'tarjeta' ? '-' : '' }}{{ currency(item.amount) }}
                                                </b>
                                            </span>
                                        </li>
                                    </ul>
                                    <div
                                        class="mt-2 rounded border border-gray-200 bg-white/70 px-2 py-2 text-[11px] text-gray-500"
                                    >
                                        <p class="font-medium text-gray-700">Detalle por proveedor:</p>
                                        <ul class="mt-1 space-y-1">
                                            <li
                                                v-for="item in providerNetTotalsList"
                                                :key="`net-${item.proveedor_id}`"
                                            class="rounded bg-white px-2 py-1.5 shadow-sm"
                                        >
                                            <div class="flex flex-wrap items-center justify-between gap-2 text-gray-800">
                                                <div class="flex items-center gap-2">
                                                    <span class="truncate font-semibold">{{ item.nombre }}</span>
                                                    <span
                                                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                                        :class="providerTypeInfo(item.proveedor_tipo, item.proveedor_pct).className"
                                                    >
                                                        <span>{{ providerTypeInfo(item.proveedor_tipo, item.proveedor_pct).icon }}</span>
                                                        <span>{{ providerTypeInfo(item.proveedor_tipo, item.proveedor_pct).label }}</span>
                                                    </span>
                                                </div>
                                                <span class="font-semibold text-gray-900">{{ currency(item.total) }}</span>
                                            </div>
                                            <button
                                                type="button"
                                                class="mt-1 text-[11px] font-semibold text-[#E4007C] underline"
                                                @click="toggleProviderDetail(item.proveedor_id)"
                                            >
                                                {{ isProviderExpanded(item.proveedor_id) ? 'Ocultar desglose' : 'Mostrar desglose' }}
                                            </button>
                                            <div
                                                v-if="isProviderExpanded(item.proveedor_id)"
                                                class="mt-1 space-y-0.5 text-[10px] text-gray-500"
                                            >
                                                <div class="flex justify-between gap-2">
                                                    <span>Público:</span>
                                                    <span class="font-medium text-gray-700">{{ currency(item.public_total) }}</span>
                                                </div>
                                                <div class="flex justify-between gap-2">
                                                    <span>Costo proveedor:</span>
                                                    <span class="font-medium text-gray-700">{{ currency(item.proveedor_bruto) }}</span>
                                                </div>
                                                <div class="flex justify-between gap-2">
                                                    <span>Desc. proveedor:</span>
                                                    <span class="font-medium text-gray-700">-{{ currency(item.proveedor_descuento) }}</span>
                                                </div>
                                                <div class="flex justify-between gap-2">
                                                    <span>Recargo tarjeta:</span>
                                                    <span class="font-medium text-gray-700">-{{ currency(item.provider_card_charge) }}</span>
                                                </div>
                                                <div class="flex justify-between gap-2">
                                                    <span>Ganancia admin:</span>
                                                    <span class="font-medium text-gray-700">+{{ currency(item.admin_ganancia) }}</span>
                                                </div>
                                            </div>
                                            <div
                                                v-if="item.line_items?.length && isProviderExpanded(item.proveedor_id)"
                                                class="mt-2 rounded border border-gray-100 bg-gray-50 p-2"
                                            >
                                                <table class="w-full text-[10px] text-gray-600">
                                                    <thead>
                                                        <tr class="text-[9px] uppercase tracking-wide text-gray-500">
                                                            <th class="text-left">Producto</th>
                                                            <th class="text-right">Cant</th>
                                                            <th class="text-right">Público</th>
                                                            <th class="text-right">Precio proveedor</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr
                                                            v-for="line in item.line_items"
                                                            :key="`${item.proveedor_id}-${line.nombre}`"
                                                            class="[&:nth-child(even)]:bg-white"
                                                        >
                                                            <td class="pr-2 text-left">{{ line.nombre }}</td>
                                                            <td class="px-1 text-right">{{ line.qty }}</td>
                                                            <td class="px-1 text-right">{{ currency(line.precio_publico) }}</td>
                                                            <td class="pl-1 text-right">{{ currency(line.precio_proveedor) }}</td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </li>
                                    </ul>
                                    </div>
                                </template>
                            </div>
                            <div class="flex justify-between text-base">
                                <span>Total</span><b>{{ currency(total) }}</b>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Método de pago</label>
                            <div class="flex flex-wrap gap-2">
                                <button @click="paymentMethod = 'efectivo'"
                                    :class="['px-2.5 py-1.5 rounded border text-xs', paymentMethod === 'efectivo' ? 'bg-gray-900 text-white' : '']">Efectivo</button>
                                <button @click="paymentMethod = 'tarjeta'"
                                    :class="['px-2.5 py-1.5 rounded border text-xs', paymentMethod === 'tarjeta' ? 'bg-gray-900 text-white' : '']">Tarjeta</button>
                                <button @click="paymentMethod = 'transferencia'"
                                    :class="['px-2.5 py-1.5 rounded border text-xs', paymentMethod === 'transferencia' ? 'bg-gray-900 text-white' : '']">Transferencia</button>
                            </div>
                        </div>

                        <div v-if="paymentMethod === 'efectivo'">
                            <label class="block text-xs font-medium text-gray-600 mb-1">Efectivo recibido</label>
                            <input v-model.number="cashReceived" type="number" step="0.01" min="0"
                                class="w-full rounded-md border px-2.5 py-1.5 text-[13px]" />
                            <div class="mt-2 text-[13px] text-gray-700 flex justify-between">
                                <span>Cambio</span><b>{{ currency(changeDue) }}</b>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <button :disabled="saving || !caja.open || cart.length === 0" @click="onCheckout"
                                class="w-full rounded-md bg-[#E4007C] hover:bg-[#cc006f] text-white px-3 py-2 text-[13px] disabled:opacity-60">
                                Cobrar
                            </button>
                            <button type="button" @click="openExpenseModal"
                                class="w-full rounded-md border border-gray-300 px-3 py-2 text-[13px] hover:bg-gray-50">
                                Registrar egreso
                            </button>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    <div v-if="showExpenseModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
        @click.self="closeExpenseModal">
        <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-lg">
            <h2 class="text-lg font-semibold text-gray-900">Registrar egreso</h2>
                <p class="mt-1 text-sm text-gray-500">Captura salidas de efectivo para mantener el control de caja.</p>

                <div v-if="expenseError" class="mt-3 rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                    {{ expenseError }}
                </div>

                <div class="mt-4 space-y-3 text-[13px] leading-tight">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Concepto</label>
                        <input v-model="expenseForm.concepto" type="text" placeholder="Descripción del egreso"
                            class="w-full rounded-md border px-2.5 py-1.5 text-[13px]" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Monto</label>
                        <input v-model.number="expenseForm.total" type="number" min="0" step="0.01"
                            class="w-full rounded-md border px-2.5 py-1.5 text-[13px]" />
                    </div>
                    <p class="text-xs text-gray-500">El egreso se registrará como <b>efectivo</b>.</p>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Fecha</label>
                        <input v-model="expenseForm.fecha" type="date"
                            class="w-full rounded-md border px-2.5 py-1.5 text-[13px]" />
                    </div>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" @click="closeExpenseModal" :disabled="expenseSaving"
                        class="rounded-md border px-3 py-1.5 text-sm hover:bg-gray-50 disabled:opacity-60">
                        Cancelar
                    </button>
                    <button type="button" @click="submitExpense" :disabled="expenseSaving"
                        class="rounded-md bg-rose-600 px-3 py-1.5 text-sm text-white hover:bg-rose-700 disabled:opacity-60">
                        {{ expenseSaving ? 'Guardando…' : 'Guardar egreso' }}
                    </button>
                </div>
            </div>
        </div>
        <div v-if="showTicketModal && lastSaleSnapshot" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
            @click.self="closeTicketModal">
            <div class="w-full max-w-2xl rounded-lg bg-white p-5 shadow-lg space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Ticket de venta #{{ lastSaleSnapshot?.idventa }}</h2>
                        <p class="text-xs text-gray-500 mt-1">Elige cómo entregar el comprobante al cliente. Total: <span class="font-semibold text-gray-800">{{ currency(lastSaleSnapshot?.total ?? 0) }}</span></p>
                    </div>
                    <button type="button" @click="closeTicketModal"
                        class="inline-flex items-center justify-center rounded-lg border px-3 py-1.5 text-sm hover:bg-gray-50">✕</button>
                </div>

                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 space-y-3">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <button type="button" @click="onPrintTicket"
                            class="rounded-lg bg-gray-900 hover:bg-gray-800 text-white px-4 py-2 text-sm disabled:opacity-60">
                            Imprimir ticket
                        </button>
                        <div class="flex flex-wrap items-center gap-2">
                            <label class="text-sm text-gray-600">Enviar por</label>
                            <select v-model="ticketSendChannel"
                                class="rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-gray-900 focus:ring-gray-900">
                                <option value="email">Email</option>
                                <option value="sms" disabled>SMS (próximamente)</option>
                            </select>
                            <button type="button" @click="sendTicket" :disabled="ticketSending"
                                class="rounded-lg border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50 disabled:opacity-60">
                                {{ ticketSending ? 'Enviando…' : 'Enviar ticket' }}
                            </button>
                        </div>
                    </div>
                    <p class="text-xs text-gray-500">Para enviar el ticket completa los datos del cliente o selecciona uno existente.</p>
                </div>

                <div class="space-y-3">
                    <div class="space-y-1">
                        <label class="block text-sm font-medium text-gray-700">Buscar cliente</label>
                        <input v-model="clientSearch" type="text" placeholder="Nombre o correo…"
                            class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2" />
                        <div v-if="clientSearchLoading" class="text-xs text-gray-500">Buscando…</div>
                        <ul v-else-if="clientSuggestions.length"
                            class="max-h-40 overflow-auto border border-gray-200 rounded-lg divide-y divide-gray-100 text-sm">
                            <li v-for="c in clientSuggestions" :key="c.id"
                                class="px-3 py-2 cursor-pointer hover:bg-gray-50"
                                @click="selectClientSuggestion(c)">
                                <div class="font-medium text-gray-800">{{ c.nombre }}</div>
                                <div class="text-xs text-gray-500">{{ c.email || 'Sin email' }} · {{ c.telefono || 'Sin teléfono' }}</div>
                            </li>
                        </ul>
                        <div v-else-if="clientSearchNoResults" class="text-xs text-gray-500">Sin resultados</div>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Nombre</label>
                            <input v-model="clientForm.nombre" type="text"
                                class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2"
                                placeholder="Nombre del cliente" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Correo electrónico</label>
                            <input v-model="clientForm.email" type="email"
                                class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2"
                                placeholder="cliente@email.com" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Teléfono</label>
                            <input v-model="clientForm.telefono" type="tel"
                                class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2"
                                placeholder="10 dígitos" />
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <button type="button" @click="saveCliente" :disabled="clientSaving"
                            class="rounded-lg bg-gray-900 hover:bg-gray-800 text-white px-4 py-2 text-sm disabled:opacity-60">
                            Guardar cliente
                        </button>
                        <button type="button" @click="resetClientForm"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">
                            Limpiar
                        </button>
                    </div>

                    <div v-if="clientMessage" class="rounded border border-emerald-200 bg-emerald-50 text-emerald-700 px-3 py-2 text-sm">{{ clientMessage }}</div>
                    <div v-if="clientError" class="rounded border border-rose-200 bg-rose-50 text-rose-700 px-3 py-2 text-sm">{{ clientError }}</div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
