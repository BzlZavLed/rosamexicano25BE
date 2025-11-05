<!-- src/views/LoginView.vue -->
<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const router = useRouter();
const auth = useAuthStore();

const identifier = ref('');
const password = ref('');
const showPw = ref(false);

async function submit() {
    const ok = await auth.login(identifier.value.trim(), password.value);
    if (!ok) return;
    if (auth.isAdmin) router.push({ name: 'admin-dashboard' });
    else if (auth.isProvider) router.push({ name: 'provider-dashboard' });
}

function onEnter(e: KeyboardEvent) {
    if (e.key === 'Enter') submit();
}

onMounted(() => {
    // put cursor in first input for faster login
    const el = document.getElementById('identifier');
    if (el) (el as HTMLInputElement).focus();
});
</script>

<template>


    <!-- Brand gradient backdrop using #E4007C -->
    <div class="min-h-screen flex items-center justify-center bg-gradient-to-b from-white to-[#ffe5f2] px-4 py-8">
        <div class="w-full max-w-md">
            <div class="bg-white/90 backdrop-blur border border-[#e9e9ee] rounded-2xl shadow-xl p-6 md:p-8">
                <div class="text-center mb-6">
                    <div
                        class="mx-auto mb-3 h-12 w-12 rounded-full grid place-items-center bg-[#E4007C]/10 text-[#E4007C] font-bold">
                        RM
                    </div>
                    <h1 class="text-2xl font-semibold text-gray-900">Iniciar sesión</h1>
                    <p class="text-sm text-gray-500 mt-1">Usa tu correo (admin) o teléfono (proveedor)</p>
                </div>

                <div class="space-y-4" @keydown="onEnter">
                    <!-- Identifier -->
                    <div class="space-y-1.5">
                        <label for="identifier" class="block text-sm font-medium text-gray-700">
                            Email (Admin) o Teléfono (Proveedor)
                        </label>
                        <input id="identifier" v-model="identifier" type="text" autocomplete="username" class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-gray-900 placeholder:text-gray-400
                     focus:outline-none focus:ring-2 focus:ring-[#E4007C] focus:border-transparent"
                            placeholder="admin@demo.com o 5551234567" />
                    </div>

                    <!-- Password -->
                    <div class="space-y-1.5">
                        <label for="password" class="block text-sm font-medium text-gray-700">Contraseña</label>
                        <div class="flex gap-2">
                            <input id="password" :type="showPw ? 'text' : 'password'" v-model="password"
                                autocomplete="current-password" class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-gray-900 placeholder:text-gray-400
                       focus:outline-none focus:ring-2 focus:ring-[#E4007C] focus:border-transparent"
                                placeholder="••••••••" />
                            <button
                                class="shrink-0 rounded-xl border px-3 py-2 text-sm text-[#E4007C] border-[#E4007C]/30 hover:bg-[#E4007C]/5"
                                @click="showPw = !showPw" type="button" :aria-pressed="showPw">
                                {{ showPw ? 'Ocultar' : 'Ver' }}
                            </button>
                        </div>
                    </div>

                    <!-- Submit -->
                    <button :disabled="auth.loading" @click="submit" class="w-full inline-flex items-center justify-center rounded-xl px-4 py-2.5 text-white font-medium
                   bg-[#E4007C] hover:bg-[#cc006f] disabled:opacity-60 disabled:cursor-not-allowed
                   focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#E4007C]">
                        <span v-if="auth.loading" class="animate-pulse">Accediendo…</span>
                        <span v-else>Entrar</span>
                    </button>

                    <!-- Error -->
                    <p v-if="auth.error"
                        class="text-[#b0003f] bg-[#E4007C]/10 border border-[#E4007C]/30 rounded-lg px-3 py-2 text-sm text-center">
                        {{ auth.error }}
                    </p>

                    <!-- Helper tip for providers -->
                    <p class="text-xs text-gray-500 text-center">
                        ¿Proveedor nuevo? Tu primera contraseña es tu <b>ID de proveedor</b>.
                    </p>
                </div>
            </div>

            <!-- footer -->
            <p class="text-xs text-gray-500 text-center mt-4">
                © {{ new Date().getFullYear() }} Rosa Mexicano
            </p>
        </div>
    </div>
</template>
