<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router';
const props = defineProps<{ to: any; label: string }>();
const emit = defineEmits<{ (e: 'navigate'): void }>();
const route = useRoute(); const router = useRouter();

function queryValue(value: unknown) {
    if (Array.isArray(value)) return value[0] == null ? '' : String(value[0]);
    return value == null ? '' : String(value);
}

function queryMatches(targetQuery: Record<string, unknown> | undefined) {
    const entries = Object.entries(targetQuery ?? {});
    if (!entries.length) return true;

    return entries.every(([key, value]) => queryValue(route.query[key]) === queryValue(value));
}

function isActive() {
    if (props.to.name && route.name === props.to.name) return queryMatches(props.to.query);
    if (props.to.path) {
        const targetPath = String(props.to.path);
        const pathMatches = route.path === targetPath || route.path.startsWith(`${targetPath}/`);
        if (!pathMatches) return false;
        if (!queryMatches(props.to.query)) return false;
        if (!props.to.query && targetPath === '/admin/reportes' && route.query.report) return false;
        return true;
    }
    return false;
}
function go() { router.push(props.to); emit('navigate'); }
</script>

<template>
    <a href="#" @click.prevent="go" :class="[
        'group flex items-center gap-3 rounded-xl px-3 py-2 text-sm sidebar-item',
        isActive() ? 'sidebar-item--active' : 'sidebar-item--inactive'
    ]">
        <slot />
        <span class="truncate">{{ label }}</span>
    </a>
</template>
