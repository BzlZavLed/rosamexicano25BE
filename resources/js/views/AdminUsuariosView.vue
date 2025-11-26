<script setup lang="ts">
import { onMounted, ref, reactive, watch, computed } from 'vue';
import AppLayout from '../components/layout/AppLayout.vue';
import {
    listAdminUsers,
    createAdminUser,
    updateAdminUser,
    deleteAdminUser,
    type AdminUser,
} from '../api/adminUsers';
import { listStaffRoles, type StaffRole } from '../api/staffRoles';
import { STAFF_MODULES, type StaffModuleValue } from '../constants/staffModules';

const users = ref<AdminUser[]>([]);
const loading = ref(false);
const error = ref('');
const search = ref('');
const page = ref(1);
const perPage = ref(25);
const total = ref(0);
const staffRoles = ref<StaffRole[]>([]);
const staffRolesError = ref('');

const formMode = ref<'create' | 'edit'>('create');
const editingId = ref<number | null>(null);
const form = reactive({
    nombre: '',
    email: '',
    password: '',
    role: 'admin' as 'admin' | 'cashier',
    modules: [] as StaffModuleValue[],
    staff_role_id: null as number | null,
});
const formError = ref('');
const saving = ref(false);

const moduleOptions = STAFF_MODULES;
const allModuleValues = moduleOptions.map((opt) => opt.value) as StaffModuleValue[];
const availableStaffRoles = computed(() =>
    staffRoles.value.filter((role) => role.base_role === form.role)
);

function defaultModulesForRole(role: 'admin' | 'cashier'): StaffModuleValue[] {
    if (role === 'admin') {
        return [...allModuleValues] as StaffModuleValue[];
    }
    return ['caja'] as StaffModuleValue[];
}

function formatUserModules(user: AdminUser) {
    const sourceModules =
        (user.staff_role?.modules?.length ? user.staff_role.modules : null) ??
        (user.modules ?? []);

    if (user.staff_role?.modules?.length) {
        return `${user.staff_role.name}: ${user.staff_role.modules.join(', ')}`;
    }

    if (!sourceModules.length) {
        return user.role === 'admin' ? 'Todos' : '—';
    }
    const unique = Array.from(new Set(sourceModules));
    const hasAll = allModuleValues.every((mod) => unique.includes(mod));
    return hasAll ? 'Todos' : unique.join(', ');
}

async function fetchUsers() {
    loading.value = true;
    error.value = '';
    try {
        const response = await listAdminUsers({
            search: search.value || undefined,
            page: page.value,
            per_page: perPage.value,
        });
        users.value = response.data;
        total.value = response.meta?.total ?? response.data.length ?? 0;
    } catch (err: any) {
        error.value = err?.response?.data?.message || err?.message || 'No se pudieron cargar los usuarios.';
        users.value = [];
    } finally {
        loading.value = false;
    }
}

async function fetchStaffRoles() {
    staffRolesError.value = '';
    try {
        staffRoles.value = await listStaffRoles();
    } catch (err: any) {
        staffRolesError.value = err?.response?.data?.message || err?.message || 'No se pudieron cargar los perfiles de acceso.';
        staffRoles.value = [];
    }
}

function resetForm() {
    form.nombre = '';
    form.email = '';
    form.password = '';
    form.role = 'admin';
    form.modules = defaultModulesForRole('admin');
    form.staff_role_id = null;
    editingId.value = null;
    formMode.value = 'create';
    formError.value = '';
}

function editUser(user: AdminUser) {
    formMode.value = 'edit';
    editingId.value = user.id;
    form.nombre = user.nombre;
    form.email = user.email;
    form.password = '';
    form.role = user.role;
    if (user.staff_role_id && user.staff_role?.modules) {
        form.staff_role_id = user.staff_role_id;
        form.modules = [...user.staff_role.modules] as StaffModuleValue[];
    } else if (user.modules?.length) {
        form.staff_role_id = null;
        form.modules = [...user.modules] as StaffModuleValue[];
    } else {
        form.staff_role_id = null;
        form.modules = defaultModulesForRole(user.role);
    }
    formError.value = '';
}

async function submitForm() {
    saving.value = true;
    formError.value = '';
    try {
        if (formMode.value === 'create') {
            await createAdminUser({
                nombre: form.nombre,
                email: form.email,
                password: form.password,
                role: form.role,
                staff_role_id: form.staff_role_id ?? undefined,
                ...(form.staff_role_id ? {} : { modules: form.modules }),
            });
        } else if (editingId.value !== null) {
            await updateAdminUser(editingId.value, {
                nombre: form.nombre,
                email: form.email,
                password: form.password || undefined,
                role: form.role,
                staff_role_id: form.staff_role_id ?? undefined,
                ...(form.staff_role_id ? {} : { modules: form.modules }),
            });
        }
        resetForm();
        await fetchUsers();
    } catch (err: any) {
        formError.value = err?.response?.data?.message || err?.message || 'No se pudo guardar.';
    } finally {
        saving.value = false;
    }
}

async function removeUser(user: AdminUser) {
    if (!window.confirm(`¿Eliminar al usuario ${user.nombre}?`)) return;
    try {
        await deleteAdminUser(user.id);
        await fetchUsers();
    } catch (err: any) {
        window.alert(err?.response?.data?.message || err?.message || 'No se pudo eliminar.');
    }
}

function toggleModule(value: StaffModuleValue) {
    if (form.modules.includes(value)) {
        form.modules = form.modules.filter((module) => module !== value);
    } else {
        form.modules = [...form.modules, value];
    }
}

watch(
    () => form.role,
    (role) => {
        const roleProfile = form.staff_role_id
            ? staffRoles.value.find((item) => item.id === form.staff_role_id)
            : null;
        if (roleProfile && roleProfile.base_role !== role) {
            form.staff_role_id = null;
        }

        if (form.staff_role_id) {
            const profile = staffRoles.value.find((item) => item.id === form.staff_role_id);
            form.modules = profile?.modules ? ([...profile.modules] as StaffModuleValue[]) : defaultModulesForRole(role);
        } else {
            form.modules = form.modules.filter((module) => allModuleValues.includes(module)) as StaffModuleValue[];
            if (!form.modules.length) {
                form.modules = defaultModulesForRole(role);
            }
        }
    }
);

watch(
    () => form.staff_role_id,
    (id) => {
        if (!id) {
            form.modules = form.modules.filter((module) => allModuleValues.includes(module)) as StaffModuleValue[];
            if (!form.modules.length) {
                form.modules = defaultModulesForRole(form.role);
            }
            return;
        }
        const profile = staffRoles.value.find((item) => item.id === id);
        if (profile) {
            form.modules = profile.modules ? ([...profile.modules] as StaffModuleValue[]) : defaultModulesForRole(form.role);
            if (profile.base_role !== form.role) {
                form.role = profile.base_role;
            }
        }
    }
);

onMounted(async () => {
    await fetchStaffRoles();
    await fetchUsers();
});
</script>

<template>
    <AppLayout>
        <div class="space-y-6">
            <header class="space-y-1">
                <h1 class="text-xl font-semibold text-gray-900">Administrar usuarios</h1>
                <p class="text-sm text-gray-500">Crea perfiles administrativos y de cajero y asigna módulos disponibles.</p>
            </header>

            <div class="grid gap-6 lg:grid-cols-[2fr,1fr]">
                <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3 text-sm text-gray-600">
                        <label class="flex items-center gap-2">
                            <span class="font-medium text-gray-700">Buscar</span>
                            <input
                                v-model="search"
                                type="search"
                                placeholder="Nombre o correo…"
                                class="w-60 rounded border border-gray-300 px-3 py-1.5 text-sm focus:border-gray-900 focus:ring-gray-900"
                            />
                        </label>
                        <button
                            type="button"
                            class="inline-flex items-center rounded border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50"
                            @click="fetchUsers"
                        >
                            Actualizar
                        </button>
                    </div>
                    <div v-if="error" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                        {{ error }}
                    </div>
                    <div v-else>
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-3 py-2 text-left">Nombre</th>
                                    <th class="px-3 py-2 text-left">Correo</th>
                                    <th class="px-3 py-2 text-left">Rol</th>
                                    <th class="px-3 py-2 text-left">Perfil</th>
                                    <th class="px-3 py-2 text-left">Módulos</th>
                                    <th class="px-3 py-2 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="user in users" :key="user.id">
                                    <td class="px-3 py-2 font-medium text-gray-900">{{ user.nombre }}</td>
                                    <td class="px-3 py-2">{{ user.email }}</td>
                                    <td class="px-3 py-2 capitalize">{{ user.role }}</td>
                                    <td class="px-3 py-2">
                                        {{ user.staff_role?.name ?? 'Personalizado' }}
                                    </td>
                                    <td class="px-3 py-2">
                                        {{ formatUserModules(user) }}
                                    </td>
                                    <td class="px-3 py-2 text-right space-x-2">
                                        <button
                                            type="button"
                                            class="text-xs font-semibold text-indigo-600 hover:underline"
                                            @click="editUser(user)"
                                        >
                                            Editar
                                        </button>
                                        <button
                                            type="button"
                                            class="text-xs font-semibold text-rose-600 hover:underline"
                                            @click="removeUser(user)"
                                        >
                                            Eliminar
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="!users.length">
                                    <td colspan="5" class="px-3 py-4 text-center text-gray-500 text-xs">
                                        No hay usuarios para mostrar.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
                    <header class="space-y-1">
                        <p class="text-xs uppercase tracking-wide text-gray-500">{{ formMode === 'create' ? 'Nuevo usuario' : 'Editar usuario' }}</p>
                        <h2 class="text-lg font-semibold text-gray-900">
                            {{ formMode === 'create' ? 'Agregar usuario' : 'Actualizar usuario' }}
                        </h2>
                    </header>
                    <form class="space-y-4" @submit.prevent="submitForm">
                        <label class="block text-sm">
                            <span class="font-medium text-gray-700">Nombre</span>
                            <input
                                v-model="form.nombre"
                                type="text"
                                required
                                class="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                            />
                        </label>
                        <label class="block text-sm">
                            <span class="font-medium text-gray-700">Correo</span>
                            <input
                                v-model="form.email"
                                type="email"
                                required
                                class="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                            />
                        </label>
                        <label class="block text-sm">
                            <span class="font-medium text-gray-700">Contraseña</span>
                            <input
                                v-model="form.password"
                                :required="formMode === 'create'"
                                type="password"
                                class="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                                placeholder="••••••••"
                            />
                            <span v-if="formMode === 'edit'" class="text-xs text-gray-500">Deja en blanco para mantener la contraseña actual.</span>
                        </label>
                        <label class="block text-sm">
                            <span class="font-medium text-gray-700">Rol</span>
                            <select
                                v-model="form.role"
                                class="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                            >
                                <option value="admin">Administrador</option>
                                <option value="cashier">Cajero</option>
                            </select>
                        </label>
                        <label class="block text-sm">
                            <span class="font-medium text-gray-700">Perfil de acceso</span>
                            <select
                                v-model="form.staff_role_id"
                                class="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                            >
                                <option :value="null">Sin perfil (configuración personalizada)</option>
                                <option v-for="roleOption in availableStaffRoles" :key="roleOption.id" :value="roleOption.id">
                                    {{ roleOption.name }}
                                </option>
                            </select>
                            <span v-if="staffRolesError" class="text-xs text-rose-600">{{ staffRolesError }}</span>
                        </label>
                        <div class="space-y-2 text-sm">
                            <div class="flex items-center justify-between">
                                <p class="font-medium text-gray-700">Módulos permitidos</p>
                                <span class="text-xs text-gray-500">
                                    {{
                                        form.staff_role_id
                                            ? 'Definidos por el perfil seleccionado.'
                                            : `Selecciona qué secciones verá este ${form.role === 'admin' ? 'administrador' : 'cajero'}.`
                                    }}
                                </span>
                            </div>
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
                                        :disabled="!!form.staff_role_id"
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
                                {{ saving ? 'Guardando…' : formMode === 'create' ? 'Crear usuario' : 'Guardar cambios' }}
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
