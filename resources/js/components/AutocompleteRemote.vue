<!-- src/components/AutocompleteRemote.vue -->
<script setup lang="ts">
import { ref, watch, onUnmounted, computed, nextTick } from 'vue';

type Item = Record<string, any>;

const props = defineProps<{
    /** Called as fetcher(query, page?). Must return { items: Item[] } OR raw array */
    fetcher: (q: string) => Promise<{ items: Item[] } | Item[]>;
    /** Display label field on each item (e.g., 'label') or a function (item)=>string */
    labelKey?: string | ((item: Item) => string);
    /** Value field on each item (e.g., 'ident') or a function (item)=>any */
    valueKey?: string | ((item: Item) => any);
    /** Placeholder text */
    placeholder?: string;
    /** Minimum characters before searching (default 2) */
    minChars?: number;
    /** Pre-filled text (optional) */
    modelText?: string;
    /** Pre-filled value (optional) */
    modelValue?: any;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', v: any): void;
    (e: 'update:modelText', v: string): void;
    (e: 'select', item: Item): void;
}>();

const open = ref(false);
const loading = ref(false);
const error = ref('');
const q = ref(props.modelText ?? '');
const items = ref<Item[]>([]);
const highlighted = ref(-1);
let t: number | undefined;

const minChars = computed(() => props.minChars ?? 2);

function labelOf(it: Item) {
    if (!it) return '';
    if (typeof props.labelKey === 'function') return props.labelKey(it);
    if (typeof props.labelKey === 'string') return it[props.labelKey] ?? '';
    // sensible default: try common keys
    return it.label ?? it.nombre ?? it.name ?? String(it.id ?? '');
}
function valueOf(it: Item) {
    if (!it) return null;
    if (typeof props.valueKey === 'function') return props.valueKey(it);
    if (typeof props.valueKey === 'string') return it[props.valueKey];
    return it.value ?? it.ident ?? it.id ?? null;
}

async function searchNow() {
    const query = q.value.trim();
    if (query.length < minChars.value) { items.value = []; open.value = false; return; }

    loading.value = true; error.value = '';
    try {
        const res = await props.fetcher(query);
        items.value = Array.isArray(res) ? res : (res?.items ?? []);
        open.value = items.value.length > 0;
        highlighted.value = items.value.length ? 0 : -1;
    } catch (e: any) {
        error.value = e?.message || 'Error buscando';
        items.value = [];
        open.value = false;
    } finally {
        loading.value = false;
    }
}

watch(q, () => {
    if (t) clearTimeout(t);
    t = window.setTimeout(searchNow, 300);
    emit('update:modelText', q.value);
});
watch(() => props.modelText, (v) => {
    q.value = v ?? '';
});
onUnmounted(() => t && clearTimeout(t));

function choose(it: Item) {
    const val = valueOf(it);
    const lab = labelOf(it);

    // update model values
    emit('update:modelValue', val);
    emit('update:modelText', lab);
    emit('select', it);

    // reflect label in the input field
    q.value = lab;

    open.value = false;
    highlighted.value = -1;
}

function onKey(e: KeyboardEvent) {
    if (!open.value) return;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        highlighted.value = Math.min(highlighted.value + 1, items.value.length - 1);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        highlighted.value = Math.max(highlighted.value - 1, 0);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (highlighted.value >= 0 && items.value[highlighted.value]) choose(items.value[highlighted.value]!);
    } else if (e.key === 'Escape') {
        open.value = false;
    }
}

function onFocus() {
    // reopen if we already have results
    nextTick(() => {
        if (items.value.length) open.value = true;
    });
}
</script>

<template>
    <div class="relative">
        <div class="flex gap-2">
            <input :placeholder="placeholder || 'Buscar…'" v-model="q" @keydown="onKey" @focus="onFocus"
                class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900 px-3 py-2" />
            <div class="shrink-0 flex items-center px-2 text-sm text-gray-500">
                <span v-if="loading">Buscando…</span>
                <span v-else-if="q.length < minChars">Min. {{ minChars }} letras</span>
            </div>
        </div>

        <div v-if="open"
            class="absolute z-20 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow max-h-64 overflow-auto">
            <template v-if="items.length">
                <div v-for="(it, idx) in items" :key="idx" @click="choose(it)"
                    class="px-3 py-2 cursor-pointer hover:bg-gray-50" :class="idx === highlighted ? 'bg-gray-50' : ''">
                    {{ labelOf(it) }}
                </div>
            </template>
            <div v-else class="px-3 py-2 text-sm text-gray-500">Sin resultados</div>
        </div>

        <p v-if="error" class="mt-1 text-xs text-rose-600">{{ error }}</p>
    </div>
</template>
