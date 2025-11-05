<!-- src/pages/AdminDashboard.vue -->
<script setup lang="ts">
import { defineAsyncComponent } from 'vue'
import AppLayout from '../components/layout/AppLayout.vue'

// Lazy-load widgets (optional but nice for perf)
const MailerQuotaWidget = defineAsyncComponent(() => import('../components/widgets/MailerQuotaWidget.vue'))
const MonthlyCobrosWidget = defineAsyncComponent(() => import('../components/widgets/MonthlyCobrosWidget.vue'))
const CashierSummaryWidget = defineAsyncComponent(() => import('../components/widgets/CashierSummaryWidget.vue'))
const TopProductsWidget = defineAsyncComponent(() => import('../components/widgets/TopProductsWidget.vue'))
</script>

<template>
    <AppLayout>
        <div class="p-6 space-y-4">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Admin Dashboard</h1>
            </div>
            <p>Desde aquí podrás acceder a Proveedores, Productos, Inventario, Caja y Ventas.</p>

            <!-- Widgets row -->
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 mt-4 auto-rows-auto">
                <Suspense>
                    <MailerQuotaWidget />
                    <template #fallback>
                        <div class="h-28 rounded-2xl border border-gray-200 bg-white shadow-sm animate-pulse" />
                    </template>
                </Suspense>

                <Suspense>
                    <MonthlyCobrosWidget
                        detailsRoute="/admin/cobros"
                        currency="MXN"
                        locale="es-MX"
                    />
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

                <!-- Future widgets go here -->
            </div>
        </div>
    </AppLayout>
</template>
