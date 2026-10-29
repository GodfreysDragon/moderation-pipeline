<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { edit, update } from '@/routes/notification-preferences';

type Option = {
    kind: 'decisions' | 'escalations' | 'backlog';
    enabled: boolean;
};

const props = defineProps<{ options: Option[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Notification settings', href: edit() }],
    },
});

const labels: Record<Option['kind'], { title: string; description: string }> = {
    decisions: {
        title: 'Decisions about my posts',
        description:
            'When a post is removed, or a moderator or an appeal decides it.',
    },
    escalations: {
        title: 'Escalations',
        description: 'When a moderator hands an item up to the admins.',
    },
    backlog: {
        title: 'Review queue backlog',
        description:
            'When items have waited too long for a reviewer (at most every two hours).',
    },
};

const form = useForm({
    email: props.options.filter((o) => o.enabled).map((o) => o.kind),
});

const save = () => form.submit(update(), { preserveScroll: true });
</script>

<template>
    <Head title="Notification settings" />

    <h1 class="sr-only">Notification settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Notifications"
            description="Everything appears under the bell in the header. Choose what also arrives by email."
        />

        <form class="space-y-6" @submit.prevent="save">
            <fieldset class="space-y-4">
                <legend class="sr-only">Email me about</legend>
                <label
                    v-for="option in options"
                    :key="option.kind"
                    class="flex items-start gap-3"
                >
                    <input
                        v-model="form.email"
                        type="checkbox"
                        :value="option.kind"
                        class="mt-0.5 size-4 accent-primary"
                    />
                    <span class="space-y-0.5">
                        <span class="block text-sm font-medium">{{
                            labels[option.kind].title
                        }}</span>
                        <span class="block text-sm text-muted-foreground">{{
                            labels[option.kind].description
                        }}</span>
                    </span>
                </label>
            </fieldset>

            <Button type="submit" :disabled="form.processing">Save</Button>
        </form>
    </div>
</template>
