<script setup lang="ts">
import { Head, Link, setLayoutProps, useForm, usePoll } from '@inertiajs/vue3';
import { FileText, ImageOff, MapPinOff, ShieldAlert } from '@lucide/vue';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import ImageText from '@/components/moderation/ImageText.vue';
import PipelineTimeline from '@/components/moderation/PipelineTimeline.vue';
import ScoreBars from '@/components/moderation/ScoreBars.vue';
import StatusBadge from '@/components/moderation/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { dateTime, sourceLabel } from '@/lib/format';
import { show as reviewTask } from '@/routes/review';
import { appeal, index, show } from '@/routes/submissions';
import type { SubmissionDetail } from '@/types';

const props = defineProps<{ submission: SubmissionDetail }>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Submissions', href: index() },
        { title: `#${props.submission.id}`, href: show(props.submission.id) },
    ],
});

const staff = computed(() => props.submission.ai !== undefined);

// Poll while the pipeline is still running.
const { start, stop } = usePoll(
    1000,
    { only: ['submission'] },
    { autoStart: false },
);
watch(
    () => props.submission.status,
    (status) => (status === 'processing' ? start() : stop()),
    { immediate: true },
);

const headline = computed(() => {
    const s = props.submission;

    switch (s.status) {
        case 'processing':
            return 'Being checked…';
        case 'pending_review':
            return s.appealed
                ? 'Your appeal is with a moderator.'
                : 'A moderator will take a look shortly.';
        case 'approved':
            return 'Approved and visible.';
        case 'rejected':
            return 'Removed for breaking the content policy.';
    }

    return '';
});

const openTask = computed(() =>
    props.submission.tasks?.find((t) => t.resolved_at === null),
);

const form = useForm({ message: '' });
const sendAppeal = () =>
    form.submit(appeal(props.submission.id), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
</script>

<template>
    <Head :title="`Submission #${submission.id}`" />

    <div
        class="grid grid-cols-1 gap-6 p-4 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]"
    >
        <div class="min-w-0 space-y-6">
            <Card class="overflow-hidden py-0">
                <div class="flex min-h-48 items-center justify-center bg-muted">
                    <img
                        v-if="submission.image_url"
                        :src="submission.image_url"
                        :alt="submission.caption ?? 'Submitted image'"
                        class="max-h-[32rem] object-contain"
                    />
                    <p
                        v-else-if="submission.media_pruned_at"
                        class="flex max-w-xs flex-col items-center gap-2 p-6 text-center text-sm text-muted-foreground"
                    >
                        <ImageOff class="size-8" aria-hidden="true" />
                        The image was deleted
                        {{ dateTime(submission.media_pruned_at) }} under the
                        retention policy for rejected content.
                    </p>
                    <Spinner
                        v-else-if="submission.has_image"
                        class="size-6 text-muted-foreground"
                    />
                    <FileText
                        v-else
                        class="size-10 text-muted-foreground"
                        aria-label="Text-only post"
                    />
                </div>
                <CardContent class="space-y-2 pb-5">
                    <p class="whitespace-pre-line">
                        {{ submission.caption ?? 'No caption' }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        <template v-if="submission.uploader"
                            >Posted by {{ submission.uploader }} ·
                        </template>
                        {{ dateTime(submission.created_at) }}
                        <template v-if="submission.width">
                            · {{ submission.width }}×{{ submission.height }}
                        </template>
                    </p>
                    <p
                        v-if="submission.had_gps"
                        class="flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <MapPinOff class="size-3.5" aria-hidden="true" />
                        The original photo contained a GPS location. It was
                        removed before storage.
                    </p>
                </CardContent>
            </Card>

            <Card v-if="staff">
                <CardHeader>
                    <CardTitle>AI classification</CardTitle>
                    <CardDescription>
                        {{ submission.ai?.model ?? 'Not classified' }}
                        <template v-if="submission.policy_version">
                            · thresholds from policy v{{
                                submission.policy_version
                            }}
                        </template>
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <p
                        v-if="submission.ai?.error"
                        class="flex gap-2 rounded-lg bg-amber-100 p-3 text-sm text-amber-950 dark:bg-amber-500/15 dark:text-amber-200"
                    >
                        <ShieldAlert
                            class="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        {{ submission.ai.error }}
                    </p>
                    <p v-if="submission.ai?.summary" class="text-sm">
                        {{ submission.ai.summary }}
                    </p>
                    <ImageText
                        v-if="submission.ai?.image_text"
                        :text="submission.ai.image_text"
                    />
                    <ScoreBars
                        v-if="submission.ai?.categories.length"
                        :categories="submission.ai.categories"
                    />
                    <p
                        v-else-if="!submission.ai"
                        class="text-sm text-muted-foreground"
                    >
                        No AI result: the item was decided before classification
                        or is still processing.
                    </p>
                </CardContent>
            </Card>
        </div>

        <div class="min-w-0 space-y-6">
            <Card>
                <CardHeader>
                    <div class="flex items-center gap-2">
                        <StatusBadge :status="submission.status" />
                    </div>
                    <CardTitle class="text-lg">{{ headline }}</CardTitle>
                    <CardDescription v-if="submission.decision_reason">
                        Reason: {{ submission.decision_reason }}
                        <template v-if="submission.decision_source">
                            · decided by
                            {{
                                submission.decided_by ??
                                sourceLabel[submission.decision_source]
                            }}
                        </template>
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <Button v-if="openTask" as-child size="sm">
                        <Link :href="reviewTask(openTask.id)"
                            >Open
                            {{ openTask.kind_label.toLowerCase() }} task</Link
                        >
                    </Button>

                    <div
                        v-if="submission.appeal_message"
                        class="rounded-lg border p-3 text-sm"
                    >
                        <p
                            class="mb-1 text-xs font-medium text-muted-foreground"
                        >
                            Appeal sent
                            {{ dateTime(submission.appealed_at!) }}
                        </p>
                        <p class="whitespace-pre-line">
                            {{ submission.appeal_message }}
                        </p>
                    </div>

                    <form
                        v-if="submission.can_appeal"
                        class="space-y-2"
                        @submit.prevent="sendAppeal"
                    >
                        <Label for="appeal">Think we got it wrong?</Label>
                        <Textarea
                            id="appeal"
                            v-model="form.message"
                            rows="3"
                            placeholder="Tell us what we missed. A different moderator will review it."
                        />
                        <InputError :message="form.errors.message" />
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-xs text-muted-foreground">
                                One appeal, until
                                {{ dateTime(submission.appeal_deadline!) }}
                            </span>
                            <Button
                                type="submit"
                                size="sm"
                                :disabled="form.processing"
                            >
                                Send appeal
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Pipeline</CardTitle>
                    <CardDescription
                        >Every stage this item went through.</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <PipelineTimeline
                        :steps="submission.steps"
                        :detailed="staff"
                    />
                </CardContent>
            </Card>

            <Card v-if="staff && submission.tasks?.length">
                <CardHeader>
                    <CardTitle>Review history</CardTitle>
                </CardHeader>
                <CardContent>
                    <ul class="space-y-3 text-sm">
                        <li
                            v-for="task in submission.tasks"
                            :key="task.id"
                            class="border-b pb-3 last:border-0 last:pb-0"
                        >
                            <div class="flex flex-wrap items-baseline gap-x-2">
                                <span class="font-medium">{{
                                    task.kind_label
                                }}</span>
                                <span
                                    v-if="task.decision"
                                    class="text-muted-foreground capitalize"
                                    >{{ task.decision }} by
                                    {{ task.resolved_by }}</span
                                >
                                <span v-else class="text-muted-foreground"
                                    >open<template v-if="task.claimed_by">
                                        · {{ task.claimed_by }} is
                                        reviewing</template
                                    ></span
                                >
                                <span
                                    class="ml-auto text-xs text-muted-foreground"
                                    >{{
                                        dateTime(
                                            task.resolved_at ?? task.created_at,
                                        )
                                    }}</span
                                >
                            </div>
                            <p
                                v-if="task.reason"
                                class="text-xs text-muted-foreground"
                            >
                                {{ task.reason }}
                            </p>
                            <p
                                v-if="task.decision_note || task.note"
                                class="mt-1 text-xs"
                            >
                                “{{ task.decision_note ?? task.note }}”
                            </p>
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <Card v-if="staff && submission.dhash">
                <CardHeader>
                    <CardTitle>Fingerprints</CardTitle>
                    <CardDescription
                        >Used to catch re-uploads of rejected
                        images.</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <dl class="space-y-1 font-mono text-xs break-all">
                        <div>
                            <dt class="inline text-muted-foreground">
                                SHA-256
                            </dt>
                            <dd class="inline">{{ submission.sha256 }}</dd>
                        </div>
                        <div>
                            <dt class="inline text-muted-foreground">dHash</dt>
                            <dd class="inline">{{ submission.dhash }}</dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
