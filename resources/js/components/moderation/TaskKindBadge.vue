<script setup lang="ts">
import { ArrowUpRight, ClipboardCheck, Inbox, Scale } from '@lucide/vue';
import type { TaskKind } from '@/types';

defineProps<{ kind: TaskKind }>();

const kinds = {
    triage: { label: 'Triage', icon: Inbox },
    escalation: { label: 'Escalation', icon: ArrowUpRight },
    appeal: { label: 'Appeal', icon: Scale },
    audit: { label: 'QA audit', icon: ClipboardCheck },
};
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-xs font-medium"
    >
        <component :is="kinds[kind].icon" class="size-3.5" aria-hidden="true" />
        {{ kinds[kind].label }}
    </span>
</template>
