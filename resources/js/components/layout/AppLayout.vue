<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useAuthStore } from '../../stores/auth';
import { useInactivityLogout } from '../../composables/useInactivityLogout';
import SidebarAdmin from './SidebarAdmin.vue';
import SidebarProvider from './SidebarProvider.vue';
import SettingsModal from '../modals/SettingsModal.vue';
import AdminCancelSalesModal from '../modals/AdminCancelSalesModal.vue';

const auth = useAuthStore();
const isAdmin = computed(() => auth.isAdmin);
const isCashier = computed(() => auth.isCashier);
const isStaff = computed(() => auth.isAdmin || auth.isCashier);
const isProvider = computed(() => auth.isProvider);
const allowedModuleSet = computed(() => new Set(auth.allowedModules));
const canOpenConfig = computed(() => isStaff.value && allowedModuleSet.value.has('configuracion'));
const canOpenCancel = computed(() => isStaff.value && allowedModuleSet.value.has('cancelaciones'));
const drawerOpen = ref(false);
const isCompact = ref(false);
const sidebarCollapsed = ref(false);
const appName = import.meta.env.VITE_APP_NAME || 'Rosa Mexicano POS';
const settingsOpen = ref(false);
const cancelToolOpen = ref(false);

useInactivityLogout();


function toggle() { drawerOpen.value = !drawerOpen.value; }
function closeDrawer() { drawerOpen.value = false; }
async function logout() { await auth.logout(); window.location.href = '/login'; }
function toggleSidebarCollapse() { sidebarCollapsed.value = !sidebarCollapsed.value; }

function handleResize() {
    isCompact.value = window.innerWidth < 768;
    if (!isCompact.value) drawerOpen.value = false;
    if (isCompact.value) sidebarCollapsed.value = false;
}

onMounted(() => {
    handleResize();
    window.addEventListener('resize', handleResize);
});

onUnmounted(() => {
    window.removeEventListener('resize', handleResize);
});
</script>

<template>
    <div class="min-h-screen bg-gray-50 text-gray-900 flex flex-col">
        <!-- Header -->
        <header class="sticky top-0 z-40 w-full bg-white border-b border-gray-200">
            <div
                class="mx-auto h-14 px-4 flex items-center justify-between"
                :class="(!sidebarCollapsed || isCompact) ? 'max-w-7xl' : 'max-w-none w-full'">
                <div class="flex items-center gap-3 min-w-0">
                    <button
                        class="md:hidden inline-flex items-center justify-center rounded-lg border px-2.5 py-2 text-sm hover:bg-gray-100 transition"
                        @click="toggle" aria-label="Abrir menú" v-if="isCompact">
                        <span class="sr-only">Abrir menú</span>
                        ☰
                    </button>
                    <div class="flex items-center gap-2">
                        <button
                            v-if="!isCompact"
                            class="inline-flex items-center justify-center rounded-lg border px-2.5 py-2 text-sm hover:bg-gray-100 transition"
                            @click="toggleSidebarCollapse"
                            :aria-pressed="sidebarCollapsed"
                            :aria-label="sidebarCollapsed ? 'Expandir barra lateral' : 'Colapsar barra lateral'">
                            <span v-if="sidebarCollapsed">☰</span>
                            <span v-else>−</span>
                        </button>
                        <div class="font-semibold truncate">{{ appName }}</div>
                    </div>
                </div>
                <div class="flex items-center gap-3 text-sm text-gray-600">
                    <button
                        v-if="canOpenConfig"
                        class="hidden sm:inline-flex items-center justify-center rounded-lg border px-3 py-2 text-xs hover:bg-gray-50 transition"
                        @click="settingsOpen = true">
                        Configuración
                    </button>
                    <button
                        v-if="canOpenCancel"
                        class="hidden sm:inline-flex items-center justify-center rounded-lg border px-3 py-2 text-xs hover:bg-gray-50 transition"
                        @click="cancelToolOpen = true">
                        Cancelar ventas
                    </button>
                    <div class="leading-tight text-right max-w-[50vw] sm:max-w-xs truncate">
                        <span class="font-medium truncate">{{ auth.displayName }}</span>
                        <div class="text-xs text-gray-400" v-if="isAdmin">Administrador</div>
                        <div class="text-xs text-gray-400" v-else-if="isCashier">Cajero</div>
                        <div class="text-xs text-gray-400" v-else-if="isProvider">Proveedor</div>
                    </div>
                    <button
                        class="inline-flex items-center justify-center rounded-lg border px-3 py-2 text-sm hover:bg-gray-50 transition"
                        @click="logout">Salir</button>
                </div>
            </div>
        </header>

        <!-- Body -->
        <div class="flex-1 w-full">
            <div class="mx-auto px-2 sm:px-4 flex h-full" :class="sidebarCollapsed ? 'max-w-full' : 'max-w-7xl'">
                <!-- Desktop sidebar -->
                <aside v-show="!sidebarCollapsed" class="hidden md:block shrink-0 w-[250px] xl:w-[280px] pt-5 pr-4">
                    <div class="sticky top-20">
                        <component :is="isStaff ? SidebarAdmin : SidebarProvider" />
                    </div>
                </aside>

                <!-- Overlay for mobile drawer -->
                <transition name="fade">
                    <div v-if="drawerOpen" class="fixed inset-0 z-40 bg-black/35 backdrop-blur-sm md:hidden" @click="closeDrawer">
                    </div>
                </transition>

                <!-- Mobile drawer -->
                <transition name="slide">
                    <aside v-if="drawerOpen"
                        class="fixed left-0 top-0 z-50 h-full w-[82vw] max-w-xs bg-white border-r border-gray-200 p-4 md:hidden flex flex-col">
                        <div class="flex items-center justify-between mb-3">
                            <div class="font-semibold text-sm">Navegación</div>
                            <button
                                class="inline-flex items-center justify-center rounded-lg border px-3 py-2 text-sm hover:bg-gray-50 transition"
                                @click="closeDrawer">✕</button>
                        </div>
                        <div class="flex-1 overflow-y-auto pr-1">
                            <component :is="isStaff ? SidebarAdmin : SidebarProvider" @navigate="closeDrawer" />
                        </div>
                    </aside>
                </transition>

                <!-- Main content -->
                <main class="flex-1 py-6 md:py-8" :class="sidebarCollapsed ? 'md:pl-0' : 'md:pl-6'">
                    <div class="rounded-xl bg-white shadow-sm border border-gray-200 md:border-transparent md:shadow-none md:bg-transparent p-4 md:p-0 h-full">
                        <slot />
                    </div>
                </main>
            </div>
        </div>
        <SettingsModal :open="settingsOpen" @close="settingsOpen = false" />
        <AdminCancelSalesModal :open="cancelToolOpen" @close="cancelToolOpen = false" />
    </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity .18s ease;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}

.slide-enter-active,
.slide-leave-active {
    transition: transform .22s ease;
}

.slide-enter-from {
    transform: translateX(-100%);
}

.slide-leave-to {
    transform: translateX(-100%);
}
</style>
