<script setup lang="ts">
import { CircleCheck, CircleX, Clock, LoaderCircle } from '@lucide/vue';
import { computed } from 'vue';
import type { SubmissionStatus } from '@/types';

const props = defineProps<{ status: SubmissionStatus }>();

// Status always pairs colour with an icon and a label, never colour alone.
const look = computed(
    () =>
        ({
            processing: {
                label: 'Processing',
                icon: LoaderCircle,
                class: 'bg-muted text-muted-foreground',
                spin: true,
            },
            pending_review: {
                label: 'In review',
                icon: Clock,
                class: 'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-300',
                spin: false,
            },
            approved: {
                label: 'Approved',
                icon: CircleCheck,
                class: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-500/15 dark:text-emerald-300',
                spin: false,
            },
            rejected: {
                label: 'Rejected',
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
