<script setup lang="ts">
import { CircleCheck, CircleX, Clock, LoaderCircle } from '@lucide/vue';
import { computed } from 'vue';
import type { EvaluationStatus } from '@/types';

const props = defineProps<{ status: EvaluationStatus }>();

// Same pattern as StatusBadge: colour, icon and label together.
const look = computed(
    () =>
        ({
            pending: {
                label: 'Queued',
                icon: Clock,
                class: 'bg-muted text-muted-foreground',
                spin: false,
            },
            running: {
                label: 'Batch running',
                icon: LoaderCircle,
                class: 'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-300',
                spin: true,
            },
            completed: {
                label: 'Completed',
                icon: CircleCheck,
                class: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-500/15 dark:text-emerald-300',
                spin: false,
            },
            failed: {
                label: 'Failed',
                icon: CircleX,
                class: 'bg-red-100 text-red-900 dark:bg-red-500/15 dark:text-red-300',
                spin: false,
            },
        })[props.status],
);
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="look.class"
    >
        <component
            :is="look.icon"
            class="size-3.5"
            :class="{ 'animate-spin': look.spin }"
            aria-hidden="true"
        />
        {{ look.label }}
    </span>
</template>
