<script setup lang="ts">
import { Head, Link, setLayoutProps, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import EvaluationStatusBadge from '@/components/moderation/EvaluationStatusBadge.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dateTime, percent } from '@/lib/format';
import { index, show } from '@/routes/evaluations';
import { show as submissionPage } from '@/routes/submissions';
import type { EvaluationReport, EvaluationRun, EvaluationSide } from '@/types';

const props = defineProps<{
    run: EvaluationRun;
    report: EvaluationReport | null;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Model evaluation', href: index() },
        { title: `Run #${props.run.id}`, href: show(props.run.id) },
    ],
});

// Batches usually finish in minutes; check back until this one has.
const { start, stop } = usePoll(5000, {}, { autoStart: false });
watch(
    () => props.run.status,
    (status) =>
        status === 'pending' || status === 'running' ? start() : stop(),
    { immediate: true },
);

const dollars = (value: number | null) =>
    value === null ? '–' : `$${value.toFixed(4)}`;

const outcomeLabel: Record<string, string> = {
    approve: 'Approve',
    review: 'Review',
    reject: 'Reject',
};

const sides = ['current', 'candidate'] as const;

const automated = (s: EvaluationSide) => s.outcomes.approve + s.outcomes.reject;

type Row = {
    label: string;
    value: (side: EvaluationSide) => string;
    // Which direction is better, so the better cell can be marked.
    score?: (side: EvaluationSide) => number | null;
    better?: 'higher' | 'lower';
};

const rows: Row[] = [
    {
        label: 'Approved automatically',
        value: (s) => String(s.outcomes.approve),
    },
    { label: 'Sent to a person', value: (s) => String(s.outcomes.review) },
    {
        label: 'Rejected automatically',
        value: (s) => String(s.outcomes.reject),
    },
    {
        label: 'Automated decisions a person agreed with',
        value: (s) =>
            s.accuracy === null
                ? '–'
                : `${percent(s.accuracy, 1)} (${s.correct} of ${automated(s)})`,
        // Too few automated decisions to call a winner.
        score: (s) => (automated(s) >= 10 ? s.accuracy : null),
        better: 'higher',
    },
    {
        label: 'Harm approved automatically',
        value: (s) => String(s.missed),
        score: (s) => s.missed,
        better: 'lower',
    },
    {
        label: 'Fine content removed automatically',
        value: (s) => String(s.wrongful),
        score: (s) => s.wrongful,
        better: 'lower',
    },
    {
        label: 'Cost per item',
        value: (s) => dollars(s.cost_per_item),
        score: (s) => s.cost_per_item,
        better: 'lower',
    },
];

const isBetter = (row: Row, side: (typeof sides)[number]) => {
    if (!props.report || !row.score || !row.better) {
        return false;
    }

    const mine = row.score(props.report[side]);
    const theirs = row.score(
        props.report[side === 'current' ? 'candidate' : 'current'],
    );

    if (mine === null || theirs === null || mine === theirs) {
        return false;
    }

    return row.better === 'higher' ? mine > theirs : mine < theirs;
};

const drift = computed(() =>
    (props.report?.drift ?? []).filter((d) => Math.abs(d.mean_change) >= 0.005),
);
</script>

<template>
    <Head :title="`Evaluation #${run.id}`" />

    <div class="mx-auto w-full max-w-5xl space-y-6 p-4">
        <Card>
            <CardHeader>
                <div class="flex flex-wrap items-center gap-2">
                    <CardTitle>
                        {{ run.model }} at {{ run.effort }} effort
                    </CardTitle>
                    <EvaluationStatusBadge
                        :status="run.status"
                        class="ml-auto"
                    />
                </div>
                <CardDescription>
                    {{ run.items || run.sample_size }} items a person decided,
                    judged under policy v{{ run.policy_version }} · started by
                    {{ run.created_by ?? 'someone' }},
                    {{ dateTime(run.created_at) }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <p
                    v-if="run.status === 'failed'"
                    class="rounded-lg bg-red-100 p-3 text-sm text-red-950 dark:bg-red-500/15 dark:text-red-200"
                >
                    {{ run.error }}
                </p>
                <p
                    v-else-if="run.status !== 'completed'"
                    class="text-sm text-muted-foreground"
                >
                    The batch is with the API. Batches cost half the normal
                    price and usually finish within minutes, at most a day. This
                    page updates on its own.
                </p>
                <p v-else-if="report" class="text-sm text-muted-foreground">
                    {{ report.scored }} scored<template v-if="report.errored"
                        >, {{ report.errored }} without a result</template
                    >. {{ report.changed }} would be decided differently.
                </p>
            </CardContent>
        </Card>

        <template v-if="report">
            <Card>
                <CardHeader>
                    <CardTitle>Against what people decided</CardTitle>
                    <CardDescription>
                        Both sets of scores under the same thresholds. These are
                        items people decided, which the current scores mostly
                        sent to review by design, so look at how many the
                        candidate decides on its own and how often people agree
                        with those. The better figure in each row is marked.
                    </CardDescription>
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="border-b text-left text-xs text-muted-foreground"
                            >
                                <th class="py-2 pr-4 font-medium">
                                    <span class="sr-only">Measure</span>
                                </th>
                                <th class="py-2 pr-4 font-medium">
                                    Current ({{ report.current.model ?? '–' }},
                                    live)
                                </th>
                                <th class="py-2 font-medium">
                                    Candidate ({{ report.candidate.model }},
                                    batch)
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in rows"
                                :key="row.label"
                                class="border-b last:border-0"
                            >
                                <th class="py-2 pr-4 text-left font-normal">
                                    {{ row.label }}
                                </th>
                                <td
                                    v-for="side in sides"
                                    :key="side"
                                    class="py-2 pr-4 tabular-nums"
                                    :class="{
                                        'font-semibold': isBetter(row, side),
                                    }"
                                >
                                    {{ row.value(report[side]) }}
                                    <span
                                        v-if="isBetter(row, side)"
                                        class="ml-1 text-xs font-normal text-emerald-700 dark:text-emerald-400"
                                        >better</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Score drift</CardTitle>
                        <CardDescription>
                            Mean change per category, candidate minus current.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <p
                            v-if="drift.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            Scores barely moved.
                        </p>
                        <dl v-else class="space-y-1.5 text-sm">
                            <div
                                v-for="d in drift"
                                :key="d.category"
                                class="flex justify-between gap-4"
                            >
                                <dt>{{ d.label }}</dt>
                                <dd class="tabular-nums">
                                    {{ d.mean_change > 0 ? '+' : ''
                                    }}{{ d.mean_change.toFixed(3) }}
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Decided differently</CardTitle>
                        <CardDescription>Up to ten examples.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <p
                            v-if="report.examples.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            Every item lands in the same place.
                        </p>
                        <ul v-else class="space-y-2 text-sm">
                            <li v-for="e in report.examples" :key="e.id">
                                <Link
                                    :href="submissionPage(e.id)"
                                    class="font-medium underline underline-offset-2"
                                    >#{{ e.id }}</Link
                                >
                                {{ outcomeLabel[e.current] }} →
                                <strong>{{ outcomeLabel[e.candidate] }}</strong>
                                <span class="text-muted-foreground">
                                    (a person decided
                                    {{
                                        outcomeLabel[e.human].toLowerCase()
                                    }})</span
                                >
                                <p
                                    v-if="e.caption"
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{ e.caption }}
                                </p>
                            </li>
                        </ul>
                    </CardContent>
                </Card>
            </div>
        </template>
    </div>
</template>
