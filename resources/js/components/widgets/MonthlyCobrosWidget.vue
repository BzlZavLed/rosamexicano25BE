<!-- src/components/widgets/MonthlyCobrosWidget.vue -->
<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { jsPDF } from 'jspdf';

// Proveedores API (list)
import { listProveedoresAll, type Proveedor } from '../../api/proveedores'

// Cobros API (batch + single)
import { createCobrosBatch, createCobro /* , loadCobros */ } from '../../api/cobros'



// Optional: allow a custom fallback check for batch -> single
// If you have a helper, import it; otherwise we use a simple local fn.
function shouldFallbackToSingle(err: any): boolean {
    const code = err?.response?.status ?? err?.status
    // e.g., your backend might return 422/409 when batch can't proceed
    return code === 422 || code === 409
}

// ---------- Props with safe defaults (no withDefaults macro) ----------
const props = defineProps<{
    detailsRoute?: string
    currency?: string
    locale?: string
}>()

const detailsRoute = props.detailsRoute ?? '/admin/cobros'
const currency = props.currency ?? 'MXN'
const locale = props.locale ?? 'es-MX'

// ---------- Helpers ----------
function parseErrorMessage(err: any) {
    return err?.response?.data?.message || err?.message || 'Ocurrió un error inesperado'
}
function formatCurrency(amount: number, currencyCode = currency, loc = locale) {
    if (!Number.isFinite(amount)) return '$0.00'
    try {
        return new Intl.NumberFormat(loc, { style: 'currency', currency: currencyCode }).format(amount)
    } catch {
        return `$${amount.toFixed(2)}`
    }
}
function todayISO() {
    return new Date().toISOString().slice(0, 10)
}
function currentMonth() {
    const now = new Date()
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
}
function defaultBulkConcept(value: string) {
    const label = formatMonthLabel(value)
    return label ? `Cobro mensualidad ${label}` : 'Cobro mensualidad'
}
function formatMonthLabel(value: string) {
    if (!value) return ''
    const [year, month] = value.split('-').map(Number)
    if (!year || !month) return value
    const formatter = new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' })
    return formatter.format(new Date(year, month - 1, 1))
}
type PdfContext = {
    concepto: string;
    importe: number;
    fecha_cobro: string;
    mes_cobro: string;
    nota?: string | null;
};

function formatDateLabel(value: string) {
    if (!value) return '';
    const [year, month, day] = value.split('-').map((x) => Number(x));
    if (!year || !month || !day) return value;
    return `${String(day).padStart(2, '0')}/${String(month).padStart(2, '0')}/${year}`;
}
function slugify(value: string) {
    return (
        value
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-zA-Z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .toLowerCase() || 'proveedor'
    );
}
function generateCobroPdf(proveedor: Proveedor, ctx: PdfContext) {
    const doc = new jsPDF();
    const marginX = 18;
    let y = 20;

    doc.setFont('helvetica', 'bold');
    doc.setFontSize(16);
    doc.text('Rosa Mexicano', marginX, y);
    doc.setFontSize(12);
    doc.setFont('helvetica', 'normal');
    y += 10;
    doc.text(`Fecha de emisión: ${formatDateLabel(ctx.fecha_cobro)}`, marginX, y);
    y += 10;

    doc.setFont('helvetica', 'bold');
    doc.text('Nota de cobro', marginX, y);
    y += 8;

    doc.setFont('helvetica', 'normal');
    doc.text(`Proveedor: ${proveedor.nombre}`, marginX, y);
    y += 6;
    doc.text(`Identificador interno: ${proveedor.ident}`, marginX, y);
    y += 6;
    if (proveedor.email) {
        doc.text(`Correo de contacto: ${proveedor.email}`, marginX, y);
        y += 6;
    }
    if (proveedor.tel) {
        doc.text(`Teléfono: ${proveedor.tel}`, marginX, y);
        y += 6;
    }
    y += 4;

    doc.setFont('helvetica', 'bold');
    doc.text('Detalle del cobro', marginX, y);
    y += 8;

    doc.setFont('helvetica', 'normal');
    doc.text(`Concepto: ${ctx.concepto}`, marginX, y);
    y += 6;
    doc.text(`Mes aplicado: ${formatMonthLabel(ctx.mes_cobro)}`, marginX, y);
    y += 6;
    doc.text(`Fecha de cargo: ${formatDateLabel(ctx.fecha_cobro)}`, marginX, y);
    y += 6;
    doc.text(`Monto: ${formatCurrency(ctx.importe)}`, marginX, y);
    y += 8;

    if (ctx.nota && ctx.nota.trim()) {
        doc.setFont('helvetica', 'bold');
        doc.text('Notas adicionales', marginX, y);
        y += 6;
        doc.setFont('helvetica', 'normal');
        const lines = doc.splitTextToSize(ctx.nota.trim(), 170);
        doc.text(lines, marginX, y);
        y += lines.length * 6;
    }

    y += 10;
    doc.setFont('helvetica', 'italic');
    doc.text('Gracias por ser parte de Rosa Mexicano.', marginX, y);

    const dataUri = doc.output('datauristring');
    const pdfBase64 = (dataUri.split(',')[1] ?? '').trim();
    const safeName = slugify(proveedor.nombre || `proveedor-${proveedor.id}`);
    const filename = `cobro-${ctx.mes_cobro}-${safeName}.pdf`;

    return { base64: pdfBase64, filename };
}
// ---------- State ----------
const loadingProveedores = ref(false)
const proveedoresError = ref('')
const proveedores = ref<Proveedor[]>([])

const bulkMessage = ref('')
const bulkError = ref('')
const bulkFailures = ref<Array<{ proveedor: Proveedor; error: string }>>([])
const bulkCreated = ref<number | null>(null)
const bulkSkipped = ref<number | null>(null)
const bulkMailStats = ref<{ sent: number; failed: number } | null>(null)
const bulkState = ref({ running: false, successCount: 0 })

const DEFAULT_FECHA_COBRO = todayISO()
const DEFAULT_NOTA = 'Cobro generado desde dashboard'

const bulkForm = ref({
    mes_cobro: currentMonth(),
})

// ---------- Data loading ----------
async function loadProveedores() {
    loadingProveedores.value = true
    proveedoresError.value = ''
    try {
        const data = await listProveedoresAll()
        proveedores.value = Array.isArray(data) ? data : []
    } catch (err: any) {
        proveedoresError.value = parseErrorMessage(err)
        proveedores.value = []
    } finally {
        loadingProveedores.value = false
    }
}

// ---------- Derived values ----------
const proveedoresOrdenados = computed(() =>
    [...proveedores.value].sort((a, b) => (a.nombre ?? '').localeCompare(b.nombre ?? '', 'es'))
)

const proveedoresConImporte = computed(() =>
    proveedoresOrdenados.value.filter((p) => Number(p.importe ?? 0) > 0)
)

const totalMensual = computed(() =>
    Math.round(proveedoresConImporte.value.reduce((sum, p) => sum + Number(p.importe ?? 0), 0) * 100) /
    100
)

const canRunBulk = computed(() => proveedoresConImporte.value.length > 0 && !bulkState.value.running)

function cobroDefaults(mes: string | undefined) {
    const month = mes && mes.trim() ? mes : currentMonth()
    return {
        concepto: defaultBulkConcept(month),
        fecha_cobro: DEFAULT_FECHA_COBRO,
        nota: DEFAULT_NOTA,
    }
}

// ---------- Actions ----------
async function runMonthlyCobros() {
    bulkState.value.running = true
    bulkState.value.successCount = 0
    bulkMessage.value = ''
    bulkError.value = ''
    bulkFailures.value = []
    bulkCreated.value = null
    bulkSkipped.value = null
    bulkMailStats.value = null

    const form = bulkForm.value

    if (!form.mes_cobro) {
        bulkError.value = 'Selecciona el mes del cobro.'
        bulkState.value.running = false
        return
    }

    const baseDefaults = cobroDefaults(form.mes_cobro)
    const concepto = baseDefaults.concepto
    const fechaCobro = baseDefaults.fecha_cobro
    const nota = baseDefaults.nota

    const objetivos = proveedoresConImporte.value
    if (!objetivos.length) {
        bulkError.value = 'No hay proveedores con importe mensual configurado.'
        bulkState.value.running = false
        return
    }

    // Build PDF + payload per proveedor
    const detalles = objetivos.map((proveedor) => {
        const importe = Math.round(Number(proveedor.importe ?? 0) * 100) / 100
        const { base64 } = generateCobroPdf(proveedor, {
            concepto,
            importe,
            fecha_cobro: fechaCobro,
            mes_cobro: form.mes_cobro,
            nota,
        })
        return {
            proveedor,
            importe,
            pdfBase64: base64,
        }
    })

    const batchPayload = {
        concepto,
        mes_cobro: form.mes_cobro,
        fecha_cobro: fechaCobro,
        nota,
        cobros: detalles.map(({ proveedor, importe, pdfBase64 }) => ({
            proveedor_id: proveedor.id,
            importe,
            cobro_pdf_base64: pdfBase64,
        })),
    }
    try {
        // Prefer batch when possible
        if (batchPayload.cobros.length > 1) {
            try {
                const resp: any = await createCobrosBatch({
                    ...batchPayload
                })
                const created = resp?.created ?? resp?.data?.length ?? batchPayload.cobros.length
                const skipped = resp?.skipped ?? 0
                const mailSent = resp?.mail?.sent ?? null
                const mailFailed = resp?.mail?.failed ?? null

                bulkState.value.successCount = created
                bulkCreated.value = created
                bulkSkipped.value = skipped
                bulkMailStats.value =
                    mailSent != null || mailFailed != null ? { sent: mailSent ?? 0, failed: mailFailed ?? 0 } : null

                const baseMsg = resp?.message ?? `Se generaron ${created} cobros.`
                const mailMsg = bulkMailStats.value
                    ? ` Correos enviados: ${bulkMailStats.value.sent}, fallidos: ${bulkMailStats.value.failed}.`
                    : ''
                const skippedMsg = skipped ? ` Omitidos: ${skipped}.` : ''
                bulkMessage.value = `${baseMsg}${mailMsg}${skippedMsg}`

                await loadProveedores()
                // If you have a global "loadCobros" in this context, you can call it:
                // if (typeof loadCobros === 'function') await loadCobros()
                return
            } catch (batchErr: any) {
                if (!shouldFallbackToSingle(batchErr)) {
                    throw batchErr
                }
                // otherwise fall through to single-creation loop
            }
        }

        // Fallback: create one by one
        const failures: Array<{ proveedor: Proveedor; error: string }> = []
        let success = 0

        for (const detalle of detalles) {
            try {
                await createCobro({
                    fecha_cobro: fechaCobro,
                    mes_cobro: form.mes_cobro,
                    concepto,
                    nota,
                    proveedor_id: detalle.proveedor.id,
                    importe: detalle.importe,
                    status: 'pending',
                    receipt_pdf_base64: detalle.pdfBase64,
                })
                success += 1
            } catch (err: any) {
                failures.push({ proveedor: detalle.proveedor, error: parseErrorMessage(err) })
            }
        }

        bulkState.value.successCount = success
        bulkFailures.value = failures
        bulkCreated.value = success
        bulkSkipped.value = failures.length
        bulkMailStats.value = { sent: success, failed: failures.length }

        if (success) bulkMessage.value = `Se generaron ${success} cobros.`
        if (failures.length) bulkError.value = `${failures.length} cobros no pudieron enviarse.`

        await loadProveedores()
        // if (typeof loadCobros === 'function') await loadCobros()
    } catch (err: any) {
        bulkError.value = parseErrorMessage(err)
    } finally {
        bulkState.value.running = false
    }
}

onMounted(loadProveedores)

watch(
    () => bulkForm.value.mes_cobro,
    (newVal) => {
        if (!newVal) return
        bulkMessage.value = ''
        bulkError.value = ''
    }
)
</script>

<template>
    <div class="sm:col-span-2 xl:col-span-2 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
                <h3 class="text-base font-semibold text-gray-900">Cobros mensuales</h3>
            </div>
            <router-link :to="detailsRoute"
                class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-900/10">
                Ver más opciones
            </router-link>
        </div>

        <div class="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px] xl:grid-cols-[minmax(0,1fr)_360px] items-start">
            <div class="space-y-4 rounded-xl border border-gray-100 bg-gray-50 p-5">
                <input
                    v-model="bulkForm.mes_cobro"
                    type="month"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                    aria-label="Mes a cobrar"
                />
                <div class="space-y-1.5 text-xs text-gray-500">
                    <p>Fecha de cargo: <span class="font-semibold text-gray-800">{{ formatDateLabel(DEFAULT_FECHA_COBRO) }}</span></p>
                    <p>Concepto: <span class="font-semibold text-gray-800">{{ defaultBulkConcept(bulkForm.mes_cobro) }}</span></p>
                    <p>Notas: <span class="font-semibold text-gray-800">{{ DEFAULT_NOTA }}</span></p>
                </div>
            </div>

            <aside class="space-y-4 rounded-xl border border-gray-100 bg-gray-50 p-5">
                <div class="space-y-2">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Resumen mensual</p>
                    <div class="flex flex-col gap-2">
                        <div class="flex items-center justify-between text-sm text-gray-600">
                            <span>Proveedores programados</span>
                            <span class="font-semibold text-gray-900">{{ proveedoresConImporte.length }}</span>
                        </div>
                        <div class="flex items-center justify-between text-sm text-gray-600">
                            <span>Total estimado</span>
                            <span class="text-base font-semibold text-gray-900">{{ formatCurrency(totalMensual) }}</span>
                        </div>
                    </div>
                </div>

                <div class="space-y-2">
                    <button
                        type="button"
                        :disabled="!canRunBulk"
                        @click="runMonthlyCobros"
                        class="inline-flex w-full items-center justify-center rounded-lg bg-gray-900 px-3 py-2 text-sm font-medium text-white transition hover:bg-gray-800 disabled:cursor-not-allowed disabled:bg-gray-300"
                    >
                        <svg
                            v-if="bulkState.running"
                            class="mr-2 h-4 w-4 animate-spin"
                            xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 24 24"
                            fill="none"
                        >
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" opacity="0.25" />
                            <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="4" stroke-linecap="round" />
                        </svg>
                        <span v-if="bulkState.running">Generando cobros…</span>
                        <span v-else>Generar cobros</span>
                    </button>
                    <p class="text-center text-[11px] text-gray-500">
                        Se crearán PDFs y se enviarán al backend.
                    </p>
                </div>
            </aside>
        </div>

        <div class="mt-4 space-y-1 text-xs">
            <p v-if="loadingProveedores" class="text-gray-500">Cargando proveedores…</p>
            <p v-if="bulkMessage" class="text-emerald-700">{{ bulkMessage }}</p>
            <p v-if="proveedoresError || bulkError" class="text-rose-600">
                {{ proveedoresError || bulkError }}
            </p>
            <p v-if="bulkFailures.length" class="text-amber-600">
                Fallidos: {{ bulkFailures.length }}
            </p>
        </div>
    </div>
</template>
