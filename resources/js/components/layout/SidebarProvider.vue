<script setup lang="ts">
import SidebarItem from './SidebarItem.vue';
</script>

<template>
  <nav class="space-y-2">
    <div class="px-3 pt-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Mi panel</div>

    <SidebarItem :to="{ path: '/provider/productos' }" label="Mis productos">
      <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
        <path d="M21 16V8a2 2 0 0 0-1-1.73L13 2.27a2 2 0 0 0-2 0L4 6.27A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
      </svg>
    </SidebarItem>

    <SidebarItem :to="{ path: '/provider/inventario' }" label="Mi inventario">
      <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
        <rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>
      </svg>
    </SidebarItem>

    <SidebarItem :to="{ path: '/provider/reportes' }" label="Reportes">
      <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
        <path d="M3 4h18v16H3z"/><path d="M8 10h8M8 14h5"/>
      </svg>
    </SidebarItem>

    <SidebarItem :to="{ path: '/provider/perfil' }" label="Perfil">
      <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
        <path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10z"/><path d="M20 21a8 8 0 1 0-16 0"/>
      </svg>
    </SidebarItem>
  </nav>
</template>
