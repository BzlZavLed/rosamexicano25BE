<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router';
const props = defineProps<{ to: any; label: string }>();
const emit = defineEmits<{ (e: 'navigate'): void }>();
const route = useRoute(); const router = useRouter();

function isActive() {
    if (props.to.name && route.name === props.to.name) return true;
    if (props.to.path && route.path.startsWith(props.to.path)) return true;
    return false;
}
function go() { router.push(props.to); emit('navigate'); }
</script>

<template>
    <a href="#" @click.prevent="go" :class="[
        'group flex items-center gap-3 rounded-xl px-3 py-2 text-sm',
        isActive()
            ? 'bg-[#E4007C] text-white'
            : 'text-gray-700 hover:bg-gray-100'
    ]">
        <slot />
        <span class="truncate">{{ label }}</span>
    </a>
</template>
