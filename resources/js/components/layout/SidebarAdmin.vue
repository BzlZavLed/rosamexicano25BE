<script setup lang="ts">
import SidebarItem from './SidebarItem.vue';
</script>

<template>
    <nav class="space-y-2">
        <div class="px-3 pt-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Dashboard</div>
        <SidebarItem :to="{ path: '/dashboard' }" label="Dashboard">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 11l9-7 9 7M5 10v10h14V10" />
            </svg>
        </SidebarItem>
        <div class="px-3 pt-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Caja</div>
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

        <div class="mt-4 px-3 pt-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Productos</div>
        <SidebarItem :to="{ path: '/admin/productos/crear' }" label="Crear producto">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 11a3 3 0 100-6 3 3 0 000 6z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 22s7-6 7-11a7 7 0 10-14 0c0 5 7 11 7 11z" />
            </svg>
        </SidebarItem>
        <SidebarItem :to="{ path: '/admin/inventario/entrada' }" label="Entrada de producto">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M15 17h5l-1.4-1.4A2 2 0 0118 14.17V11a6 6 0 10-12 0v3.17a2 2 0 01-.6 1.43L4 17h5m6 0a3 3 0 11-6 0" />
            </svg>
        </SidebarItem>
        <SidebarItem :to="{ path: '/admin/promociones' }" label="Promociones">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path d="M12 5v14M5 12h14" />
            </svg>
        </SidebarItem>
        <div class="mt-4 px-3 pt-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Operación</div>
        <SidebarItem :to="{ path: '/admin/proveedores' }" label="Proveedores">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M16 14a4 4 0 10-8 0v5h8v-5zM12 7a3 3 0 110-6 3 3 0 010 6z" />
            </svg>
        </SidebarItem>
        <SidebarItem :to="{ path: '/admin/reportes' }" label="Generar reportes">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h10M4 18h16" />
            </svg>
        </SidebarItem>
        <SidebarItem :to="{ path: '/admin/cobros' }" label="Crear cobros">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <rect x="3" y="5" width="18" height="14" rx="2" />
                <path d="M3 9h18" />
            </svg>
        </SidebarItem>
        <SidebarItem :to="{ path: '/admin/emails' }" label="Historial de emails">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path d="M4 6h16v12H4z" />
                <path d="M22 6l-10 7L2 6" />
            </svg>
        </SidebarItem>
        <SidebarItem :to="{ path: '/admin/respaldo' }" label="Respaldar">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path d="M20 16.58A5 5 0 0018 7h-1.26A8 8 0 104 15.25" />
                <path d="M12 12v9m0 0l-3-3m3 3l3-3" />
            </svg>
        </SidebarItem>
        <SidebarItem :to="{ path: '/admin/clientes' }" label="Clientes">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path d="M17 21v-2a4 4 0 00-4-4H7a4 4 0 00-4 4v2" />
                <circle cx="9" cy="7" r="3" />
                <path d="M23 21v-2a4 4 0 00-3-3.87" />
                <path d="M16 3.13a3 3 0 010 5.75" />
            </svg>
        </SidebarItem>

    </nav>
</template>
