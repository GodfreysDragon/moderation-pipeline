<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { FileText, Upload } from '@lucide/vue';
import { computed, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/moderation/Pagination.vue';
import StatusBadge from '@/components/moderation/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { timeAgo } from '@/lib/format';
import { create, index, show } from '@/routes/submissions';
import type { Paginated, SubmissionStatus, SubmissionSummary } from '@/types';

const props = defineProps<{
    submissions: Paginated<SubmissionSummary>;
    filters: { status: SubmissionStatus | null };
    scope: 'all' | 'mine';
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Submissions', href: index() }],
    },
});

const tabs: { label: string; value: SubmissionStatus | null }[] = [
    { label: 'All', value: null },
    { label: 'In review', value: 'pending_review' },
    { label: 'Approved', value: 'approved' },
    { label: 'Rejected', value: 'rejected' },
];

const filter = (status: SubmissionStatus | null) =>
    router.get(
        index({ query: status ? { status } : {} }),
        {},
        {
            preserveScroll: true,
            preserveState: true,
        },
    );

// Refresh while anything is still moving through the pipeline.
const processing = computed(() =>
    props.submissions.data.some((s) => s.status === 'processing'),
);
const { start, stop } = usePoll(
    1500,
    { only: ['submissions'] },
    { autoStart: false },
);
watch(processing, (busy) => (busy ? start() : stop()), { immediate: true });
</script>

<template>
    <Head :title="scope === 'all' ? 'All submissions' : 'My submissions'" />

    <div class="space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="scope === 'all' ? 'All submissions' : 'My submissions'"
                :description="
                    scope === 'all'
                        ? 'Everything uploaded, newest first.'
                        : 'What you have posted and where each item stands.'
                "
            />
            <Button as-child>
                <Link :href="create()"><Upload /> Submit content</Link>
            </Button>
        </div>

        <div class="flex flex-wrap gap-2" role="tablist">
            <Button
                v-for="tab in tabs"
                :key="tab.label"
                size="sm"
                :variant="filters.status === tab.value ? 'default' : 'outline'"
                role="tab"
                :aria-selected="filters.status === tab.value"
                @click="filter(tab.value)"
            >
                {{ tab.label }}
            </Button>
        </div>

        <p
            v-if="submissions.data.length === 0"
            class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            Nothing here yet.
        </p>

        <ul
            v-else
            class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4"
        >
            <li v-for="s in submissions.data" :key="s.id">
                <Link
                    :href="show(s.id)"
                    class="group flex h-full flex-col overflow-hidden rounded-xl border bg-card transition hover:border-foreground/30 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <div
                        class="flex aspect-[4/3] items-center justify-center overflow-hidden bg-muted"
                    >
                        <img
                            v-if="s.thumbnail_url"
                            :src="s.thumbnail_url"
                            alt=""
                            class="size-full object-cover"
                            loading="lazy"
                        />
                        <FileText
                            v-else
                            class="size-10 text-muted-foreground"
                            aria-hidden="true"
                        />
                    </div>
                    <div class="flex flex-1 flex-col gap-2 p-3">
                        <p class="line-clamp-2 text-sm">
                            {{ s.caption ?? 'No caption' }}
                        </p>
                        <div
                            class="mt-auto flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                        >
                            <StatusBadge :status="s.status" />
                            <span v-if="s.decision_reason">{{
                                s.decision_reason
                            }}</span>
                            <span class="ml-auto">{{
                                timeAgo(s.created_at)
                            }}</span>
                        </div>
                        <p
                            v-if="s.uploader"
                            class="text-xs text-muted-foreground"
                        >
                            by {{ s.uploader }}
                        </p>
                    </div>
                </Link>
            </li>
        </ul>

        <Pagination :page="submissions" />
    </div>
</template>
