<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { EyeOff, FileText, Lock, Play } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/moderation/Pagination.vue';
import TaskKindBadge from '@/components/moderation/TaskKindBadge.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { timeAgo } from '@/lib/format';
import { index, next, show } from '@/routes/review';
import type {
    Paginated,
    ReviewTask,
    SubmissionSummary,
    TaskKind,
} from '@/types';

type QueueRow = ReviewTask & {
    excluded: boolean;
    mine: boolean;
    submission: SubmissionSummary;
};

const props = defineProps<{
    tasks: Paginated<QueueRow>;
    kinds: { value: TaskKind; label: string; open: number }[];
    filters: { kind: TaskKind | null };
    exposure: { graphic_today: number; limit: number };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Review queue', href: index() }],
    },
});

const filter = (kind: TaskKind | null) =>
    router.get(
        index({ query: kind ? { kind } : {} }),
        {},
        {
            preserveScroll: true,
            preserveState: true,
        },
    );

const claim = useForm({ kind: props.filters.kind });
const startReviewing = () => {
    claim.kind = props.filters.kind;
    claim.submit(next());
};
</script>

<template>
    <Head title="Review queue" />

    <div class="space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Review queue"
                description="Most urgent first. Claiming a task locks it for you for a few minutes so nobody duplicates your work."
            />
            <Button :disabled="claim.processing" @click="startReviewing">
                <Spinner v-if="claim.processing" />
                <Play v-else />
                Review next
            </Button>
        </div>

        <p
            v-if="exposure.graphic_today >= exposure.limit"
            class="flex gap-2 rounded-lg bg-amber-100 p-3 text-sm text-amber-950 dark:bg-amber-500/15 dark:text-amber-200"
        >
            <EyeOff class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            You have decided {{ exposure.graphic_today }} graphic items in the
            last 24 hours. “Review next” now gives you non-graphic work only.
            Take a break if you can.
        </p>
        <p
            v-else-if="exposure.graphic_today > 0"
            class="text-xs text-muted-foreground"
        >
            {{ exposure.graphic_today }} of {{ exposure.limit }} graphic items
            today. After that, “Review next” gives you non-graphic work only.
        </p>

        <div class="flex flex-wrap gap-2" role="tablist">
            <Button
                size="sm"
                role="tab"
                :aria-selected="filters.kind === null"
                :variant="filters.kind === null ? 'default' : 'outline'"
                @click="filter(null)"
            >
                All
                <span class="tabular-nums opacity-70">{{
                    kinds.reduce((sum, k) => sum + k.open, 0)
                }}</span>
            </Button>
            <Button
                v-for="kind in kinds"
                :key="kind.value"
                size="sm"
                role="tab"
                :aria-selected="filters.kind === kind.value"
                :variant="filters.kind === kind.value ? 'default' : 'outline'"
                @click="filter(kind.value)"
            >
                {{ kind.label }}
                <span class="tabular-nums opacity-70">{{ kind.open }}</span>
            </Button>
        </div>

        <p
            v-if="tasks.data.length === 0"
            class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            The queue is empty.
        </p>

        <ul v-else class="divide-y rounded-xl border">
            <li v-for="task in tasks.data" :key="task.id">
                <Link
                    :href="show(task.id)"
                    class="flex items-center gap-3 p-3 transition hover:bg-accent/50 focus-visible:bg-accent/50 focus-visible:outline-none"
                >
                    <div
                        class="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-md bg-muted"
                    >
                        <img
                            v-if="task.submission.thumbnail_url"
                            :src="task.submission.thumbnail_url"
                            alt=""
                            class="size-full object-cover"
                            :class="{ 'scale-110 blur-md': task.graphic }"
                            loading="lazy"
                        />
                        <FileText
                            v-else
                            class="size-6 text-muted-foreground"
                            aria-hidden="true"
                        />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <TaskKindBadge :kind="task.kind" />
                            <span
                                v-if="task.graphic"
                                class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                                ><EyeOff class="size-3.5" aria-hidden="true" />
                                Graphic</span
                            >
                            <span
                                v-if="task.submission.top_category"
                                class="text-xs text-muted-foreground"
                                >{{ task.submission.top_category.label }}
                                {{
                                    task.submission.top_category.score.toFixed(
                                        2,
                                    )
                                }}</span
                            >
                            <span
                                v-else-if="task.submission.decision_reason"
                                class="text-xs text-muted-foreground"
                                >{{ task.submission.decision_reason }}</span
                            >
                        </div>
                        <p class="mt-1 truncate text-sm">
                            {{ task.submission.caption ?? 'No caption' }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ task.submission.uploader }} · queued
                            {{ timeAgo(task.created_at) }}
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1 text-xs">
                        <span
                            class="rounded-md bg-muted px-1.5 py-0.5 font-medium tabular-nums"
                            :title="`Priority ${task.priority}`"
                            >P{{ task.priority }}</span
                        >
                        <span
                            v-if="task.claimed_by"
                            class="flex items-center gap-1 text-muted-foreground"
                        >
                            <Lock class="size-3" aria-hidden="true" />
                            {{ task.mine ? 'You' : task.claimed_by }}
                        </span>
                        <span v-if="task.excluded" class="text-muted-foreground"
                            >Your decision is appealed</span
                        >
                    </div>
                </Link>
            </li>
        </ul>

        <Pagination :page="tasks" />
    </div>
</template>
