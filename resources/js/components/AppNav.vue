<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { logout as apiLogout } from '../api/auth';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const links = computed(() => {
    if (auth.isAdmin) {
        return [
            { to: { name: 'admin-dashboard' }, label: 'Dashboard' },
            // add these as you build the views/routes:
            { to: { path: '/admin/proveedores' }, label: 'Proveedores' },
            { to: { path: '/admin/productos' }, label: 'Productos' },
            { to: { path: '/admin/inventario' }, label: 'Inventario' },
            { to: { path: '/admin/caja' }, label: 'Caja / POS' },
            { to: { path: '/admin/users' }, label: 'Usuarios' },
        ];
    } else if (auth.isProvider) {
        return [
            { to: { name: 'provider-dashboard' }, label: 'Inicio' },
            { to: { path: '/provider/productos' }, label: 'Mis productos' },
            { to: { path: '/provider/inventario' }, label: 'Mi inventario' },
        ];
    }
    return [];
});

function isActive(to: any) {
    // basic active matcher (name preferred, fallback to path)
    if (to.name && route.name === to.name) return true;
    if (to.path && route.path.startsWith(to.path)) return true;
    return false;
}

async function doLogout() {
    try { await apiLogout(); } catch { }
    auth.logout();
    router.push({ name: 'login' });
}
</script>

<template>
    <nav class="w-full bg-white border-b">
        <div class="mx-auto max-w-6xl px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-6">
                <span class="font-semibold">POS</span>
                <ul class="hidden md:flex items-center gap-4">
                    <li v-for="link in links" :key="link.label">
                        <RouterLink :to="link.to" class="px-2 py-1 rounded hover:bg-gray-100"
                            :class="isActive(link.to) ? 'text-black font-medium' : 'text-gray-600'">
                            {{ link.label }}
                        </RouterLink>
                    </li>
                </ul>
            </div>

            <div class="flex items-center gap-3">
                <span class="text-sm text-gray-600">
                    {{ auth.displayName }}
                    <span v-if="auth.isAdmin" class="ml-1 text-xs text-gray-400">(admin)</span>
                    <span v-else-if="auth.isProvider" class="ml-1 text-xs text-gray-400">(proveedor)</span>
                </span>
                <button @click="doLogout" class="border rounded px-3 py-1 hover:bg-gray-50">Salir</button>
            </div>
        </div>
    </nav>
</template>
