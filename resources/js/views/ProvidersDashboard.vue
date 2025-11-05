<!-- src/views/ProviderDashboard.vue -->
<AppNav />
<script setup lang="ts">
import AppLayout from '../components/layout/AppLayout.vue';

import { useAuthStore } from '../stores/auth';
import { useRouter } from 'vue-router';
import { logout } from '../api/auth';
const auth = useAuthStore();
const router = useRouter();
async function doLogout() { await logout(); auth.logout(); router.push({ name: 'login' }); }
</script>

<template>
    <AppLayout>

        <div class="p-6 space-y-4">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Panel de Proveedor</h1>
                <div class="flex items-center gap-3">
                    <span class="text-sm text-gray-600">Hola, {{ auth.displayName }}</span>
                    <button @click="doLogout" class="border px-3 py-1 rounded">Salir</button>
                </div>
            </div>
            <p>Aquí verás tus productos e inventario (endpoints /proveedores/{id}/productos e /inventario).</p>
        </div>
    </AppLayout>

</template>
