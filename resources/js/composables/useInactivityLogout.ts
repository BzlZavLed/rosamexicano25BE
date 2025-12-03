// resources/js/composables/useInactivityLogout.ts
import { onMounted, onUnmounted, watch, type Ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const DEFAULT_TIMEOUT_MS = 10 * 60 * 1000; // 10 minutes
const ACTIVITY_EVENTS: Array<keyof WindowEventMap> = [
    'mousemove',
    'mousedown',
    'keydown',
    'touchstart',
    'scroll',
];

/**
 * Logs the user out after a period of inactivity.
 * Attach inside authenticated layouts/screens so it runs only when logged in.
 */
export function useInactivityLogout(timeoutMs = DEFAULT_TIMEOUT_MS, enabledRef?: Ref<boolean>) {
    const auth = useAuthStore();
    const router = useRouter();
    let timer: number | undefined;
    const localFlag = computed(() => {
        if (enabledRef) return enabledRef.value;
        try {
            const val = localStorage.getItem('inactivityLogoutEnabled');
            return val === null ? true : val !== 'false';
        } catch {
            return true;
        }
    });

    const clear = () => {
        if (timer) {
            window.clearTimeout(timer);
            timer = undefined;
        }
    };

    const triggerLogout = async () => {
        clear();
        await auth.logout();
        router.push({ name: 'login' });
    };

    const resetTimer = () => {
        if (!auth.isAuthenticated || !localFlag.value) {
            clear();
            return;
        }
        clear();
        timer = window.setTimeout(triggerLogout, timeoutMs);
    };

    const onActivity = () => resetTimer();

    const onStorage = (e: StorageEvent) => {
        if (e.key === 'inactivityLogoutEnabled') {
            resetTimer();
        }
    };

    onMounted(() => {
        if (!auth.isAuthenticated) return;
        ACTIVITY_EVENTS.forEach((ev) => window.addEventListener(ev, onActivity, { passive: true }));
        document.addEventListener('visibilitychange', onActivity);
        window.addEventListener('storage', onStorage);
        resetTimer();
    });

    onUnmounted(() => {
        ACTIVITY_EVENTS.forEach((ev) => window.removeEventListener(ev, onActivity));
        document.removeEventListener('visibilitychange', onActivity);
        window.removeEventListener('storage', onStorage);
        clear();
    });

    watch(
        () => [auth.isAuthenticated, localFlag.value],
        ([isAuth, enabled]) => {
            if (isAuth && enabled) resetTimer();
            else clear();
        }
    );
}
