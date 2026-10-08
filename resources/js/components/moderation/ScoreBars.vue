<script setup lang="ts">
import { computed } from 'vue';
import type { CategoryScore } from '@/types';

const props = defineProps<{ categories: CategoryScore[] }>();

const rows = computed(() =>
    [...props.categories].sort((a, b) => b.score - a.score),
);

const zone = (c: CategoryScore) =>
    c.reject !== null && c.score >= c.reject
        ? 'reject'
        : c.score >= c.review
          ? 'review'
          : 'clear';

const pct = (v: number) => `${Math.round(v * 100)}%`;
</script>

<template>
    <ul class="space-y-3" aria-label="AI scores by policy category">
        <li v-for="c in rows" :key="c.value">
            <div class="mb-1 flex items-baseline justify-between gap-3 text-sm">
                <span class="font-medium">{{ c.label }}</span>
                <span class="text-xs text-muted-foreground tabular-nums">
                    <span
                        class="font-semibold text-foreground"
                        :class="{
                            'text-red-700 dark:text-red-400':
                                zone(c) === 'reject',
                            'text-amber-700 dark:text-amber-400':
                                zone(c) === 'review',
                        }"
                        >{{ c.score.toFixed(2) }}</span
                    >
                    · review ≥ {{ c.review }} · reject
                    {{ c.reject === null ? 'never auto' : `≥ ${c.reject}` }}
                </span>
            </div>
            <div class="relative h-2.5 rounded-full bg-muted">
                <div
                    class="h-full rounded-full"
                    :class="{
                        'bg-red-600': zone(c) === 'reject',
                        'bg-amber-500': zone(c) === 'review',
                        'bg-muted-foreground/40': zone(c) === 'clear',
                    }"
                    :style="{ width: pct(Math.max(0.01, c.score)) }"
                />
                <!-- Threshold ticks -->
                <span
                    class="absolute -top-1 h-4.5 w-0.5 bg-amber-600"
                    :style="{ left: pct(c.review) }"
                    :title="`Review at ${c.review}`"
                />
                <span
                    v-if="c.reject !== null"
                    class="absolute -top-1 h-4.5 w-0.5 bg-red-700"
                    :style="{ left: pct(c.reject) }"
                    :title="`Reject at ${c.reject}`"
                />
            </div>
            <p
                v-if="c.evidence && c.evidence !== 'none'"
                class="mt-1 text-xs text-muted-foreground"
            >
                {{ c.evidence }}
            </p>
        </li>
    </ul>
</template>
