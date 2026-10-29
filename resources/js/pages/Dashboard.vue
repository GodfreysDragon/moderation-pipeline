<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Download, Info } from '@lucide/vue';
import { computed, ref } from 'vue';
import StackedColumns from '@/components/charts/StackedColumns.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { minutes, percent, shortDate } from '@/lib/format';
import { dashboard } from '@/routes';
import { decisionLog } from '@/routes/exports';
import { index as review } from '@/routes/review';

type Metrics = {
    days: number;
    totals: Record<
        'submitted' | 'approved' | 'rejected' | 'pending_review' | 'processing',
        number
    >;
    automation_rate: number | null;
    blocklist_hits: number;
    queue: {
        open: Record<string, number>;
        total: number;
        oldest_minutes: number | null;
        claimed: number;
    };
    timing: {
        automated_minutes_p50: number | null;
        human_minutes_p50: number | null;
        handling_seconds_p50: number | null;
    };
    audit: Record<
        'approve' | 'reject',
        { checked: number; agreed: number; precision: number | null }
    >;
    appeals: {
        resolved: number;
        overturned: number;
        overturn_rate: number | null;
        open: number;
    };
    daily: {
        date: string;
        auto_approved: number;
        auto_rejected: number;
        human_approved: number;
        human_rejected: number;
        open: number;
    }[];
    categories: {
        category: string;
        label: string;
        auto: number;
        human: number;
    }[];
    stages: {
        stage: string;
        label: string;
        runs: number;
        failed: number;
        skipped: number;
        p50_ms: number | null;
        p95_ms: number | null;
    }[];
    ai_cost: {
        classified: number;
        input_tokens: number;
        output_tokens: number;
        cache_hit_rate: number | null;
        cost: number | null;
        cost_per_item: number | null;
        saved_by_cache: number | null;
    };
};

const dollars = (value: number | null, digits = 2) =>
    value === null ? 'â€“' : `$${value.toFixed(digits)}`;
const thousands = (value: number) =>
    value >= 10_000
        ? `${Math.round(value / 1000)}k`
        : value.toLocaleString('en-US');

const props = defineProps<{ metrics: Metrics }>();
const page = usePage();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

const loading = ref(false);
const setDays = (days: number) =>
    router.get(
        dashboard({ query: { days } }),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
        },
    );

// One colour per entity across the page: automated vs human-reviewed.
const series = [
    {
        key: 'automated' as const,
        label: 'Automated',
        color: 'var(--viz-series-1)',
    },
    {
        key: 'human' as const,
        label: 'Human-reviewed',
        color: 'var(--viz-series-2)',
    },
    { key: 'open' as const, label: 'Still open', color: 'var(--viz-neutral)' },
];

const daily = computed(() =>
    props.metrics.daily.map((d) => ({
        label: shortDate(d.date),
        automated: d.auto_approved + d.auto_rejected,
        human: d.human_approved + d.human_rejected,
        open: d.open,
    })),
);

const categoryRows = computed(() =>
    props.metrics.categories.filter((c) => c.auto + c.human > 0),
);
const categoryMax = computed(() =>
    Math.max(1, ...categoryRows.value.map((c) => c.auto + c.human)),
);

const ms = (v: number | null) =>
    v === null ? '—' : v < 1000 ? `${v} ms` : `${(v / 1000).toFixed(1)} s`;
const seconds = (v: number | null) =>
    v === null ? '—' : v < 90 ? `${v} s` : `${Math.round(v / 60)} min`;
const t = computed(() => props.metrics.totals);
</script>

<template>
    <Head title="Dashboard" />

    <div
        class="space-y-6 p-4"
        :class="{ 'opacity-60 transition-opacity': loading }"
    >
        <div class="flex flex-wrap items-center gap-2">
            <span class="mr-1 text-sm text-muted-foreground">Range</span>
            <Button
                v-for="d in [7, 14, 30]"
                :key="d"
                size="sm"
                :variant="metrics.days === d ? 'default' : 'outline'"
                :aria-pressed="metrics.days === d"
                @click="setDays(d)"
            >
                Last {{ d }} days
            </Button>
            <Button
                v-if="page.props.auth.can.managePolicy"
                as="a"
                size="sm"
                variant="ghost"
                class="ml-auto"
                :href="decisionLog({ query: { days: metrics.days } }).url"
            >
                <Download /> Decision log (CSV)
            </Button>
        </div>

        <!-- Headline numbers -->
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Card class="gap-2 py-4">
                <CardContent class="space-y-1 px-4">
                    <p class="text-sm text-muted-foreground">
                        Decided automatically
                    </p>
                    <p class="text-3xl font-semibold tracking-tight">
                        {{ percent(metrics.automation_rate) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        of {{ t.approved + t.rejected }} decisions ·
                        {{ metrics.blocklist_hits }} caught by hash match
                    </p>
                </CardContent>
            </Card>
            <Card class="gap-2 py-4">
                <CardContent class="space-y-1 px-4">
                    <p class="text-sm text-muted-foreground">
                        Auto-decision precision
                    </p>
                    <p class="text-3xl font-semibold tracking-tight">
                        {{ percent(metrics.audit.approve.precision) }}
                        <span
                            class="text-base font-normal text-muted-foreground"
                            >/
                            {{ percent(metrics.audit.reject.precision) }}</span
                        >
                    </p>
                    <p class="text-xs text-muted-foreground">
                        approve / reject, from
                        {{
                            metrics.audit.approve.checked +
                            metrics.audit.reject.checked
                        }}
                        QA audits
                    </p>
                </CardContent>
            </Card>
            <Card class="gap-2 py-4">
                <CardContent class="space-y-1 px-4">
                    <p class="text-sm text-muted-foreground">
                        Appeals overturned
                    </p>
                    <p class="text-3xl font-semibold tracking-tight">
                        {{ percent(metrics.appeals.overturn_rate) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ metrics.appeals.overturned }} of
                        {{ metrics.appeals.resolved }} resolved ·
                        {{ metrics.appeals.open }} open
                    </p>
                </CardContent>
            </Card>
            <Card class="gap-2 py-4">
                <CardContent class="space-y-1 px-4">
                    <p class="text-sm text-muted-foreground">Review queue</p>
                    <p class="text-3xl font-semibold tracking-tight">
                        <Link :href="review()" class="hover:underline">{{
                            metrics.queue.total
                        }}</Link>
                        <span
                            class="ml-1 text-base font-normal text-muted-foreground"
                            >open</span
                        >
                    </p>
                    <p class="text-xs text-muted-foreground">
                        oldest waiting
                        {{ minutes(metrics.queue.oldest_minutes) }} ·
                        {{ metrics.queue.claimed }} being reviewed
                    </p>
                </CardContent>
            </Card>
        </div>

        <div
            class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]"
        >
            <Card>
                <CardHeader>
                    <CardTitle>Submissions per day</CardTitle>
                    <CardDescription>
                        {{ t.submitted }} in the last {{ metrics.days }} days,
                        by who made the final call.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <StackedColumns
                        :rows="daily"
                        :series="series"
                        :label="`Submissions per day for the last ${metrics.days} days, split into automated, human-reviewed and still open`"
                    />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Rejections by policy</CardTitle>
                    <CardDescription
                        >Who removed it: the pipeline or a
                        person.</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <p
                        v-if="categoryRows.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No rejections in this range.
                    </p>
                    <ul
                        v-else
                        class="space-y-3"
                        aria-label="Rejections by policy category"
                    >
                        <li v-for="c in categoryRows" :key="c.category">
                            <div
                                class="mb-1 flex items-baseline justify-between gap-2 text-sm"
                            >
                                <span>{{ c.label }}</span>
                                <span
                                    class="text-xs text-muted-foreground tabular-nums"
                                >
                                    <span
                                        class="font-semibold text-foreground"
                                        >{{ c.auto + c.human }}</span
                                    >
                                    · {{ c.auto }} automated ·
                                    {{ c.human }} human
                                </span>
                            </div>
                            <div
                                class="flex h-2.5 gap-0.5"
                                :title="`${c.auto} automated, ${c.human} human`"
                            >
                                <div
                                    v-if="c.auto"
                                    class="h-full first:rounded-l-[2px] last:rounded-r-[4px]"
                                    :style="{
                                        width: `${(c.auto / categoryMax) * 100}%`,
                                        background: 'var(--viz-series-1)',
                                    }"
                                />
                                <div
                                    v-if="c.human"
                                    class="h-full first:rounded-l-[2px] last:rounded-r-[4px]"
                                    :style="{
                                        width: `${(c.human / categoryMax) * 100}%`,
                                        background: 'var(--viz-series-2)',
                                    }"
                                />
                            </div>
                        </li>
                    </ul>
                    <div class="mt-4 flex gap-4 text-xs text-muted-foreground">
                        <span class="flex items-center gap-1.5">
                            <span
                                class="size-2.5 rounded-[2px]"
                                style="background: var(--viz-series-1)"
                                aria-hidden="true"
                            />
                            Automated
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span
                                class="size-2.5 rounded-[2px]"
                                style="background: var(--viz-series-2)"
                                aria-hidden="true"
                            />
                            Human-reviewed
                        </span>
                    </div>
                </CardContent>
            </Card>
        </div>

        <div
            class="grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]"
        >
            <Card>
                <CardHeader>
                    <CardTitle>Pipeline stages</CardTitle>
                    <CardDescription
                        >Latency of successful runs, and how often each stage
                        failed or was skipped.</CardDescription
                    >
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <table class="w-full min-w-[30rem] text-sm">
                        <thead class="text-xs text-muted-foreground">
                            <tr>
                                <th
                                    scope="col"
                                    class="py-1.5 text-left font-medium"
                                >
                                    Stage
                                </th>
                                <th
                                    scope="col"
                                    class="py-1.5 text-right font-medium"
                                >
                                    Runs
                                </th>
                                <th
                                    scope="col"
                                    class="py-1.5 text-right font-medium"
                                >
                                    Skipped
                                </th>
                                <th
                                    scope="col"
                                    class="py-1.5 text-right font-medium"
                                >
                                    Failed
                                </th>
                                <th
                                    scope="col"
                                    class="py-1.5 text-right font-medium"
                                >
                                    p50
                                </th>
                                <th
                                    scope="col"
                                    class="py-1.5 text-right font-medium"
                                >
                                    p95
                                </th>
                            </tr>
                        </thead>
                        <tbody class="tabular-nums">
                            <tr
                                v-for="s in metrics.stages"
                                :key="s.stage"
                                class="border-t"
                            >
                                <th
                                    scope="row"
                                    class="py-2 text-left font-normal"
                                >
                                    {{ s.label }}
                                </th>
                                <td class="text-right">{{ s.runs }}</td>
                                <td class="text-right text-muted-foreground">
                                    {{ s.skipped }}
                                </td>
                                <td
                                    class="text-right"
                                    :class="{
                                        'font-semibold text-red-700 dark:text-red-400':
                                            s.failed > 0,
                                    }"
                                >
                                    {{ s.failed }}
                                </td>
                                <td class="text-right">{{ ms(s.p50_ms) }}</td>
                                <td class="text-right">{{ ms(s.p95_ms) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p
                        class="mt-3 flex items-start gap-1.5 text-xs text-muted-foreground"
                    >
                        <Info
                            class="mt-0.5 size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        Skipped stages are expected: text-only posts skip media
                        preparation, and hash matches skip the AI call.
                    </p>
                </CardContent>
            </Card>

            <div class="min-w-0 space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Time to decision</CardTitle>
                        <CardDescription
                            >Medians for this range.</CardDescription
                        >
                    </CardHeader>
                    <CardContent>
                        <dl
                            class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3 xl:grid-cols-1 2xl:grid-cols-3"
                        >
                            <div class="rounded-lg border p-3">
                                <dt class="text-xs text-muted-foreground">
                                    Automated
                                </dt>
                                <dd class="text-xl font-semibold">
                                    {{
                                        minutes(
                                            metrics.timing
                                                .automated_minutes_p50,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div class="rounded-lg border p-3">
                                <dt class="text-xs text-muted-foreground">
                                    With a person
                                </dt>
                                <dd class="text-xl font-semibold">
                                    {{
                                        minutes(
                                            metrics.timing.human_minutes_p50,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div class="rounded-lg border p-3">
                                <dt class="text-xs text-muted-foreground">
                                    Handling per task
                                </dt>
                                <dd class="text-xl font-semibold">
                                    {{
                                        seconds(
                                            metrics.timing.handling_seconds_p50,
                                        )
                                    }}
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>AI spend</CardTitle>
                        <CardDescription>
                            Estimated from token counts at list prices,
                            {{ metrics.ai_cost.classified }} classified items.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <p
                            v-if="metrics.ai_cost.classified === 0"
                            class="text-sm text-muted-foreground"
                        >
                            No token counts in this range. The offline
                            classifier doesnâ€™t report any.
                        </p>
                        <dl
                            v-else
                            class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4 xl:grid-cols-2 2xl:grid-cols-4"
                        >
                            <div class="rounded-lg border p-3">
                                <dt class="text-xs text-muted-foreground">
                                    Total
                                </dt>
                                <dd class="text-xl font-semibold tabular-nums">
                                    {{ dollars(metrics.ai_cost.cost) }}
                                </dd>
                            </div>
                            <div class="rounded-lg border p-3">
                                <dt class="text-xs text-muted-foreground">
                                    Per item
                                </dt>
                                <dd class="text-xl font-semibold tabular-nums">
                                    {{
                                        dollars(
                                            metrics.ai_cost.cost_per_item,
                                            4,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div class="rounded-lg border p-3">
                                <dt class="text-xs text-muted-foreground">
                                    Input from cache
                                </dt>
                                <dd class="text-xl font-semibold tabular-nums">
                                    {{
                                        percent(metrics.ai_cost.cache_hit_rate)
                                    }}
                                </dd>
                            </div>
                            <div class="rounded-lg border p-3">
                                <dt class="text-xs text-muted-foreground">
                                    Saved by caching
                                </dt>
                                <dd class="text-xl font-semibold tabular-nums">
                                    {{
                                        dollars(metrics.ai_cost.saved_by_cache)
                                    }}
                                </dd>
                            </div>
                        </dl>
                        <p
                            v-if="metrics.ai_cost.classified > 0"
                            class="mt-3 text-xs text-muted-foreground"
                        >
                            {{ thousands(metrics.ai_cost.input_tokens) }} input
                            and
                            {{ thousands(metrics.ai_cost.output_tokens) }}
                            output tokens.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
