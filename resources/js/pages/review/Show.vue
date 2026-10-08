<script setup lang="ts">
import { Head, router, setLayoutProps, useForm } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    Check,
    FileText,
    Lock,
    MapPinOff,
    ShieldAlert,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PipelineTimeline from '@/components/moderation/PipelineTimeline.vue';
import ScoreBars from '@/components/moderation/ScoreBars.vue';
import StatusBadge from '@/components/moderation/StatusBadge.vue';
import TaskKindBadge from '@/components/moderation/TaskKindBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { dateTime, sourceLabel } from '@/lib/format';
import { claim, decide, index, release, show } from '@/routes/review';
import { show as submissionPage } from '@/routes/submissions';
import type { ReviewTask, SubmissionDetail } from '@/types';

const props = defineProps<{
    task: ReviewTask & {
        open: boolean;
        mine: boolean;
        can_claim: boolean;
        may_work: boolean;
        excluded: boolean;
    };
    submission: SubmissionDetail;
    reasons: { value: string; label: string }[];
    claimMinutes: number;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Review queue', href: index() },
        { title: `Task #${props.task.id}`, href: show(props.task.id) },
    ],
});

const why = computed(
    () =>
        ({
            triage:
                props.submission.ai?.error !== null &&
                props.submission.ai?.error !== undefined
                    ? 'The AI stage could not score this item, so a person decides.'
                    : 'The scores fell between the review and reject thresholds.',
            escalation: 'A moderator handed this up for an admin decision.',
            appeal: 'The uploader disputes the rejection. You did not make the original decision.',
            audit: `A random QA sample: the pipeline ${props.task.automated_outcome === 'approve' ? 'approved' : 'rejected'} this automatically. Decide independently; disagreeing overturns it.`,
        })[props.task.kind],
);

// --- Claim countdown -----------------------------------------------------

const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => (timer = setInterval(() => (now.value = Date.now()), 10_000)));
onBeforeUnmount(() => clearInterval(timer));

const minutesLeft = computed(() =>
    props.task.claim_expires_at
        ? Math.max(
              0,
              Math.ceil(
                  (new Date(props.task.claim_expires_at).getTime() -
                      now.value) /
                      60_000,
              ),
          )
        : 0,
);

const claimIt = () =>
    router.visit(claim(props.task.id), { preserveScroll: true });
const releaseIt = () => router.visit(release(props.task.id));

// --- Decision ------------------------------------------------------------

const form = useForm({
    decision: '' as '' | 'approve' | 'reject' | 'escalate',
    reason: '',
    note: '',
    block_image: true,
    next: true,
});

const mode = ref<'approve' | 'reject' | 'escalate' | null>(null);

const choose = (decision: 'approve' | 'reject' | 'escalate') => {
    mode.value = decision;
    form.decision = decision;
    form.clearErrors();
};

const submit = () => form.submit(decide(props.task.id));

const canEscalate = computed(() => props.task.kind !== 'escalation');
</script>

<template>
    <Head :title="`Review task #${task.id}`" />

    <div class="grid gap-6 p-4 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div class="min-w-0 space-y-6">
            <Card class="overflow-hidden py-0">
                <div class="flex min-h-48 items-center justify-center bg-muted">
                    <img
                        v-if="submission.image_url"
                        :src="submission.image_url"
                        :alt="submission.caption ?? 'Submitted image'"
                        class="max-h-[34rem] object-contain"
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
                        {{ submission.uploader }} ·
                        {{ dateTime(submission.created_at) }} ·
                        {{ submission.uploader_history?.total ?? 0 }} other
                        posts, {{ submission.uploader_history?.rejected ?? 0 }}
                        rejected
                    </p>
                    <p
                        v-if="submission.had_gps"
                        class="flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <MapPinOff class="size-3.5" aria-hidden="true" />
                        GPS location was stripped from the upload.
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>AI classification</CardTitle>
                    <CardDescription>
                        {{ submission.ai?.model ?? 'No AI result' }}
                        <template v-if="submission.policy_version">
                            · policy v{{ submission.policy_version }}</template
                        >
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
                    <ScoreBars
                        v-if="submission.ai?.categories.length"
                        :categories="submission.ai.categories"
                    />
                </CardContent>
            </Card>
        </div>

        <div class="min-w-0 space-y-6">
            <Card>
                <CardHeader>
                    <div class="flex flex-wrap items-center gap-2">
                        <TaskKindBadge :kind="task.kind" />
                        <StatusBadge :status="submission.status" />
                        <span
                            class="ml-auto rounded-md bg-muted px-1.5 py-0.5 text-xs font-medium tabular-nums"
                            >Priority {{ task.priority }}</span
                        >
                    </div>
                    <CardDescription class="pt-1">{{ why }}</CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div v-if="task.note" class="rounded-lg border p-3 text-sm">
                        <p
                            class="mb-1 text-xs font-medium text-muted-foreground"
                        >
                            Escalation note
                        </p>
                        “{{ task.note }}”
                    </div>
                    <div
                        v-if="
                            task.kind === 'appeal' && submission.appeal_message
                        "
                        class="rounded-lg border p-3 text-sm"
                    >
                        <p
                            class="mb-1 text-xs font-medium text-muted-foreground"
                        >
                            Uploader’s appeal
                        </p>
                        <p class="whitespace-pre-line">
                            {{ submission.appeal_message }}
                        </p>
                    </div>
                    <p
                        v-if="
                            submission.decision_source && task.kind !== 'triage'
                        "
                        class="text-xs text-muted-foreground"
                    >
                        Current decision: {{ submission.status }} by
                        {{
                            submission.decided_by ??
                            sourceLabel[submission.decision_source]
                        }}<template v-if="submission.decision_reason">
                            ({{ submission.decision_reason }})</template
                        >
                    </p>

                    <!-- Resolved -->
                    <p
                        v-if="!task.open"
                        class="rounded-lg bg-muted p-3 text-sm"
                    >
                        Resolved:
                        <strong class="capitalize">{{ task.decision }}</strong>
                        by {{ task.resolved_by }},
                        {{ dateTime(task.resolved_at!) }}.
                    </p>

                    <!-- Someone else holds it, or the user can't take it -->
                    <template v-else-if="!task.mine">
                        <p
                            v-if="task.claimed_by"
                            class="flex items-center gap-2 text-sm"
                        >
                            <Lock class="size-4" aria-hidden="true" />
                            {{ task.claimed_by }} is reviewing this.
                        </p>
                        <p v-else-if="task.excluded" class="text-sm">
                            You made the decision being appealed, so someone
                            else must review it.
                        </p>
                        <p v-else-if="!task.may_work" class="text-sm">
                            Only admins can decide escalations.
                        </p>
                        <Button v-if="task.can_claim" @click="claimIt">
                            <Lock /> Claim for {{ claimMinutes }} minutes
                        </Button>
                    </template>

                    <!-- Decision panel -->
                    <form v-else class="space-y-4" @submit.prevent="submit">
                        <p class="text-xs text-muted-foreground">
                            Yours for {{ minutesLeft }} more minute{{
                                minutesLeft === 1 ? '' : 's'
                            }}.
                            <button
                                type="button"
                                class="underline underline-offset-2"
                                @click="releaseIt"
                            >
                                Release
                            </button>
                        </p>

                        <div
                            class="grid grid-cols-3 gap-2"
                            role="radiogroup"
                            aria-label="Decision"
                        >
                            <Button
                                type="button"
                                role="radio"
                                :aria-checked="mode === 'approve'"
                                :variant="
                                    mode === 'approve' ? 'default' : 'outline'
                                "
                                @click="choose('approve')"
                            >
                                <Check /> Approve
                            </Button>
                            <Button
                                type="button"
                                role="radio"
                                :aria-checked="mode === 'reject'"
                                :variant="
                                    mode === 'reject'
                                        ? 'destructive'
                                        : 'outline'
                                "
                                @click="choose('reject')"
                            >
                                <X /> Reject
                            </Button>
                            <Button
                                type="button"
                                role="radio"
                                :aria-checked="mode === 'escalate'"
                                :disabled="!canEscalate"
                                :variant="
                                    mode === 'escalate'
                                        ? 'secondary'
                                        : 'outline'
                                "
                                @click="choose('escalate')"
                            >
                                <ArrowUpRight /> Escalate
                            </Button>
                        </div>
                        <InputError :message="form.errors.decision" />

                        <fieldset v-if="mode === 'reject'" class="space-y-2">
                            <legend class="mb-2 text-sm font-medium">
                                Which policy does it break?
                            </legend>
                            <div class="flex flex-wrap gap-2">
                                <label
                                    v-for="reason in reasons"
                                    :key="reason.value"
                                    class="cursor-pointer rounded-full border px-3 py-1 text-sm transition has-[:checked]:border-foreground has-[:checked]:bg-foreground has-[:checked]:text-background has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
                                >
                                    <input
                                        v-model="form.reason"
                                        type="radio"
                                        name="reason"
                                        :value="reason.value"
                                        class="sr-only"
                                    />
                                    {{ reason.label }}
                                </label>
                            </div>
                            <InputError :message="form.errors.reason" />
                            <Label
                                v-if="submission.image_url"
                                class="flex items-center gap-2 pt-1 font-normal"
                            >
                                <Checkbox v-model="form.block_image" />
                                Block re-uploads of this image (and close
                                copies)
                            </Label>
                        </fieldset>

                        <div v-if="mode" class="space-y-2">
                            <Label for="note">{{
                                mode === 'escalate'
                                    ? 'Why does an admin need to see this?'
                                    : 'Note (optional)'
                            }}</Label>
                            <Textarea id="note" v-model="form.note" rows="2" />
                            <InputError :message="form.errors.note" />
                        </div>

                        <div
                            v-if="mode"
                            class="flex flex-wrap items-center justify-between gap-3"
                        >
                            <Label class="flex items-center gap-2 font-normal">
                                <Checkbox v-model="form.next" />
                                Open the next task after this
                            </Label>
                            <Button type="submit" :disabled="form.processing">
                                <Spinner v-if="form.processing" />
                                Confirm {{ mode }}
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Pipeline</CardTitle>
                </CardHeader>
                <CardContent>
                    <PipelineTimeline :steps="submission.steps" detailed />
                    <a
                        :href="submissionPage(submission.id).url"
                        class="mt-4 inline-block text-xs underline underline-offset-2"
                        >Full submission record</a
                    >
                </CardContent>
            </Card>
        </div>
    </div>
</template>
