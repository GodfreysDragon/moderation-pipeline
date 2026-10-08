<script setup lang="ts">
import { Head, Link, useForm, useHttp } from '@inertiajs/vue3';
import { FlaskConical } from '@lucide/vue';
import { computed, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { dateTime, percent } from '@/lib/format';
import { edit, preview, store } from '@/routes/policy';
import { show as submissionPage } from '@/routes/submissions';

type CategoryPolicy = {
    value: string;
    label: string;
    definition: string;
    severity: number;
    review: number;
    reject: number | null;
};

type Thresholds = Record<string, { review: number; reject: number | null }>;

type Backtest = {
    sample: number;
    labelled: number;
    current: Record<'approve' | 'review' | 'reject', number>;
    proposed: Record<'approve' | 'review' | 'reject', number>;
    changed: number;
    transitions: Record<string, number>;
    errors: Record<
        'current' | 'proposed',
        { missed: number; wrongful: number }
    >;
    examples: { id: number; from: string; to: string; human: string | null }[];
};

const props = defineProps<{
    policy: {
        id: number;
        audit_sample_rate: number;
        blocklist_distance: number;
        categories: CategoryPolicy[];
    };
    versions: {
        id: number;
        note: string | null;
        author: string | null;
        created_at: string;
    }[];
    blocklistSize: number;
    canEdit: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Policy', href: edit() }],
    },
});

const initialThresholds = (): Thresholds =>
    Object.fromEntries(
        props.policy.categories.map((c) => [
            c.value,
            { review: c.review, reject: c.reject },
        ]),
    );

const form = useForm({
    thresholds: initialThresholds(),
    audit_sample_rate: props.policy.audit_sample_rate,
    blocklist_distance: props.policy.blocklist_distance,
    note: '',
});

const backtest = useHttp<{ thresholds: Thresholds }, Backtest>(preview(), {
    thresholds: initialThresholds(),
});

const runBacktest = () => {
    backtest.thresholds = JSON.parse(JSON.stringify(form.thresholds));
    backtest.submit();
};

// A stale backtest would mislead: clear it whenever the thresholds change.
watch(
    () => JSON.stringify(form.thresholds),
    () => (backtest.response = null),
);

const autoReject = (category: string) =>
    form.thresholds[category].reject !== null;

const toggleAutoReject = (category: string, on: boolean) => {
    const limits = form.thresholds[category];
    limits.reject = on ? Math.max(limits.review, 0.85) : null;
};

const save = () =>
    form.submit(store(), {
        preserveScroll: true,
        onSuccess: () => form.reset('note'),
    });

const result = computed(() => backtest.response);

const outcomes = ['approve', 'review', 'reject'] as const;

const delta = (before: number, after: number) => {
    const d = after - before;

    return d === 0 ? '±0' : d > 0 ? `+${d}` : `${d}`;
};

const errorOf = (key: string) =>
    (backtest.errors as Record<string, string | undefined>)[key] ??
    (form.errors as Record<string, string | undefined>)[key];
</script>

<template>
    <Head title="Policy" />

    <div class="space-y-6 p-4">
        <Heading
            title="Moderation policy"
            :description="`Version ${policy.id}. Every decision records the version it used, so changes never rewrite history.`"
        />

        <div class="grid gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <form class="min-w-0 space-y-6" @submit.prevent="save">
                <Card>
                    <CardHeader>
                        <CardTitle>Thresholds</CardTitle>
                        <CardDescription>
                            At or above <em>review</em>, a person decides. At or
                            above <em>reject</em>, the item is removed
                            automatically. Below both, it is approved.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="divide-y">
                        <div
                            v-for="c in policy.categories"
                            :key="c.value"
                            class="grid gap-3 py-4 first:pt-0 last:pb-0 md:grid-cols-[minmax(0,1fr)_auto]"
                        >
                            <div>
                                <p class="font-medium">
                                    {{ c.label }}
                                    <span
                                        class="ml-1 text-xs font-normal text-muted-foreground"
                                        >severity {{ c.severity }}</span
                                    >
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ c.definition }}
                                </p>
                            </div>
                            <div class="flex flex-wrap items-end gap-3">
                                <div class="space-y-1">
                                    <Label
                                        :for="`review-${c.value}`"
                                        class="text-xs"
                                        >Review at</Label
                                    >
                                    <Input
                                        :id="`review-${c.value}`"
                                        v-model.number="
                                            form.thresholds[c.value].review
                                        "
                                        type="number"
                                        min="0.05"
                                        max="1"
                                        step="0.05"
                                        class="w-24"
                                        :disabled="!canEdit"
                                    />
                                </div>
                                <div class="space-y-1">
                                    <Label
                                        :for="`reject-${c.value}`"
                                        class="text-xs"
                                        >Reject at</Label
                                    >
                                    <Input
                                        v-if="autoReject(c.value)"
                                        :id="`reject-${c.value}`"
                                        :model-value="
                                            form.thresholds[c.value].reject ??
                                            undefined
                                        "
                                        @update:model-value="
                                            (v) =>
                                                (form.thresholds[
                                                    c.value
                                                ].reject = Number(v))
                                        "
                                        type="number"
                                        min="0.05"
                                        max="1"
                                        step="0.05"
                                        class="w-24"
                                        :disabled="!canEdit"
                                    />
                                    <p
                                        v-else
                                        class="flex h-9 w-24 items-center text-xs text-muted-foreground"
                                    >
                                        Never auto
                                    </p>
                                </div>
                                <Label
                                    class="flex h-9 items-center gap-2 text-xs font-normal"
                                >
                                    <Checkbox
                                        :model-value="autoReject(c.value)"
                                        :disabled="!canEdit"
                                        @update:model-value="
                                            (on) =>
                                                toggleAutoReject(
                                                    c.value,
                                                    on === true,
                                                )
                                        "
                                    />
                                    Auto-reject
                                </Label>
                                <InputError
                                    class="basis-full"
                                    :message="
                                        errorOf(
                                            `thresholds.${c.value}.review`,
                                        ) ??
                                        errorOf(`thresholds.${c.value}.reject`)
                                    "
                                />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Sampling and matching</CardTitle>
                    </CardHeader>
                    <CardContent class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1">
                            <Label for="audit">QA audit sample rate</Label>
                            <Input
                                id="audit"
                                v-model.number="form.audit_sample_rate"
                                type="number"
                                min="0"
                                max="1"
                                step="0.01"
                                :disabled="!canEdit"
                            />
                            <p class="text-xs text-muted-foreground">
                                {{ percent(form.audit_sample_rate || 0, 1) }} of
                                automated decisions are re-checked by a person
                                to measure precision.
                            </p>
                            <InputError
                                :message="form.errors.audit_sample_rate"
                            />
                        </div>
                        <div class="space-y-1">
                            <Label for="distance"
                                >Near-duplicate distance</Label
                            >
                            <Input
                                id="distance"
                                v-model.number="form.blocklist_distance"
                                type="number"
                                min="0"
                                max="16"
                                :disabled="!canEdit"
                            />
                            <p class="text-xs text-muted-foreground">
                                Max differing bits (of 64) for a perceptual-hash
                                match. {{ blocklistSize }} images blocked.
                            </p>
                            <InputError
                                :message="form.errors.blocklist_distance"
                            />
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="canEdit">
                    <CardContent class="space-y-3 pt-6">
                        <Label for="note">What changed and why</Label>
                        <Input
                            id="note"
                            v-model="form.note"
                            placeholder="e.g. Fewer spam false positives after the audit review"
                        />
                        <InputError :message="form.errors.note" />
                        <div class="flex flex-wrap justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="backtest.processing"
                                @click="runBacktest"
                            >
                                <Spinner v-if="backtest.processing" />
                                <FlaskConical v-else /> Backtest
                            </Button>
                            <Button type="submit" :disabled="form.processing">
                                Save new version
                            </Button>
                        </div>
                    </CardContent>
                </Card>
                <p v-else class="text-sm text-muted-foreground">
                    Only admins can change the policy.
                </p>
            </form>

            <div class="min-w-0 space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Backtest</CardTitle>
                        <CardDescription>
                            Replays the most recent classified items under the
                            current and proposed thresholds. Items a person
                            decided show where each policy would go wrong.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <p v-if="!result" class="text-sm text-muted-foreground">
                            {{
                                canEdit
                                    ? 'Change some thresholds, then run a backtest before saving.'
                                    : 'Available to admins.'
                            }}
                        </p>
                        <div v-else class="space-y-5">
                            <p class="text-sm">
                                <strong class="tabular-nums">{{
                                    result.changed
                                }}</strong>
                                of {{ result.sample }} items would be decided
                                differently.
                            </p>

                            <table class="w-full text-sm">
                                <caption class="sr-only">
                                    Outcomes under each policy
                                </caption>
                                <thead class="text-xs text-muted-foreground">
                                    <tr>
                                        <th
                                            scope="col"
                                            class="py-1 text-left font-medium"
                                        >
                                            Outcome
                                        </th>
                                        <th
                                            scope="col"
                                            class="py-1 text-right font-medium"
                                        >
                                            Current
                                        </th>
                                        <th
                                            scope="col"
                                            class="py-1 text-right font-medium"
                                        >
                                            Proposed
                                        </th>
                                        <th
                                            scope="col"
                                            class="py-1 text-right font-medium"
                                        >
                                            Change
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="tabular-nums">
                                    <tr
                                        v-for="o in outcomes"
                                        :key="o"
                                        class="border-t"
                                    >
                                        <th
                                            scope="row"
                                            class="py-1.5 text-left font-normal capitalize"
                                        >
                                            {{
                                                o === 'review'
                                                    ? 'Human review'
                                                    : `Auto-${o}`
                                            }}
                                        </th>
                                        <td class="text-right">
                                            {{ result.current[o] }}
                                        </td>
                                        <td class="text-right">
                                            {{ result.proposed[o] }}
                                        </td>
                                        <td
                                            class="text-right text-muted-foreground"
                                        >
                                            {{
                                                delta(
                                                    result.current[o],
                                                    result.proposed[o],
                                                )
                                            }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <div>
                                <p class="mb-2 text-sm font-medium">
                                    Against {{ result.labelled }} human
                                    decisions
                                </p>
                                <dl class="grid grid-cols-2 gap-3 text-sm">
                                    <div class="rounded-lg border p-3">
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Harm auto-approved
                                        </dt>
                                        <dd
                                            class="text-lg font-semibold tabular-nums"
                                        >
                                            {{ result.errors.proposed.missed }}
                                            <span
                                                class="text-xs font-normal text-muted-foreground"
                                                >was
                                                {{
                                                    result.errors.current.missed
                                                }}</span
                                            >
                                        </dd>
                                    </div>
                                    <div class="rounded-lg border p-3">
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Fine content auto-removed
                                        </dt>
                                        <dd
                                            class="text-lg font-semibold tabular-nums"
                                        >
                                            {{
                                                result.errors.proposed.wrongful
                                            }}
                                            <span
                                                class="text-xs font-normal text-muted-foreground"
                                                >was
                                                {{
                                                    result.errors.current
                                                        .wrongful
                                                }}</span
                                            >
                                        </dd>
                                    </div>
                                </dl>
                            </div>

                            <div v-if="result.examples.length">
                                <p class="mb-1 text-sm font-medium">Examples</p>
                                <ul class="space-y-1 text-xs">
                                    <li
                                        v-for="e in result.examples"
                                        :key="e.id"
                                    >
                                        <Link
                                            :href="submissionPage(e.id)"
                                            class="underline underline-offset-2"
                                            >#{{ e.id }}</Link
                                        >
                                        {{ e.from }} → {{ e.to }}
                                        <span
                                            v-if="e.human"
                                            class="text-muted-foreground"
                                        >
                                            (a person decided:
                                            {{ e.human }})</span
                                        >
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>History</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ol class="space-y-3 text-sm">
                            <li v-for="v in versions" :key="v.id">
                                <p>
                                    <span class="font-medium">v{{ v.id }}</span>
                                    {{ v.note }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ v.author ?? 'System' }} ·
                                    {{ dateTime(v.created_at) }}
                                </p>
                            </li>
                        </ol>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
