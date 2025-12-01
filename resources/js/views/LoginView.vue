<!-- src/views/LoginView.vue -->
<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { resolveStaffHome } from '../utils/staffRoutes';
import { passkeyLogin, passkeyLoginOptions, passkeyRegister, passkeyRegisterOptions } from '../api/auth';

const router = useRouter();
const auth = useAuthStore();

const identifier = ref(auth.lastIdentifier || '');
const password = ref('');
const showPw = ref(false);
const biometricError = ref('');
const biometricLoading = ref(false);
const passkeyError = ref('');
const passkeyLoading = ref(false);
const DEFAULT_THEME = 'rosa-mexicano';
const LOCAL_BIOMETRIC_KEY = 'pos_biometric_secret';

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

const supportsBiometric = computed(() =>
    typeof navigator !== 'undefined' &&
    !!navigator.credentials &&
    typeof navigator.credentials.get === 'function'
);
const supportsPasskey = computed(() =>
    typeof PublicKeyCredential !== 'undefined' && typeof navigator.credentials?.create === 'function'
);

async function submit() {
    biometricError.value = '';
    passkeyError.value = '';
    const inputIdentifier = identifier.value.trim();
    const ok = await auth.login(inputIdentifier, password.value);
    if (!ok) {
        console.warn('[login] failed', { identifier: inputIdentifier, error: auth.error });
        return;
    }
    await enrollBiometricIfPossible(inputIdentifier);
    await enrollPasskeyIfPossible();
    if (auth.isAdmin || auth.isCashier) {
        const staffRole: 'admin' | 'cashier' | '' = auth.isAdmin ? 'admin' : auth.isCashier ? 'cashier' : '';
        const fallback = resolveStaffHome(staffRole, auth.modules) ?? { name: 'admin-dashboard' };
        router.push(fallback);
    } else if (auth.isProvider) {
        router.push({ name: 'provider-dashboard' });
    }
}

async function enrollBiometricIfPossible(inputIdentifier: string) {
    if (!supportsBiometric.value) return;
    try {
        const res = await auth.issueBiometricCredential(inputIdentifier);
        if (!res) return;
        const secret = `${res.credential_id}:${res.token}`;
        persistLocalBiometricSecret(res.identifier, secret);
        if (navigator.credentials?.store) {
            const cred = new (window as any).PasswordCredential({
                id: res.identifier,
                name: res.identifier,
                password: secret,
            });
            await navigator.credentials.store(cred);
        }
    } catch (err) {
        console.warn('[biometric enrollment] failed', err);
    }
}

function persistLocalBiometricSecret(id: string, secret: string) {
    try {
        localStorage.setItem(LOCAL_BIOMETRIC_KEY, JSON.stringify({ id, secret }));
    } catch (e) {
        console.warn('[biometric enrollment] could not persist locally', e);
    }
}

function readLocalBiometricSecret() {
    try {
        const raw = localStorage.getItem(LOCAL_BIOMETRIC_KEY);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        if (parsed?.id && parsed?.secret) return parsed as { id: string; secret: string };
    } catch (e) {
        console.warn('[biometric login] failed to read local secret', e);
    }
    return null;
}

function bufferDecode(input: string): ArrayBuffer {
    const pad = '='.repeat((4 - (input.length % 4)) % 4);
    const base64 = (input + pad).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const output = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; ++i) {
        output[i] = raw.charCodeAt(i);
    }
    return output.buffer;
}

function bufferEncode(buffer: ArrayBuffer): string {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.byteLength; i++) {
        binary += String.fromCharCode(bytes[i] ?? 0);
    }
    const base64 = btoa(binary);
    return base64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function decodePublicKeyCredentialCreateOptions(options: any) {
    const publicKey = { ...options };
    publicKey.challenge = bufferDecode(options.challenge);
    publicKey.user = { ...options.user, id: bufferDecode(options.user.id) };
    publicKey.excludeCredentials = (options.excludeCredentials || []).map((cred: any) => ({
        ...cred,
        id: bufferDecode(cred.id),
    }));
    return publicKey;
}

function decodePublicKeyCredentialRequestOptions(options: any) {
    const publicKey = { ...options };
    publicKey.challenge = bufferDecode(options.challenge);
    publicKey.allowCredentials = (options.allowCredentials || []).map((cred: any) => ({
        ...cred,
        id: bufferDecode(cred.id),
    }));
    return publicKey;
}

function buildAttestation(credential: PublicKeyCredential) {
    const response = credential.response as AuthenticatorAttestationResponse;
    return {
        id: credential.id,
        rawId: bufferEncode(credential.rawId),
        type: credential.type,
        response: {
            clientDataJSON: bufferEncode(response.clientDataJSON),
            attestationObject: bufferEncode(response.attestationObject),
        },
    };
}

function buildAssertion(credential: PublicKeyCredential) {
    const response = credential.response as AuthenticatorAssertionResponse;
    return {
        id: credential.id,
        rawId: bufferEncode(credential.rawId),
        type: credential.type,
        response: {
            clientDataJSON: bufferEncode(response.clientDataJSON),
            authenticatorData: bufferEncode(response.authenticatorData),
            signature: bufferEncode(response.signature),
            userHandle: response.userHandle ? bufferEncode(response.userHandle) : null,
        },
    };
}

async function passkeyLoginFlow() {
    passkeyError.value = '';
    if (!supportsPasskey.value) {
        passkeyError.value = 'Este dispositivo no soporta passkeys.';
        return;
    }

    passkeyLoading.value = true;
    try {
        const inputIdentifier = identifier.value.trim();
        if (!inputIdentifier) {
            passkeyError.value = 'Ingresa tu email o teléfono para buscar tu passkey.';
            return;
        }
        const options = await passkeyLoginOptions(inputIdentifier);
        const publicKey = decodePublicKeyCredentialRequestOptions(options.publicKey ?? options);
        const credential = (await navigator.credentials.get({ publicKey })) as PublicKeyCredential;
        const assertion = buildAssertion(credential);
        const res = await passkeyLogin(assertion);
        console.log('response', res);
        if ('provider' in res) {
            auth.setSession(res.token, 'provider', { provider: res.provider });
        } else {
            auth.setSession(res.token, res.role, { user: res.user });
        }
        const staffRole: 'admin' | 'cashier' | '' = auth.isAdmin ? 'admin' : auth.isCashier ? 'cashier' : '';
        const fallback = auth.isProvider ? { name: 'provider-dashboard' } : resolveStaffHome(staffRole, auth.modules) ?? { name: 'admin-dashboard' };
        router.push(fallback);
    } catch (err: any) {
        if (err?.name === 'NotAllowedError') {
            passkeyError.value = 'Passkey cancelada o no autorizada.';
        } else {
            passkeyError.value = 'No se pudo iniciar sesión con passkey.';
            console.error('[passkey-login] error', err);
        }
    } finally {
        passkeyLoading.value = false;
    }
}

async function enrollPasskeyIfPossible() {
    if (!supportsPasskey.value) return;
    try {
        const options = await passkeyRegisterOptions();
        const publicKey = decodePublicKeyCredentialCreateOptions(options.publicKey ?? options);
        const credential = (await navigator.credentials.create({ publicKey })) as PublicKeyCredential;
        const attestation = buildAttestation(credential);
        await passkeyRegister(attestation);
    } catch (err) {
        console.warn('[passkey enrollment] skipped', err);
    }
}

async function biometricLogin() {
    biometricError.value = '';
    if (!supportsBiometric.value || !navigator.credentials?.get) {
        biometricError.value = 'Este dispositivo no soporta biometría para autocompletar.';
        return;
    }

    biometricLoading.value = true;
    try {
        const credential = (await navigator.credentials.get({
            password: true,
            mediation: 'optional',
        } as any)) as any;
        let id = (credential?.id as string | undefined) ?? (credential?.name as string | undefined);
        let secret = credential?.password as string | undefined;

        if (!id || !secret) {
            const fallback = readLocalBiometricSecret();
            if (!fallback) {
                biometricError.value = 'No encontramos credenciales guardadas para esta app.';
                return;
            }
            id = fallback.id;
            secret = fallback.secret;
        }

        identifier.value = id;
        const ok = await auth.biometricLogin(id, secret);
        if (!ok) {
            biometricError.value = auth.error || 'No se pudo iniciar sesión con biometría.';
            return;
        }
        const staffRole: 'admin' | 'cashier' | '' = auth.isAdmin ? 'admin' : auth.isCashier ? 'cashier' : '';
        const fallback = auth.isProvider ? { name: 'provider-dashboard' } : resolveStaffHome(staffRole, auth.modules) ?? { name: 'admin-dashboard' };
        router.push(fallback);
    } catch (err: any) {
        if (err?.name === 'NotAllowedError') {
            biometricError.value = 'Biometría cancelada o no autorizada.';
        } else {
            biometricError.value = 'No se pudo usar la biometría en este dispositivo.';
            console.error('[biometric-login] error', err);
        }
    } finally {
        biometricLoading.value = false;
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

                    <div class="alt-login-row" v-if="supportsBiometric || supportsPasskey">
                        <button
                            v-if="supportsBiometric"
                            :disabled="auth.loading || biometricLoading"
                            @click="biometricLogin"
                            class="pill-icon-btn"
                            :title="biometricLoading ? 'Verificando biometría…' : 'Entrar con la credencial guardada en el navegador'">
                            <span class="pill-icon">🔒</span>
                            <span v-if="biometricLoading" class="animate-pulse">Verificando…</span>
                        </button>
                        <button
                            v-if="supportsPasskey"
                        :disabled="auth.loading || passkeyLoading"
                        @click="passkeyLoginFlow"
                        class="pill-icon-btn"
                        :title="passkeyLoading ? 'Verificando passkey…' : 'Entrar con Passkey (Face ID / Touch ID)'">
                            <span class="faceid-icon" aria-hidden="true"></span>
                            <span v-if="passkeyLoading" class="animate-pulse">Passkey…</span>
                        </button>
                    </div>

                    <p v-if="auth.error" class="login-error">
                        {{ auth.error }}
                    </p>
                    <p v-else-if="biometricError" class="login-error login-error--soft">
                        {{ biometricError }}
                    </p>
                    <p v-else-if="passkeyError" class="login-error login-error--soft">
                        {{ passkeyError }}
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

.login-submit--ghost {
    margin-top: 0.6rem;
    background: #ffffff;
    color: var(--brand-primary, #e4007c);
    border: 1px solid var(--brand-secondary, #fdd8e9);
    box-shadow: none;
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

.login-error--soft {
    background: #fff;
    color: #92400e;
    border-color: var(--brand-secondary, #fdd8e9);
}

.alt-login-row {
    margin-top: 0.75rem;
    display: flex;
    gap: 0.5rem;
    justify-content: center;
}

.pill-icon-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.65rem 0.9rem;
    border-radius: 999px;
    border: 1px solid var(--brand-secondary, #fdd8e9);
    background: #ffffff;
    color: var(--brand-primary, #e4007c);
    font-weight: 600;
    font-size: 0.9rem;
    cursor: pointer;
    transition: transform 0.12s ease, box-shadow 0.12s ease, border-color 0.12s ease;
}

.pill-icon-btn:disabled {
    opacity: 0.65;
    cursor: not-allowed;
}

.pill-icon-btn:hover:not(:disabled) {
    border-color: var(--brand-primary, #e4007c);
    box-shadow: 0 4px 10px rgba(0,0,0,0.08);
}

.pill-icon {
    font-size: 1.1rem;
}

.faceid-icon {
    width: 1.15rem;
    height: 1.15rem;
    display: inline-block;
    background-size: 100% 100%;
    background-repeat: no-repeat;
    background-image: url("data:image/svg+xml,%3Csvg width='64' height='64' viewBox='0 0 64 64' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Crect x='9' y='6' width='46' height='52' rx='10' stroke='%23e4007c' stroke-width='4'/%3E%3Crect x='18' y='20' width='6' height='8' rx='3' fill='%23e4007c'/%3E%3Crect x='40' y='20' width='6' height='8' rx='3' fill='%23e4007c'/%3E%3Cpath d='M22 42c2.2 3 6 5 10 5s7.8-2 10-5' stroke='%23e4007c' stroke-width='4' stroke-linecap='round'/%3E%3C/svg%3E");
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
