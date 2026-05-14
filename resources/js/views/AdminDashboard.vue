<!-- src/pages/AdminDashboard.vue -->
<script setup lang="ts">
import { defineAsyncComponent, computed } from 'vue'
import AppLayout from '../components/layout/AppLayout.vue'
import { useAuthStore } from '../stores/auth'

// Lazy-load widgets (optional but nice for perf)
const MailerQuotaWidget = defineAsyncComponent(() => import('../components/widgets/MailerQuotaWidget.vue'))
const MonthlyCobrosWidget = defineAsyncComponent(() => import('../components/widgets/MonthlyCobrosWidget.vue'))
const CashierSummaryWidget = defineAsyncComponent(() => import('../components/widgets/CashierSummaryWidget.vue'))
const TopProductsWidget = defineAsyncComponent(() => import('../components/widgets/TopProductsWidget.vue'))
const RestockAlertsWidget = defineAsyncComponent(() => import('../components/widgets/RestockAlertsWidget.vue'))
const InventoryProposalWidget = defineAsyncComponent(() => import('../components/widgets/InventoryProposalWidget.vue'))
const HostingServicePaymentsWidget = defineAsyncComponent(() => import('../components/widgets/HostingServicePaymentsWidget.vue'))

const HOSTING_WIDGET_HOST = 'rosamexicano.on-forge.com';
const auth = useAuthStore();
const isCashier = computed(() => auth.isCashier);
const canShowCashierWidget = computed(() => isCashier.value && auth.allowedModules.includes('dashboard'));
const canShowHostingServicePaymentsWidget = computed(() => window.location.hostname === HOSTING_WIDGET_HOST);
</script>

<template>
    <AppLayout>
        <div class="p-6 space-y-4">
            <h1 class="text-xl font-semibold">Panel administrativo</h1>
            <p class="text-sm text-gray-600">
                {{
                    isCashier
                        ? 'Acceso restringido: muestra un resumen básico para cajeros.'
                        : 'Desde aquí puedes revisar proyecciones, inventario y reportes clave.'
                }}
            </p>

            <template v-if="!isCashier">
                <Suspense v-if="canShowHostingServicePaymentsWidget">
                    <HostingServicePaymentsWidget />
                    <template #fallback>
                        <div class="h-40 rounded-2xl border border-gray-200 bg-white shadow-sm animate-pulse" />
                    </template>
                </Suspense>

                <!-- Widgets row -->
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 mt-4 auto-rows-auto">
                    <Suspense>
                        <MailerQuotaWidget />
                        <template #fallback>
                            <div class="h-28 rounded-2xl border border-gray-200 bg-white shadow-sm animate-pulse" />
                        </template>
                    </Suspense>

                    <Suspense>
                        <MonthlyCobrosWidget detailsRoute="/admin/cobros" currency="MXN" locale="es-MX" />
                        <template #fallback>
                            <div class="h-28 rounded-2xl border border-gray-200 bg-white shadow-sm animate-pulse" />
                        </template>
                    </Suspense>

                    <Suspense>
                        <CashierSummaryWidget />
                        <template #fallback>
                            <div class="h-28 rounded-2xl border border-gray-200 bg-white shadow-sm animate-pulse" />
                        </template>
                    </Suspense>

                    <Suspense>
                        <TopProductsWidget />
                        <template #fallback>
                            <div class="h-28 rounded-2xl border border-gray-200 bg-white shadow-sm animate-pulse" />
                        </template>
                    </Suspense>
                    <Suspense>
                        <RestockAlertsWidget />
                        <template #fallback>
                            <div class="h-28 rounded-2xl border border-gray-200 bg-white shadow-sm animate-pulse" />
                        </template>
                    </Suspense>
                    <Suspense>
                        <InventoryProposalWidget />
                        <template #fallback>
                            <div class="h-28 rounded-2xl border border-gray-200 bg-white shadow-sm animate-pulse" />
                        </template>
                    </Suspense>
                    <!-- Future widgets go here -->
                </div>
            </template>
            <template v-else>
                <div class="space-y-4">
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                        <p class="text-sm text-gray-600">
                            Bienvenido. Consulta Caja o las secciones asignadas desde el menú lateral.
                            Las métricas avanzadas están ocultas para tu perfil.
                        </p>
                    </div>
                    <div v-if="canShowCashierWidget">
                        <Suspense>
                            <CashierSummaryWidget />
                            <template #fallback>
                                <div class="h-28 rounded-2xl border border-gray-200 bg-white shadow-sm animate-pulse" />
                            </template>
                        </Suspense>
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
