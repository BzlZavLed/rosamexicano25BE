<script setup lang="ts">
import SidebarItem from './SidebarItem.vue';
</script>

<template>
    <nav class="space-y-5">
        <section>
            <p class="px-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Dashboard</p>
            <SidebarItem :to="{ path: '/dashboard' }" label="Inicio">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 11l9-7 9 7M5 10v10h14V10" />
            </svg>
        </SidebarItem>
        </section>

        <section>
            <p class="px-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Caja</p>
            <SidebarItem :to="{ path: '/admin/caja' }" label="Caja">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                aria-hidden="true">
                <!-- top: receipt tray + display -->
                <rect x="7" y="4" width="5" height="3" rx="0.5" stroke-linejoin="round" />
                <rect x="14" y="4" width="4" height="3" rx="0.5" stroke-linejoin="round" />
                <!-- body -->
                <rect x="2" y="9" width="20" height="10" rx="2" />
                <!-- keypad / controls -->
                <path stroke-linecap="round" d="M6 12h4M6 14h4M12 12h6" />
                <!-- drawer handle -->
                <rect x="9" y="14.5" width="6" height="3" rx="1" />
            </svg>
        </SidebarItem>
        </section>

        <section>
            <p class="px-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Inventario</p>
            <div class="space-y-1 border-l border-gray-200 pl-4">
                <SidebarItem :to="{ path: '/admin/inventario/entrada' }" label="Entradas">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M15 17h5l-1.4-1.4A2 2 0 0118 14.17V11a6 6 0 10-12 0v3.17a2 2 0 01-.6 1.43L4 17h5m6 0a3 3 0 11-6 0" />
            </svg>
        </SidebarItem>
                <SidebarItem :to="{ path: '/admin/inventario/reporte' }" label="Reporte de inventario">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 11l7-7 7 7M5 18h14" />
            </svg>
        </SidebarItem>
            </div>
        </section>

        <section>
            <p class="px-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Productos</p>
            <div class="space-y-1 border-l border-gray-200 pl-4">
                <SidebarItem :to="{ path: '/admin/productos/crear' }" label="Crear producto">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 11a3 3 0 100-6 3 3 0 000 6z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 22s7-6 7-11a7 7 0 10-14 0c0 5 7 11 7 11z" />
            </svg>
        </SidebarItem>
                <SidebarItem :to="{ path: '/admin/promociones' }" label="Promociones">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path d="M12 5v14M5 12h14" />
            </svg>
        </SidebarItem>
            </div>
        </section>

        <section>
            <p class="px-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Proveedores</p>
            <div class="space-y-1 border-l border-gray-200 pl-4">
                <SidebarItem :to="{ path: '/admin/proveedores' }" label="Crear proveedor">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14" />
            </svg>
        </SidebarItem>
                <SidebarItem :to="{ path: '/admin/cobros' }" label="Crear cobros">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <rect x="3" y="5" width="18" height="14" rx="2" />
                <path d="M3 9h18" />
            </svg>
        </SidebarItem>
            </div>
        </section>

        <section>
            <p class="px-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Emails</p>
            <SidebarItem :to="{ path: '/admin/emails' }" label="Historial de emails">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path d="M4 6h16v12H4z" />
                <path d="M22 6l-10 7L2 6" />
            </svg>
        </SidebarItem>
        </section>

        <section>
            <p class="px-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Reportes</p>
            <div class="space-y-1 border-l border-gray-200 pl-4">
                <SidebarItem :to="{ path: '/admin/reportes' }" label="Reportes condensados">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </SidebarItem>
                <SidebarItem :to="{ path: '/admin/analisis' }" label="Análisis histórico">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 19h16M4 12h16M4 5h16" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5v14M15 5v14" />
            </svg>
        </SidebarItem>
            </div>
        </section>

    </nav>
</template>
