<!-- src/views/LoginView.vue -->
<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { resolveStaffHome } from '../utils/staffRoutes';

const router = useRouter();
const auth = useAuthStore();

const identifier = ref('');
const password = ref('');
const showPw = ref(false);
const DEFAULT_THEME = 'rosa-mexicano';

const THEME_LOGOS: Record<string, string> = {
    'verde-lima': '/images/themes/dpekesypekas.png',
    'rosa-mexicano': '/images/themes/logorm.png',
};

const THEME_TITLES: Record<string, string> = {
    'verde-lima': 'Dpekesypekas',
    'rosa-mexicano': 'Rosa Mexicano',
};

function readTheme(): string {
    return (document.documentElement.dataset.theme ||
        (import.meta.env.VITE_APP_THEME as string | undefined) ||
        DEFAULT_THEME).toLowerCase();
}

const theme = ref(readTheme());
const logoSrc = computed(() => THEME_LOGOS[theme.value]);
const heroTitle = computed(() => THEME_TITLES[theme.value] ?? 'Portal interno');

async function submit() {
    console.log('Submitting login form');
    const inputIdentifier = identifier.value.trim();
    console.log('[login] attempt', { identifier: inputIdentifier });
    const ok = await auth.login(inputIdentifier, password.value);
    console.log('[login] result', { identifier: inputIdentifier, success: ok });
    if (!ok) {
        console.warn('[login] failed', { identifier: inputIdentifier, error: auth.error });
        return;
    }
    if (auth.isAdmin || auth.isCashier) {
        const staffRole: 'admin' | 'cashier' | '' = auth.isAdmin ? 'admin' : auth.isCashier ? 'cashier' : '';
        const fallback = resolveStaffHome(staffRole, auth.modules) ?? { name: 'admin-dashboard' };
        router.push(fallback);
    } else if (auth.isProvider) {
        router.push({ name: 'provider-dashboard' });
    }
}

function onEnter(e: KeyboardEvent) {
    if (e.key === 'Enter') submit();
}

let themeObserver: MutationObserver | null = null;

onMounted(() => {
    // put cursor in first input for faster login
    const el = document.getElementById('identifier');
    if (el) (el as HTMLInputElement).focus();

    themeObserver = new MutationObserver(() => {
        theme.value = readTheme();
    });

    themeObserver.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-theme'],
    });
});

onUnmounted(() => {
    themeObserver?.disconnect();
});
</script>

<template>
    <div class="login-background">
        <div class="login-grid">
            <section class="login-hero">
                <span class="hero-accent hero-accent--one" aria-hidden="true"></span>
                <span class="hero-accent hero-accent--two" aria-hidden="true"></span>
                <div class="hero-content">
                    <div v-if="logoSrc" class="hero-logo" aria-hidden="true">
                        <img :src="logoSrc" alt="Logotipo del tema actual" />
                    </div>
                    <p class="hero-kicker">{{ heroTitle }}</p>
                </div>
            </section>

            <section class="login-card">
                <div class="login-card__header">
                    <div class="login-card__avatar" aria-hidden="true">
                        <span class="login-card__dot"></span>
                    </div>
                    <div>
                        <h1>Iniciar sesión</h1>
                        <p>Email (admin) o teléfono (proveedor)</p>
                    </div>
                </div>

                <div class="login-form" @keydown="onEnter">
                    <div class="field">
                        <label for="identifier" class="form-label">Email (Admin) o Teléfono (Proveedor)</label>
                        <input id="identifier" v-model="identifier" type="text" autocomplete="username"
                            class="form-input" placeholder="admin@demo.com o 5551234567" />
                    </div>

                    <div class="field">
                        <label for="password" class="form-label">Contraseña</label>
                        <div class="password-line">
                            <input id="password" :type="showPw ? 'text' : 'password'" v-model="password"
                                autocomplete="current-password" class="form-input" placeholder="••••••••" />
                            <button class="toggle-btn" @click="showPw = !showPw" type="button" :aria-pressed="showPw">
                                {{ showPw ? 'Ocultar' : 'Ver' }}
                            </button>
                        </div>
                    </div>

                    <button :disabled="auth.loading" @click="submit" class="login-submit">
                        <span v-if="auth.loading" class="animate-pulse">Accediendo…</span>
                        <span v-else>Entrar</span>
                    </button>

                    <p v-if="auth.error" class="login-error">
                        {{ auth.error }}
                    </p>

                    <p class="helper-text">
                        ¿Proveedor? Tu contraseña es tu <b>IDENT</b> (número de proveedor).
                    </p>
                </div>

                <p class="login-footer">
                    © {{ new Date().getFullYear() }} Rosa Mexicano
                </p>
            </section>
        </div>
    </div>
</template>

<style scoped>
.login-background {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2.5rem 1rem;
    background: linear-gradient(135deg, var(--brand-surface-subtle, #ffe5f2), #ffffff);
}

.login-grid {
    width: 100%;
    max-width: 1200px;
    display: grid;
    gap: 2rem;
}

@media (min-width: 1024px) {
    .login-grid {
        grid-template-columns: minmax(0, 1fr) 420px;
        align-items: center;
    }
}

.login-hero {
    position: relative;
    overflow: hidden;
    border-radius: 2rem;
    padding: 2.5rem;
    background: var(--brand-surface, #ffffff);
    border: 1px solid var(--brand-secondary, #fdd8e9);
    box-shadow: 0 25px 65px rgba(30, 41, 59, 0.08);
}

.hero-content {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
    color: var(--brand-muted, #4c1d57);
    text-align: center;
    align-items: center;
}

.hero-logo img {
    width: 100%;
    height: auto;
    max-width: 280px;
    margin: 0 auto;
    display: block;
    object-fit: contain;
    filter: drop-shadow(0 10px 25px rgba(0, 0, 0, 0.2));
}

.hero-kicker {
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.3em;
    color: var(--brand-primary, #e4007c);
    font-weight: 600;
}

.hero-content h2 {
    font-size: clamp(1.75rem, 3vw, 2.5rem);
    font-weight: 700;
    color: #1f2937;
}

.hero-content p {
    font-size: 1rem;
    line-height: 1.6;
    color: #374151;
}

.hero-content code {
    background: var(--brand-surface-subtle, #fff5f9);
    padding: 0.15rem 0.35rem;
    border-radius: 0.4rem;
    font-size: 0.85rem;
}

.hero-points {
    display: grid;
    gap: 0.5rem;
    font-size: 0.95rem;
    color: #1f2937;
}

.hero-points li {
    padding-left: 1.3rem;
    position: relative;
}

.hero-points li::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0.45rem;
    width: 0.6rem;
    height: 0.6rem;
    border-radius: 999px;
    background: var(--brand-primary, #e4007c);
    opacity: 0.8;
}

.hero-accent {
    position: absolute;
    border-radius: 999px;
    filter: blur(60px);
    opacity: 0.6;
}

.hero-accent--one {
    width: 220px;
    height: 220px;
    top: -60px;
    right: -60px;
    background: var(--brand-secondary, #ffd0e6);
}

.hero-accent--two {
    width: 280px;
    height: 280px;
    bottom: -80px;
    left: -70px;
    background: var(--brand-primary, #e4007c);
    opacity: 0.25;
}

.login-card {
    background: var(--brand-surface, #ffffff);
    border: 1px solid var(--brand-secondary, #fdd8e9);
    border-radius: 1.75rem;
    padding: 2rem;
    box-shadow: 0 18px 45px rgba(15, 23, 42, 0.15);
    backdrop-filter: blur(6px);
}

.login-card__header {
    display: flex;
    gap: 1rem;
    align-items: center;
    margin-bottom: 2rem;
}

.login-card__avatar {
    height: 3.5rem;
    width: 3.5rem;
    border-radius: 1.25rem;
    background: var(--brand-surface-subtle, #fff5f9);
    border: 1px solid var(--brand-secondary, #fdd8e9);
    display: grid;
    place-items: center;
    position: relative;
}

.login-card__dot {
    width: 14px;
    height: 14px;
    border-radius: 999px;
    background: var(--brand-primary, #e4007c);
    filter: drop-shadow(0 6px 12px rgba(228, 0, 124, 0.35));
}

.login-card__header h1 {
    font-size: 1.75rem;
    font-weight: 600;
    color: #111827;
    margin: 0;
}

.login-card__header p {
    margin: 0.15rem 0 0;
    color: #4b5563;
    font-size: 0.95rem;
}

.login-form {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.field {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}

.form-label {
    font-size: 0.9rem;
    font-weight: 500;
    color: var(--brand-muted, #4b2849);
}

.form-input {
    width: 100%;
    border-radius: 1.2rem;
    border: 1px solid var(--brand-secondary, #fdd8e9);
    padding: 0.85rem 1rem;
    background: #ffffff;
    color: #111827;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.form-input:focus {
    outline: none;
    border-color: var(--brand-primary, #e4007c);
    box-shadow: 0 0 0 2px var(--brand-secondary, #fdd8e9);
}

.password-line {
    display: flex;
    gap: 0.75rem;
}

.toggle-btn {
    border-radius: 1.2rem;
    border: 1px solid var(--brand-secondary, #fdd8e9);
    padding: 0 1rem;
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--brand-muted, #4b2849);
    background: var(--brand-surface-subtle, #fff5f9);
    transition: background 0.2s ease, border-color 0.2s ease;
}

.toggle-btn:hover {
    border-color: var(--brand-primary, #e4007c);
}

.login-submit {
    width: 100%;
    border: none;
    border-radius: 1.2rem;
    padding: 0.9rem;
    font-weight: 600;
    font-size: 1rem;
    color: #ffffff;
    background: var(--brand-primary, #e4007c);
    box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15);
    transition: transform 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease;
}

.login-submit:disabled {
    opacity: 0.65;
    cursor: not-allowed;
}

.login-submit:not(:disabled):hover {
    transform: translateY(-1px);
    filter: brightness(1.02);
    box-shadow: 0 20px 35px rgba(0, 0, 0, 0.2);
}

.login-error {
    font-size: 0.9rem;
    text-align: center;
    border-radius: 1rem;
    padding: 0.75rem 1rem;
    background: var(--brand-surface-subtle, #fff5f9);
    color: var(--brand-primary, #e4007c);
    border: 1px solid var(--brand-primary, #e4007c);
}

.helper-text {
    font-size: 0.85rem;
    text-align: center;
    color: #4b5563;
}

.login-footer {
    margin-top: 2rem;
    font-size: 0.8rem;
    text-align: center;
    color: #6b7280;
}

@media (max-width: 640px) {
    .password-line {
        flex-direction: column;
    }
}
</style>
