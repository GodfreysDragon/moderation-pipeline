<script setup lang="ts">
import {
    CircleCheck,
    CircleDashed,
    CircleMinus,
    CircleX,
    LoaderCircle,
} from '@lucide/vue';
import type { PipelineStep } from '@/types';

defineProps<{ steps: PipelineStep[]; detailed?: boolean }>();

const icons = {
    running: LoaderCircle,
    succeeded: CircleCheck,
    skipped: CircleMinus,
    failed: CircleX,
};

const skipReasons: Record<string, string> = {
    text_only: 'No image',
    already_decided: 'Already decided upstream',
    already_prepared: 'Already prepared',
};

const duration = (ms: number | null) =>
    ms === null ? '' : ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`;

/** One plain-language line per stage, from the recorded detail. */
const describe = (step: PipelineStep): string | null => {
    const d = step.detail ?? {};

    if (step.status === 'skipped') {
        return skipReasons[String(d.reason)] ?? null;
    }

    if (step.status === 'failed') {
        return step.error;
    }

    switch (step.stage) {
        case 'prepare':
            return `${d.width}×${d.height}, metadata stripped${d.had_gps ? ' (GPS location removed)' : ''}`;
        case 'match_known':
            return d.match === 'none'
                ? `No match in ${d.hashes_checked} blocked images`
                : `${d.match === 'exact' ? 'Exact' : 'Near-duplicate'} match (distance ${d.closest_distance})`;
        case 'classify':
            return `${d.model}: highest ${d.top_category} ${Number(d.top_score).toFixed(2)}`;
        case 'apply_policy':
            return `Policy v${d.policy_version} → ${d.outcome}${d.audit_sampled ? ', sampled for QA audit' : ''}`;
    }

    return null;
};
</script>

<template>
    <ol class="relative space-y-4 border-l border-border pl-5">
        <li v-for="step in steps" :key="step.id" class="relative">
            <component
                :is="icons[step.status] ?? CircleDashed"
                class="absolute top-0.5 -left-[1.95rem] size-5 rounded-full bg-background"
                :class="{
                    'text-emerald-600 dark:text-emerald-400':
                        step.status === 'succeeded',
                    'text-muted-foreground': step.status === 'skipped',
                    'text-red-600 dark:text-red-400': step.status === 'failed',
                    'animate-spin text-muted-foreground':
                        step.status === 'running',
                }"
                aria-hidden="true"
            />
            <div class="flex flex-wrap items-baseline gap-x-2 text-sm">
                <span class="font-medium">{{ step.label }}</span>
                <span class="text-xs text-muted-foreground capitalize">
                    {{ step.status
                    }}<template v-if="step.attempt > 1">
                        · attempt {{ step.attempt }}</template
                    >
                </span>
                <span
                    v-if="step.duration_ms !== null"
                    class="ml-auto text-xs text-muted-foreground tabular-nums"
                    >{{ duration(step.duration_ms) }}</span
                >
            </div>
            <p
                v-if="detailed && describe(step)"
                class="mt-0.5 text-xs break-words text-muted-foreground"
            >
                {{ describe(step) }}
            </p>
        </li>
    </ol>
</template>
