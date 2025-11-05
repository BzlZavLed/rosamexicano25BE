<script setup lang="ts">
import { reactive, watch, computed } from 'vue';

const props = defineProps<{
    open: boolean;
    saving?: boolean;
    error?: string;
    message?: string;
    initialEmail?: string;
    initialSubject?: string;
    initialBody?: string;
    attachmentUrl?: string | null;
    attachmentLabel?: string | null;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'submit', payload: { email: string; subject: string; body: string }): void;
    (e: 'open-attachment'): void;
}>();

const form = reactive({
    email: props.initialEmail ?? '',
    subject: props.initialSubject ?? '',
    body: props.initialBody ?? '',
});

watch(
    () => props.open,
    (open) => {
        if (open) {
            form.email = props.initialEmail ?? '';
            form.subject = props.initialSubject ?? '';
            form.body = props.initialBody ?? '';
        }
    }
);

const hasAttachment = computed(() => Boolean(props.attachmentUrl));

function close() {
    if (props.saving) return;
    emit('close');
}

function submit() {
    emit('submit', {
        email: form.email.trim(),
        subject: form.subject.trim(),
        body: form.body.trim(),
    });
}
</script>

<template>
    <transition name="fade">
        <div
            v-if="open"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4 py-6"
        >
            <div class="w-full max-w-xl rounded-2xl bg-white shadow-xl">
                <header class="flex items-start justify-between border-b border-gray-200 px-6 py-4">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Reenviar correo</h3>
                        <p class="text-sm text-gray-500">
                            Envía nuevamente el comprobante al proveedor.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-500 hover:bg-gray-50"
                        @click="close"
                    >
                        Cerrar
                    </button>
                </header>

                <form class="px-6 py-5 space-y-4" @submit.prevent="submit">
                    <div class="space-y-2">
                        <label class="text-sm font-medium text-gray-700">Correo electrónico</label>
                        <input
                            v-model="form.email"
                            type="email"
                            required
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                            placeholder="proveedor@example.com"
                        />
                    </div>

                    <div class="space-y-2">
                        <label class="text-sm font-medium text-gray-700">Asunto</label>
                        <input
                            v-model="form.subject"
                            type="text"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                            placeholder="Documento solicitado"
                        />
                    </div>

                    <div class="space-y-2">
                        <label class="text-sm font-medium text-gray-700">Mensaje</label>
                        <textarea
                            v-model="form.body"
                            rows="4"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"
                            placeholder="Adjunto documento."
                        ></textarea>
                    </div>

                    <div
                        v-if="hasAttachment"
                        class="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3 text-xs text-gray-600"
                    >
                        <p class="font-medium text-gray-800">Adjunto</p>
                        <button
                            type="button"
                            class="mt-1 inline-flex items-center gap-1 text-xs text-gray-700 underline underline-offset-4 hover:text-gray-900"
                            @click="emit('open-attachment')"
                        >
                            {{ attachmentLabel || 'Abrir comprobante' }}
                        </button>
                    </div>

                    <div v-if="message" class="rounded-lg border border-green-100 bg-green-50 px-4 py-3 text-sm text-green-700">
                        {{ message }}
                    </div>
                    <div v-if="error" class="rounded-lg border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-600">
                        {{ error }}
                    </div>

                    <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-lg border border-gray-200 px-4 py-2 text-sm text-gray-500 hover:bg-gray-50"
                            @click="close"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-gray-800 disabled:cursor-not-allowed disabled:bg-gray-300"
                            :disabled="saving"
                        >
                            <span v-if="saving">Enviando…</span>
                            <span v-else>Enviar correo</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </transition>
</template>
