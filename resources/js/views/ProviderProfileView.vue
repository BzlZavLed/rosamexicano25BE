<script setup lang="ts">
import { onMounted } from 'vue';
import AppLayout from '../components/layout/AppLayout.vue';
import { storeToRefs } from 'pinia';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const { provider } = storeToRefs(auth);

onMounted(async () => {
    if (!auth.provider) {
        await auth.hydrateFromToken();
    }
});
</script>

<template>
    <AppLayout>
        <div class="p-6 space-y-6">
            <header>
                <h1 class="text-xl font-semibold text-gray-900">Perfil del proveedor</h1>
                <p class="text-sm text-gray-500">
                    Información clave del proveedor registrado en el sistema. Si necesitas actualizar tus datos comunícate
                    con el administrador.
                </p>
            </header>

            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Nombre</dt>
                        <dd class="text-base text-gray-900">{{ provider?.nombre ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Ident</dt>
                        <dd class="text-base text-gray-900">{{ provider?.ident ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Teléfono registrado</dt>
                        <dd class="text-base text-gray-900">{{ provider?.tel ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Correo de contacto</dt>
                        <dd class="text-base text-gray-900">{{ provider?.email ?? '—' }}</dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800 space-y-2">
                <p class="font-semibold">¿Necesitas ayuda?</p>
                <p>Comunícate con el administrador para restablecer tu contraseña o actualizar tus datos de contacto.</p>
            </section>
        </div>
    </AppLayout>
</template>
