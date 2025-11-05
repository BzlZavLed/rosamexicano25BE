<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { getTopProducts, type TopProductsResponse } from '../../api/widgets';

const loading = ref(false);
const error = ref('');
const data = ref<TopProductsResponse | null>(null);

const BAR_COLORS = ['#2563eb', '#f97316', '#10b981', '#f43f5e', '#8b5cf6', '#14b8a6'];

const maxCantidad = computed(() => {
    if (!data.value) return 0;
    return data.value.productos.reduce((acc, prod) => Math.max(acc, prod.cantidad_vendida), 0);
});

const products = computed(() => data.value?.productos ?? []);
const hasProducts = computed(() => products.value.length > 0);

const CHART_HEIGHT = 220;
const TOP_MARGIN = 20;
const BOTTOM_MARGIN = 70;
const BAR_WIDTH = 24;
const GAP = 16;

const chartWidth = computed(() => {
    const count = products.value.length;
    return Math.max(count * (BAR_WIDTH + GAP) + GAP, 200);
});

function lightenHex(hex: string, amount = 0.5) {
    let col = hex.replace('#', '');
    if (col.length === 3) {
        col = col.split('').map((c) => c + c).join('');
    }
    const num = parseInt(col, 16);
    const r = (num >> 16) & 0xff;
    const g = (num >> 8) & 0xff;
    const b = num & 0xff;
    const lerp = (channel: number) => Math.round(channel + (255 - channel) * amount);
    return `#${[lerp(r), lerp(g), lerp(b)].map((c) => c.toString(16).padStart(2, '0')).join('')}`;
}

function truncateLabel(value: string, limit = 24) {
    if (!value) return '';
    return value.length > limit ? `${value.slice(0, limit - 3)}...` : value;
}
const chartBars = computed(() => {
    const max = maxCantidad.value || 1;
    return products.value.map((prod, index) => {
        const x = GAP + index * (BAR_WIDTH + GAP);
        const availableHeight = CHART_HEIGHT - TOP_MARGIN - BOTTOM_MARGIN;
        const height = Math.max((prod.cantidad_vendida / max) * availableHeight, 8);
        const y = TOP_MARGIN + (availableHeight - height);
        const color = BAR_COLORS[index % BAR_COLORS.length];
        return {
            id: prod.producto_id,
            label: prod.producto_nombre,
            proveedor: prod.proveedor_nombre,
            cantidad: prod.cantidad_vendida,
            x,
            y,
            height,
            color,
            colorLight: lightenHex(color ?? '#2563eb', 0.7),
        };
    });
});

const legendItems = computed(() =>
    chartBars.value.map((bar) => ({
        id: bar.id,
        label: bar.label,
        displayLabel: truncateLabel(bar.label),
        proveedor: bar.proveedor,
        color: bar.color,
        cantidad: bar.cantidad,
    }))
);

async function loadTopProducts() {
    loading.value = true;
    error.value = '';
    try {
        const resp = await getTopProducts();
        data.value = resp;
    } catch (err: any) {
        error.value =
            err?.response?.data?.message || err?.message || 'No se pudo cargar el ranking de productos.';
        data.value = null;
    } finally {
        loading.value = false;
    }
}

onMounted(loadTopProducts);
</script>

<template>
    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div class="space-y-1">
                <h3 class="text-base font-semibold text-gray-900">Top productos</h3>
                <p class="text-sm text-gray-500">
                    Últimos 10 días, ordenado por cantidad vendida.
                </p>
                <p v-if="data" class="text-xs text-gray-400">
                    Rango: {{ data.desde }} – {{ data.hasta }}
                </p>
            </div>
            <button
                type="button"
                @click="loadTopProducts"
                class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 w-full sm:w-auto"
            >
                Actualizar
            </button>
        </div>

        <div class="mt-5 space-y-3">
            <div
                v-if="loading"
                class="rounded-xl border border-gray-100 bg-gray-50 px-4 py-5 text-sm text-gray-600"
            >
                Cargando ranking…
            </div>
            <div
                v-else-if="error"
                class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-5 text-sm text-rose-700"
            >
                {{ error }}
            </div>
            <div v-else-if="hasProducts && data" class="space-y-4">
                <div class="overflow-hidden rounded-xl border border-gray-100 bg-gray-50 p-4">
                    <svg
                        class="w-full"
                        :height="CHART_HEIGHT"
                        :viewBox="`0 0 ${chartWidth} ${CHART_HEIGHT}`"
                        preserveAspectRatio="none"
                        role="img"
                        aria-label="Productos más vendidos"
                    >
                        <defs>
                            <template v-for="bar in chartBars" :key="`grad-${bar.id}`">
                                <linearGradient :id="`bar-grad-${bar.id}`" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" :stop-color="bar.colorLight" />
                                    <stop offset="100%" :stop-color="bar.color" />
                                </linearGradient>
                            </template>
                        </defs>
                        <g v-if="chartBars.length">
                            <line
                                x1="0"
                                :x2="chartWidth"
                                :y1="CHART_HEIGHT - BOTTOM_MARGIN"
                                :y2="CHART_HEIGHT - BOTTOM_MARGIN"
                                stroke="#e5e7eb"
                                stroke-width="1"
                            />
                            <template v-for="bar in chartBars" :key="bar.id">
                                <rect
                                    :x="bar.x"
                                    :y="bar.y"
                                    :width="BAR_WIDTH"
                                    :height="bar.height"
                                    rx="4"
                                    ry="4"
                                    :fill="`url(#bar-grad-${bar.id})`"
                                />
                                <text
                                    :x="bar.x + BAR_WIDTH / 2"
                                    :y="bar.y - 6"
                                    text-anchor="middle"
                                    fill="#111827"
                                    font-size="10"
                                    font-family="inherit"
                                >
                                    {{ bar.cantidad }}
                                </text>
                            </template>
                        </g>
                    </svg>
                </div>

                <ul class="space-y-1 text-xs text-gray-600">
                    <li
                        v-for="item in legendItems"
                        :key="item.id"
                        class="flex flex-wrap items-center justify-between gap-1 border-b border-gray-200 pb-1 last:border-b-0"
                    >
                        <div class="flex items-center gap-2 min-w-0 max-w-[60%]">
                            <span
                                class="inline-flex h-2 w-2 rounded-full"
                                :style="{ backgroundColor: item.color }"
                            ></span>
                            <p class="truncate font-medium text-gray-800 max-w-[140px]">{{ item.displayLabel }}</p>
                        </div>
                        <div class="flex flex-col text-[11px] text-gray-500 text-right">
                            <span class="truncate">{{ item.proveedor }}</span>
                            <span class="font-semibold text-gray-900">{{ item.cantidad }} uds</span>
                        </div>
                    </li>
                </ul>
            </div>
            <div
                v-else
                class="rounded-xl border border-gray-100 bg-gray-50 px-4 py-5 text-sm text-gray-500"
            >
                No hay ventas registradas en los últimos días.
            </div>
        </div>
    </div>
</template>
