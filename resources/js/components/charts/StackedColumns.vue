<script setup lang="ts" generic="T extends { label: string }">
import { useResizeObserver } from '@vueuse/core';
import { computed, ref } from 'vue';

export type Series = { key: string; label: string; color: string };

const props = withDefaults(
    defineProps<{
        rows: T[];
        series: Series[];
        height?: number;
        ariaLabel: string;
        tickLabel?: (row: T) => string;
    }>(),
    { height: 220, tickLabel: (row: { label: string }) => row.label },
);

const el = ref<HTMLElement | null>(null);
const width = ref(600);
useResizeObserver(
    el,
    (entries) => (width.value = entries[0].contentRect.width),
);

const hover = ref<number | null>(null);
const margin = { top: 12, right: 8, bottom: 26, left: 36 };
const GAP = 2; // surface gap between stacked segments

const plotW = computed(() =>
    Math.max(0, width.value - margin.left - margin.right),
);
const plotH = computed(() => props.height - margin.top - margin.bottom);
const value = (row: T, key: string) =>
    Number((row as Record<string, unknown>)[key] ?? 0);
const total = (row: T) =>
    props.series.reduce((sum, s) => sum + value(row, s.key), 0);

/** Round up to a "nice" axis max and return 3-4 ticks. */
const ticks = computed(() => {
    const max = Math.max(1, ...props.rows.map(total));
    const rough = max / 4;
    const mag = 10 ** Math.floor(Math.log10(rough));
    const step =
        [1, 2, 5, 10].map((m) => m * mag).find((s) => s >= rough) ?? mag * 10;
    const top = Math.ceil(max / step) * step;

    return Array.from(
        { length: Math.round(top / step) + 1 },
        (_, i) => i * step,
    );
});
const yMax = computed(() => ticks.value[ticks.value.length - 1]);
const y = (v: number) =>
    margin.top + plotH.value - (v / yMax.value) * plotH.value;

const slot = computed(() => plotW.value / Math.max(1, props.rows.length));
const barW = computed(() => Math.max(3, Math.min(28, slot.value * 0.62)));
const cx = (i: number) => margin.left + slot.value * (i + 0.5);

type Segment = { key: string; color: string; path: string };

/** Segments bottom-up; the topmost visible one gets the 4px rounded data end. */
const columns = computed(() =>
    props.rows.map((row, i) => {
        const x0 = cx(i) - barW.value / 2;
        const w = barW.value;
        const segments: Segment[] = [];
        let acc = 0;
        const visible = props.series.filter((s) => value(row, s.key) > 0);

        visible.forEach((s, idx) => {
            const v = value(row, s.key);
            const bottom = y(acc) - (idx > 0 ? GAP / 2 : 0);
            acc += v;
            const isTop = idx === visible.length - 1;
            const top = y(acc) + (isTop ? 0 : GAP / 2);
            const h = bottom - top;

            if (h < 0.5) {
                return;
            }

            const r = isTop ? Math.min(4, w / 2, h) : 0;
            const path = r
                ? `M${x0},${bottom}V${top + r}Q${x0},${top} ${x0 + r},${top}H${x0 + w - r}Q${x0 + w},${top} ${x0 + w},${top + r}V${bottom}Z`
                : `M${x0},${bottom}V${top}H${x0 + w}V${bottom}Z`;
            segments.push({ key: s.key, color: s.color, path });
        });

        return segments;
    }),
);

const xTicks = computed(() => {
    const n = props.rows.length;
    const every = Math.max(
        1,
        Math.ceil(n / Math.max(2, Math.floor(plotW.value / 70))),
    );

    return props.rows
        .map((_, i) => i)
        .filter((i) => i % every === 0 || i === n - 1);
});

const showTable = ref(false);

const onKey = (event: KeyboardEvent) => {
    const last = props.rows.length - 1;

    if (event.key === 'ArrowRight') {
        hover.value = Math.min(last, (hover.value ?? -1) + 1);
    } else if (event.key === 'ArrowLeft') {
        hover.value = Math.max(0, (hover.value ?? last + 1) - 1);
    } else {
        return;
    }

    event.preventDefault();
};
</script>

<template>
    <div>
        <div
            class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground"
        >
            <span
                v-for="s in series"
                :key="s.key"
                class="flex items-center gap-1.5"
            >
                <span
                    class="size-2.5 rounded-[2px]"
                    :style="{ background: s.color }"
                    aria-hidden="true"
                />
                {{ s.label }}
            </span>
            <button
                type="button"
                class="ml-auto underline underline-offset-2"
                :aria-pressed="showTable"
                @click="showTable = !showTable"
            >
                {{ showTable ? 'Show chart' : 'Show table' }}
            </button>
        </div>

        <div
            v-show="!showTable"
            ref="el"
            class="relative"
            :style="{ height: `${height}px` }"
        >
            <svg
                :width="width"
                :height="height"
                class="block focus-visible:outline-2 focus-visible:outline-ring"
                role="img"
                :aria-label="ariaLabel"
                tabindex="0"
                @keydown="onKey"
                @blur="hover = null"
            >
                <g>
                    <line
                        v-for="t in ticks"
                        :key="t"
                        :x1="margin.left"
                        :x2="width - margin.right"
                        :y1="y(t)"
                        :y2="y(t)"
                        :stroke="
                            t === 0 ? 'var(--viz-axis)' : 'var(--viz-grid)'
                        "
                        shape-rendering="crispEdges"
                    />
                    <text
                        v-for="t in ticks"
                        :key="`t${t}`"
                        :x="margin.left - 6"
                        :y="y(t)"
                        dy="0.32em"
                        text-anchor="end"
                        class="text-[11px] tabular-nums"
                        fill="var(--viz-muted)"
                    >
                        {{ t }}
                    </text>
                    <text
                        v-for="i in xTicks"
                        :key="`x${i}`"
                        :x="cx(i)"
                        :y="height - 6"
                        text-anchor="middle"
                        class="text-[11px]"
                        fill="var(--viz-muted)"
                    >
                        {{ tickLabel(rows[i]) }}
                    </text>
                </g>

                <g v-for="(segments, i) in columns" :key="i">
                    <path
                        v-for="seg in segments"
                        :key="seg.key"
                        :d="seg.path"
                        :fill="seg.color"
                        :opacity="hover === null || hover === i ? 1 : 0.5"
                    />
                    <!-- Hit target: the whole slot, not just the painted column -->
                    <rect
                        :x="cx(i) - slot / 2"
                        :y="margin.top"
                        :width="slot"
                        :height="plotH"
                        fill="transparent"
                        @pointerenter="hover = i"
                        @pointerleave="hover = null"
                    />
                </g>
            </svg>

            <div
                v-if="hover !== null"
                class="pointer-events-none absolute top-0 z-10 min-w-36 rounded-lg border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-lg"
                :style="
                    cx(hover) > width - 170
                        ? { right: `${width - cx(hover) + 12}px` }
                        : { left: `${cx(hover) + 12}px` }
                "
                role="status"
            >
                <p class="mb-1 font-medium">{{ rows[hover].label }}</p>
                <p
                    v-for="s in [...series].reverse()"
                    :key="s.key"
                    class="flex items-center gap-2"
                >
                    <span
                        class="h-0.5 w-3 rounded"
                        :style="{ background: s.color }"
                        aria-hidden="true"
                    />
                    <span class="font-semibold tabular-nums">{{
                        value(rows[hover], s.key)
                    }}</span>
                    <span class="text-muted-foreground">{{ s.label }}</span>
                </p>
                <p class="mt-1 border-t pt-1 text-muted-foreground">
                    <span class="font-semibold text-foreground tabular-nums">{{
                        total(rows[hover])
                    }}</span>
                    total
                </p>
            </div>
        </div>

        <div v-if="showTable" class="max-h-72 overflow-auto">
            <table class="w-full text-sm">
                <caption class="sr-only">
                    {{
                        ariaLabel
                    }}
                </caption>
                <thead
                    class="sticky top-0 bg-card text-xs text-muted-foreground"
                >
                    <tr>
                        <th scope="col" class="py-1 text-left font-medium">
                            Day
                        </th>
                        <th
                            v-for="s in series"
                            :key="s.key"
                            scope="col"
                            class="py-1 text-right font-medium"
                        >
                            {{ s.label }}
                        </th>
                        <th scope="col" class="py-1 text-right font-medium">
                            Total
                        </th>
                    </tr>
                </thead>
                <tbody class="tabular-nums">
                    <tr v-for="row in rows" :key="row.label" class="border-t">
                        <th scope="row" class="py-1 text-left font-normal">
                            {{ row.label }}
                        </th>
                        <td v-for="s in series" :key="s.key" class="text-right">
                            {{ value(row, s.key) }}
                        </td>
                        <td class="text-right font-medium">{{ total(row) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
