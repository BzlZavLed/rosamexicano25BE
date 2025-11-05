<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useAuthStore } from '../../stores/auth';
import SidebarAdmin from './SidebarAdmin.vue';
import SidebarProvider from './SidebarProvider.vue';

const auth = useAuthStore();
const isAdmin = computed(() => auth.isAdmin);
const isProvider = computed(() => auth.isProvider);
const drawerOpen = ref(false);
const isCompact = ref(false);

function toggle() { drawerOpen.value = !drawerOpen.value; }
function closeDrawer() { drawerOpen.value = false; }
async function logout() { await auth.logout(); window.location.href = '/login'; }

function handleResize() {
    isCompact.value = window.innerWidth < 768;
    if (!isCompact.value) drawerOpen.value = false;
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
            <div class="mx-auto max-w-7xl h-14 px-4 flex items-center justify-between">
                <div class="flex items-center gap-3 min-w-0">
                    <button
                        class="md:hidden inline-flex items-center justify-center rounded-lg border px-2.5 py-2 text-sm hover:bg-gray-100 transition"
                        @click="toggle" aria-label="Abrir menú" v-if="isCompact">
                        <span class="sr-only">Abrir menú</span>
                        ☰
                    </button>
                    <div class="font-semibold truncate">Rosa Mexicano POS</div>
                </div>
                <div class="flex items-center gap-3 text-sm text-gray-600">
                    <div class="leading-tight text-right max-w-[50vw] sm:max-w-xs truncate">
                        <span class="font-medium truncate">{{ auth.displayName }}</span>
                        <div class="text-xs text-gray-400" v-if="isAdmin">Administrador</div>
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
            <div class="mx-auto max-w-7xl px-2 sm:px-4 flex h-full">
                <!-- Desktop sidebar -->
                <aside class="hidden md:block shrink-0 w-[250px] xl:w-[280px] pt-5 pr-4">
                    <div class="sticky top-20">
                        <component :is="isAdmin ? SidebarAdmin : SidebarProvider" />
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
                            <component :is="isAdmin ? SidebarAdmin : SidebarProvider" @navigate="closeDrawer" />
                        </div>
                    </aside>
                </transition>

                <!-- Main content -->
                <main class="flex-1 py-6 md:py-8 md:pl-6">
                    <div class="rounded-xl bg-white shadow-sm border border-gray-200 md:border-transparent md:shadow-none md:bg-transparent p-4 md:p-0 h-full">
                        <slot />
                    </div>
                </main>
            </div>
        </div>
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
