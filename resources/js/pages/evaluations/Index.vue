<script setup lang="ts">
import { Head, Link, useForm, usePoll } from '@inertiajs/vue3';
import { FlaskConical } from '@lucide/vue';
import { computed, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import EvaluationStatusBadge from '@/components/moderation/EvaluationStatusBadge.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { timeAgo } from '@/lib/format';
import { index, show, store } from '@/routes/evaluations';
import type { EvaluationRun } from '@/types';

const props = defineProps<{
    runs: EvaluationRun[];
    models: string[];
    efforts: string[];
    current: { model: string; effort: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Model evaluation', href: index() }],
    },
});

const form = useForm({
    model: props.current.model,
    effort: 'medium',
    sample_size: 50,
});

const submit = () => form.submit(store());

// Refresh the list while a batch is still running.
const running = computed(() =>
    props.runs.some((r) => r.status === 'pending' || r.status === 'running'),
);
const { start, stop } = usePoll(5000, { only: ['runs'] }, { autoStart: false });
watch(running, (busy) => (busy ? start() : stop()), { immediate: true });
</script>

<template>
    <Head title="Model evaluation" />

    <div class="mx-auto w-full max-w-5xl space-y-6 p-4">
        <Heading
            title="Model evaluation"
            description="Re-score recent items that a person decided with another model or effort level, then compare both against those decisions before switching the pipeline over. Runs through the Message Batches API at half price."
        />

        <Card>
            <CardHeader>
                <CardTitle>New run</CardTitle>
                <CardDescription>
                    The pipeline uses {{ current.model }} at
                    {{ current.effort }} effort.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form
                    class="grid grid-cols-1 items-end gap-4 sm:grid-cols-[1fr_1fr_8rem_auto]"
                    @submit.prevent="submit"
                >
                    <div class="space-y-2">
                        <Label for="model">Model</Label>
                        <Select v-model="form.model">
                            <SelectTrigger id="model" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="model in models"
                                    :key="model"
                                    :value="model"
                                    >{{ model }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.model" />
                    </div>
                    <div class="space-y-2">
                        <Label for="effort">Effort</Label>
                        <Select v-model="form.effort">
                            <SelectTrigger id="effort" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="effort in efforts"
                                    :key="effort"
                                    :value="effort"
                                    class="capitalize"
                                    >{{ effort }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.effort" />
                    </div>
                    <div class="space-y-2">
                        <Label for="sample_size">Items</Label>
                        <Input
                            id="sample_size"
                            v-model.number="form.sample_size"
                            type="number"
                            min="10"
                            max="200"
                        />
                        <InputError :message="form.errors.sample_size" />
                    </div>
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" />
                        <FlaskConical v-else />
                        Start batch
                    </Button>
                </form>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Runs</CardTitle>
            </CardHeader>
            <CardContent>
                <p
                    v-if="runs.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No runs yet.
                </p>
                <ul v-else class="divide-y">
                    <li v-for="run in runs" :key="run.id">
                        <Link
                            :href="show(run.id)"
                            class="flex flex-wrap items-center gap-x-4 gap-y-1 py-3 text-sm hover:underline"
                        >
                            <span class="font-medium">#{{ run.id }}</span>
                            <span>{{ run.model }} · {{ run.effort }}</span>
                            <span class="text-muted-foreground"
                                >{{ run.scored }}/{{
                                    run.items || run.sample_size
                                }}
                                items</span
                            >
                            <span class="text-muted-foreground">{{
                                timeAgo(run.created_at)
                            }}</span>
                            <EvaluationStatusBadge
                                :status="run.status"
                                class="ml-auto"
                            />
                        </Link>
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
