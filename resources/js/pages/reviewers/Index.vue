<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { percent } from '@/lib/format';
import { index } from '@/routes/reviewers';

type Reviewer = {
    id: number;
    name: string;
    role: string;
    decided: number;
    approved: number;
    rejected: number;
    escalated: number;
    handling_seconds_p50: number | null;
    appealed: number;
    overturned: number;
    second_reviewed: number;
    second_agreed: number;
    graphic_today: number;
};

defineProps<{
    stats: {
        days: number;
        agreement: {
            pairs: number;
            agreed: number;
            rate: number | null;
            kappa: number | null;
        };
        reviewers: Reviewer[];
    };
    graphicLimit: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Reviewers', href: index() }],
    },
});

const setDays = (days: number) =>
    router.get(index({ query: { days } }), {}, { preserveScroll: true });

const ratio = (part: number, whole: number) =>
    whole === 0 ? '–' : percent(part / whole);

const seconds = (value: number | null) =>
    value === null
        ? '–'
        : value < 120
          ? `${value}s`
          : `${Math.round(value / 60)}m`;

// Landis and Koch's conventional bands for kappa.
const kappaLabel = (kappa: number | null) => {
    if (kappa === null) {
        return 'not enough variation to tell';
    }

    if (kappa < 0.2) {
        return 'slight agreement';
    }

    if (kappa < 0.4) {
        return 'fair agreement';
    }

    if (kappa < 0.6) {
        return 'moderate agreement';
    }

    if (kappa < 0.8) {
        return 'substantial agreement';
    }

    return 'almost perfect agreement';
};
</script>

<template>
    <Head title="Reviewers" />

    <div class="space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Reviewers"
                description="How decisions hold up on appeal and in blind second reviews, and how much graphic content each person has seen today."
            />
            <div class="flex gap-1" role="group" aria-label="Range">
                <Button
                    v-for="d in [7, 30, 90]"
                    :key="d"
                    size="sm"
                    :variant="stats.days === d ? 'default' : 'outline'"
                    :aria-pressed="stats.days === d"
                    @click="setDays(d)"
                    >{{ d }} days</Button
                >
            </div>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Agreement between reviewers</CardTitle>
                <CardDescription>
                    From {{ stats.agreement.pairs }} blind second reviews in the
                    last {{ stats.days }} days.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <p
                    v-if="stats.agreement.pairs === 0"
                    class="text-sm text-muted-foreground"
                >
                    No second reviews yet. A share of triage decisions is sent
                    to a second reviewer automatically.
                </p>
                <dl
                    v-else
                    class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2"
                >
                    <div class="rounded-lg border p-3">
                        <dt class="text-xs text-muted-foreground">
                            Same decision
                        </dt>
                        <dd class="text-xl font-semibold tabular-nums">
                            {{ percent(stats.agreement.rate) }}
                        </dd>
                        <p class="text-xs text-muted-foreground">
                            {{ stats.agreement.agreed }} of
                            {{ stats.agreement.pairs }} pairs
                        </p>
                    </div>
                    <div class="rounded-lg border p-3">
                        <dt class="text-xs text-muted-foreground">
                            Cohen’s kappa
                        </dt>
                        <dd class="text-xl font-semibold tabular-nums">
                            {{ stats.agreement.kappa?.toFixed(2) ?? '–' }}
                        </dd>
                        <p class="text-xs text-muted-foreground">
                            {{ kappaLabel(stats.agreement.kappa) }}, after
                            discounting agreement expected by chance
                        </p>
                    </div>
                </dl>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Per reviewer</CardTitle>
                <CardDescription>
                    Last {{ stats.days }} days. Graphic exposure is the last 24
                    hours, against a limit of {{ graphicLimit }}.
                </CardDescription>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr
                            class="border-b text-left text-xs text-muted-foreground"
                        >
                            <th class="py-2 pr-4 font-medium">Reviewer</th>
                            <th class="py-2 pr-4 text-right font-medium">
                                Decided
                            </th>
                            <th class="py-2 pr-4 text-right font-medium">
                                Rejected
                            </th>
                            <th class="py-2 pr-4 text-right font-medium">
                                Handling (median)
                            </th>
                            <th class="py-2 pr-4 text-right font-medium">
                                Overturned on appeal
                            </th>
                            <th class="py-2 pr-4 text-right font-medium">
                                Second reviews agreeing
                            </th>
                            <th class="py-2 text-right font-medium">
                                Graphic today
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="r in stats.reviewers"
                            :key="r.id"
                            class="border-b last:border-0"
                        >
                            <th class="py-2 pr-4 text-left font-medium">
                                {{ r.name }}
                                <span
                                    class="ml-1 text-xs font-normal text-muted-foreground capitalize"
                                    >{{ r.role }}</span
                                >
                            </th>
                            <td class="py-2 pr-4 text-right tabular-nums">
                                {{ r.decided }}
                            </td>
                            <td class="py-2 pr-4 text-right tabular-nums">
                                {{ ratio(r.rejected, r.approved + r.rejected) }}
                            </td>
                            <td class="py-2 pr-4 text-right tabular-nums">
                                {{ seconds(r.handling_seconds_p50) }}
                            </td>
                            <td class="py-2 pr-4 text-right tabular-nums">
                                {{ r.overturned }} of {{ r.appealed }}
                            </td>
                            <td class="py-2 pr-4 text-right tabular-nums">
                                {{ ratio(r.second_agreed, r.second_reviewed) }}
                                <span class="text-xs text-muted-foreground"
                                    >({{ r.second_reviewed }})</span
                                >
                            </td>
                            <td
                                class="py-2 text-right tabular-nums"
                                :class="{
                                    'font-semibold text-amber-700 dark:text-amber-400':
                                        r.graphic_today >= graphicLimit,
                                }"
                            >
                                {{ r.graphic_today }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </div>
</template>
