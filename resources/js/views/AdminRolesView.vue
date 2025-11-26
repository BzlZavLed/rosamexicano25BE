<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import AppLayout from '../components/layout/AppLayout.vue';
import {
    listStaffRoles,
    createStaffRole,
    updateStaffRole,
    deleteStaffRole,
    type StaffRole,
} from '../api/staffRoles';
import { STAFF_MODULES, type StaffModuleValue } from '../constants/staffModules';

const roles = ref<StaffRole[]>([]);
const loading = ref(false);
const error = ref('');
const saving = ref(false);
const formError = ref('');

const formMode = ref<'create' | 'edit'>('create');
const form = reactive<{
    id: number | null;
    name: string;
    slug: string;
    base_role: 'admin' | 'cashier';
    modules: StaffModuleValue[];
    is_default: boolean;
}>({
    id: null,
    name: '',
    slug: '',
    base_role: 'cashier',
    modules: ['caja'],
    is_default: false,
});

const moduleOptions = STAFF_MODULES;

const adminRoles = computed(() => roles.value.filter((role) => role.base_role === 'admin'));
const employeeRoles = computed(() => roles.value.filter((role) => role.base_role === 'cashier'));

function defaultModules(baseRole: 'admin' | 'cashier'): StaffModuleValue[] {
    if (baseRole === 'admin') {
        return moduleOptions.map((option) => option.value) as StaffModuleValue[];
    }
    return ['caja'] as StaffModuleValue[];
}

async function fetchRoles() {
    loading.value = true;
    error.value = '';
    try {
        roles.value = await listStaffRoles();
    } catch (err: any) {
        error.value = err?.response?.data?.message || err?.message || 'No se pudieron cargar los perfiles.';
        roles.value = [];
    } finally {
        loading.value = false;
    }
}

function resetForm() {
    formMode.value = 'create';
    form.id = null;
    form.name = '';
    form.slug = '';
    form.base_role = 'cashier';
    form.modules = defaultModules('cashier');
    form.is_default = false;
    formError.value = '';
}

function editRole(role: StaffRole) {
    formMode.value = 'edit';
    form.id = role.id;
    form.name = role.name;
    form.slug = role.slug;
    form.base_role = role.base_role;
    form.modules = role.modules ? ([...role.modules] as StaffModuleValue[]) : defaultModules(role.base_role);
    form.is_default = role.is_default;
    formError.value = '';
}

function toggleModule(value: StaffModuleValue) {
    if (form.modules.includes(value)) {
        form.modules = form.modules.filter((module) => module !== value) as StaffModuleValue[];
    } else {
        form.modules = [...form.modules, value] as StaffModuleValue[];
    }
}

async function submitForm() {
    saving.value = true;
    formError.value = '';
    try {
        const payload = {
            name: form.name,
            slug: form.slug || undefined,
            base_role: form.base_role,
            modules: form.modules,
            is_default: form.is_default,
        };
        if (formMode.value === 'create') {
            await createStaffRole(payload);
        } else if (form.id) {
            await updateStaffRole(form.id, payload);
        }
        resetForm();
        await fetchRoles();
    } catch (err: any) {
        formError.value = err?.response?.data?.message || err?.message || 'No se pudo guardar el perfil.';
    } finally {
        saving.value = false;
    }
}

async function removeRole(role: StaffRole) {
    if (!window.confirm(`¿Eliminar el perfil "${role.name}"?`)) return;
    try {
        await deleteStaffRole(role.id);
        if (form.id === role.id) {
            resetForm();
        }
        await fetchRoles();
    } catch (err: any) {
        window.alert(err?.response?.data?.message || err?.message || 'No se pudo eliminar el perfil.');
    }
}

function roleModulesLabel(role: StaffRole) {
    if (role.modules?.length === moduleOptions.length) {
        return 'Todos los módulos';
    }
    if (!role.modules?.length) {
        return 'Sin módulos configurados';
    }
    return role.modules.join(', ');
}

onMounted(fetchRoles);
</script>

<template>
    <AppLayout>
        <div class="space-y-6">
            <header class="space-y-1">
                <p class="text-xs uppercase tracking-wide text-gray-500">Perfiles disponibles</p>
                <h1 class="text-xl font-semibold text-gray-900">Perfiles de acceso</h1>
                <p class="text-sm text-gray-500">
                    Agrupa los módulos por perfil (administradores o personal operativo) y reutiliza estas configuraciones al crear nuevos usuarios.
                </p>
            </header>

            <div class="grid gap-6 lg:grid-cols-[2fr,1fr]">
                <section class="space-y-4">
                    <div
                        class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm space-y-3"
                        v-for="group in [
                            { label: 'Perfiles para administradores', hint: 'Define qué módulos tendrán los usuarios de tipo administrador.', items: adminRoles },
                            { label: 'Perfiles para personal operativo', hint: 'Controla el acceso de cajeros y personal de piso.', items: employeeRoles },
                        ]"
                        :key="group.label"
                    >
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-sm font-semibold text-gray-700">{{ group.label }}</h2>
                                <p v-if="group.hint" class="text-xs text-gray-500">{{ group.hint }}</p>
                            </div>
                            <span class="text-xs text-gray-400">{{ group.items.length }} perfiles</span>
                        </div>
                        <div v-if="group.items.length" class="space-y-3">
                            <article
                                v-for="role in group.items"
                                :key="role.id"
                                class="rounded-xl border border-gray-100 bg-gray-50 px-3 py-2 text-sm"
                            >
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="font-semibold text-gray-900">
                                            {{ role.name }}
                                            <span
                                                v-if="role.is_default"
                                                class="ml-2 rounded-full bg-gray-800/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-700"
                                            >
                                                Predeterminado
                                            </span>
                                        </p>
                                        <p class="text-xs text-gray-500">{{ roleModulesLabel(role) }}</p>
                                    </div>
                                    <div class="space-x-2 text-xs">
                                        <button class="font-semibold text-indigo-600 hover:underline" @click="editRole(role)">
                                            Editar
                                        </button>
                                        <button class="font-semibold text-rose-600 hover:underline" @click="removeRole(role)">
                                            Eliminar
                                        </button>
                                    </div>
                                </div>
                            </article>
                        </div>
                        <p v-else class="text-sm text-gray-500">Aún no hay perfiles para esta categoría.</p>
                    </div>
                    <div v-if="error" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                        {{ error }}
                    </div>
                </section>

                <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
                    <header class="space-y-1">
                        <p class="text-xs uppercase tracking-wide text-gray-500">
                            {{ formMode === 'create' ? 'Nuevo rol' : 'Editar rol' }}
                        </p>
                        <h2 class="text-lg font-semibold text-gray-900">
                            {{ formMode === 'create' ? 'Configurar rol personalizado' : 'Actualizar rol' }}
                        </h2>
                        <p class="text-xs text-gray-500">
                            Define los módulos que incluye este rol y a qué perfil (admin o personal operativo) pertenece.
                        </p>
                    </header>
                    <form class="space-y-4" @submit.prevent="submitForm">
                        <label class="block text-sm">
                            <span class="font-medium text-gray-700">Nombre</span>
                            <input
                                v-model="form.name"
                                type="text"
                                required
                                class="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                            />
                        </label>
                        <label class="block text-sm">
                            <span class="font-medium text-gray-700">Identificador (slug)</span>
                            <input
                                v-model="form.slug"
                                type="text"
                                placeholder="roles-admin"
                                class="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                            />
                            <span class="text-xs text-gray-500">Se genera automáticamente si se deja vacío.</span>
                        </label>
                        <label class="block text-sm">
                            <span class="font-medium text-gray-700">Tipo de usuario</span>
                            <select
                                v-model="form.base_role"
                                class="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                                @change="form.modules = defaultModules(form.base_role)"
                            >
                                <option value="admin">Administrador</option>
                                <option value="cashier">Cajero</option>
                            </select>
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input
                                v-model="form.is_default"
                                type="checkbox"
                                class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
                            />
                            <span>
                                Usar como predeterminado para este tipo de usuario
                                <span class="block text-[11px] text-gray-500 font-normal">
                                    Si está activo, se aplicará este rol automáticamente cuando crees un nuevo usuario de este perfil.
                                </span>
                            </span>
                        </label>
                        <div class="space-y-2 text-sm">
                            <p class="font-medium text-gray-700">Módulos incluidos</p>
                            <div class="grid grid-cols-2 gap-2 text-xs text-gray-600">
                                <label
                                    v-for="option in moduleOptions"
                                    :key="option.value"
                                    class="inline-flex items-center gap-2 rounded border border-gray-200 px-2 py-1"
                                >
                                    <input
                                        type="checkbox"
                                        class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
                                        :value="option.value"
                                        :checked="form.modules.includes(option.value)"
                                        @change="toggleModule(option.value)"
                                    />
                                    <span>{{ option.label }}</span>
                                </label>
                            </div>
                        </div>
                        <div v-if="formError" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                            {{ formError }}
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="submit"
                                class="inline-flex items-center rounded bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-60"
                                :disabled="saving"
                            >
                                {{ saving ? 'Guardando…' : formMode === 'create' ? 'Crear perfil' : 'Guardar cambios' }}
                            </button>
                            <button
                                v-if="formMode === 'edit'"
                                type="button"
                                class="inline-flex items-center rounded border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                @click="resetForm"
                            >
                                Cancelar edición
                            </button>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
