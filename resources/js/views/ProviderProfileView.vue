<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import AppLayout from '../components/layout/AppLayout.vue';
import { storeToRefs } from 'pinia';
import { useAuthStore } from '../stores/auth';
import { updateProviderProfile } from '../api/proveedores';

const auth = useAuthStore();
const { provider } = storeToRefs(auth);
const form = reactive({
    email: '',
    tel: '',
});
const saving = ref(false);
const formError = ref('');
const formSuccess = ref('');
const canSubmit = computed(() => !!provider.value && !saving.value);

onMounted(async () => {
    if (!auth.provider) {
        await auth.hydrateFromToken();
    }
});

watch(
    provider,
    (value) => {
        form.email = value?.email ?? '';
        form.tel = value?.tel ?? '';
    },
    { immediate: true }
);

async function saveContact() {
    if (!provider.value) {
        formError.value = 'No se pudo cargar tu perfil. Intenta de nuevo.';
        return;
    }

    saving.value = true;
    formError.value = '';
    formSuccess.value = '';
    try {
        const payload = {
            email: form.email?.trim() ? form.email.trim() : null,
            tel: form.tel?.trim() ? form.tel.trim() : null,
        };
        const updated = await updateProviderProfile(payload);
        const current = auth.provider;
        const merged = {
            ...(current ?? {}),
            ...updated,
            id: updated.id ?? current?.id ?? 0,
            ident: Number(updated.ident ?? current?.ident ?? 0),
            nombre: updated.nombre ?? current?.nombre ?? '',
            tel: updated.tel ?? current?.tel ?? '',
            email: updated.email ?? current?.email ?? null,
        };
        auth.provider = merged as NonNullable<typeof auth.provider>;
        form.email = merged.email ?? '';
        form.tel = merged.tel ?? '';
        formSuccess.value = 'Tus datos de contacto se actualizaron correctamente.';
        setTimeout(() => {
            window.location.reload();
        }, 800);
    } catch (err: any) {
        formError.value = err?.response?.data?.message || 'No se pudo actualizar la información.';
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppLayout>
        <div class="p-6 space-y-6">
            <header>
                <h1 class="text-xl font-semibold text-gray-900">Perfil del proveedor</h1>
                <p class="text-sm text-gray-500">
                    Consulta tu información y actualiza el correo o teléfono asociado a tu cuenta.
                </p>
            </header>

            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm space-y-4">
                <div>
                    <h2 class="text-base font-semibold text-gray-800">Datos del proveedor</h2>
                    <p class="text-sm text-gray-500">Nombre e identificador no pueden modificarse desde esta vista.</p>
                </div>
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Nombre</dt>
                        <dd class="text-base text-gray-900">{{ provider?.nombre ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Ident</dt>
                        <dd class="text-base text-gray-900">{{ provider?.ident ?? '—' }}</dd>
                    </div>
                </dl>
                <form class="space-y-4" @submit.prevent="saveContact">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <label class="flex flex-col text-sm text-gray-700">
                            <span class="mb-1 font-medium">Correo electrónico</span>
                            <input
                                type="email"
                                v-model="form.email"
                                class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[#E4007C] focus:ring-[#E4007C]"
                                :disabled="saving || !provider"
                                placeholder="correo@ejemplo.com"
                            />
                        </label>
                        <label class="flex flex-col text-sm text-gray-700">
                            <span class="mb-1 font-medium">Teléfono</span>
                            <input
                                type="tel"
                                v-model="form.tel"
                                class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[#E4007C] focus:ring-[#E4007C]"
                                :disabled="saving || !provider"
                                placeholder="55 0000 0000"
                            />
                        </label>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <button
                            type="submit"
                            class="rounded-lg bg-[#E4007C] px-4 py-2 text-sm font-medium text-white hover:bg-[#cc006f] disabled:opacity-60 disabled:cursor-not-allowed"
                            :disabled="!canSubmit"
                        >
                            {{ saving ? 'Guardando…' : 'Guardar cambios' }}
                        </button>
                        <span class="text-xs text-gray-500">
                            Estos datos se usarán para comunicación por parte del equipo administrativo.
                        </span>
                    </div>
                    <p
                        v-if="formError"
                        class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700"
                    >
                        {{ formError }}
                    </p>
                    <p
                        v-if="formSuccess"
                        class="rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700"
                    >
                        {{ formSuccess }}
                    </p>
                </form>
            </section>

            <section class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800 space-y-2">
                <p class="font-semibold">¿Necesitas ayuda?</p>
                <p>
                    Para cambios adicionales como razón social, dirección o datos bancarios comunícate directamente con
                    el administrador.
                </p>
            </section>
        </div>
    </AppLayout>
</template>
