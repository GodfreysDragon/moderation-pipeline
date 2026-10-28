<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ImagePlus, X } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { create, index, store } from '@/routes/submissions';

const props = defineProps<{ maxUploadKb: number }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Submissions', href: index() },
            { title: 'Submit', href: create() },
        ],
    },
});

const form = useForm<{ caption: string; image: File | null }>({
    caption: '',
    image: null,
});

const preview = ref<string | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const dragging = ref(false);

const setImage = (file: File | null) => {
    if (preview.value) {
        URL.revokeObjectURL(preview.value);
    }

    form.image = file;
    preview.value = file ? URL.createObjectURL(file) : null;
    form.clearErrors('image');
};

onBeforeUnmount(() => preview.value && URL.revokeObjectURL(preview.value));

const onPick = (event: Event) =>
    setImage((event.target as HTMLInputElement).files?.[0] ?? null);

const onDrop = (event: DragEvent) => {
    dragging.value = false;
    const file = event.dataTransfer?.files?.[0];

    if (file) {
        setImage(file);
    }
};

const clearImage = () => {
    setImage(null);

    if (fileInput.value) {
        fileInput.value.value = '';
    }
};

// Demo content that exercises each path through the pipeline.
const samples = [
    {
        label: 'Harbour sunset',
        hint: 'Approved automatically',
        file: 'sunset-harbour.png',
        caption:
            'Evening walk along the harbour. The light was unreal tonight.',
    },
    {
        label: 'Crypto giveaway',
        hint: 'Rejected as spam',
        file: 'crypto-giveaway.png',
        caption:
            'Crypto giveaway! Guaranteed returns, click the link in my bio.',
    },
    {
        label: 'Parts for sale',
        hint: 'Goes to human review',
        file: 'parts-for-sale.png',
        caption:
            'Clearing out the garage: unregistered parts for sale, DM to buy.',
    },
    {
        label: 'Known meme',
        hint: 'Blocked by hash match',
        file: 'known-bad.png',
        caption: 'Reposting this classic.',
    },
    {
        label: 'Resized meme',
        hint: 'Blocked as a near-duplicate',
        file: 'known-bad-resized.jpg',
        caption: 'Found a smaller copy of this one.',
    },
    {
        label: 'Personal attack',
        hint: 'Text only, human review',
        file: null,
        caption: '@jordan every single post of yours is garbage. Total loser.',
    },
    {
        label: 'Distress',
        hint: 'Always reaches a person',
        file: null,
        caption: 'Nothing matters anymore. Honestly I want to die.',
    },
];

const loadingSample = ref<string | null>(null);

const useSample = async (sample: (typeof samples)[number]) => {
    form.clearErrors();
    form.caption = sample.caption;

    if (!sample.file) {
        clearImage();

        return;
    }

    loadingSample.value = sample.label;

    try {
        const blob = await (await fetch(`/samples/${sample.file}`)).blob();
        setImage(new File([blob], sample.file, { type: blob.type }));
    } finally {
        loadingSample.value = null;
    }
};

const maxMb = computed(() => Math.round(props.maxUploadKb / 1024));

const submit = () => form.submit(store());
</script>

<template>
    <Head title="Submit content" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading
            title="Submit content"
            description="Upload an image, write a caption, or both. Everything is checked before it goes live."
        />

        <section aria-labelledby="samples-heading" class="space-y-2">
            <h2 id="samples-heading" class="text-sm font-medium">
                Try a sample
            </h2>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <button
                    v-for="sample in samples"
                    :key="sample.label"
                    type="button"
                    class="rounded-lg border p-2.5 text-left text-sm transition hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    @click="useSample(sample)"
                >
                    <span class="flex items-center gap-1.5 font-medium">
                        {{ sample.label }}
                        <Spinner
                            v-if="loadingSample === sample.label"
                            class="size-3"
                        />
                    </span>
                    <span class="block text-xs text-muted-foreground">{{
                        sample.hint
                    }}</span>
                </button>
            </div>
        </section>

        <form class="space-y-5" @submit.prevent="submit">
            <div class="space-y-2">
                <Label for="image">Image</Label>
                <div
                    v-if="preview"
                    class="relative overflow-hidden rounded-xl border bg-muted"
                >
                    <img
                        :src="preview"
                        alt="Selected image preview"
                        class="mx-auto max-h-80 object-contain"
                    />
                    <Button
                        type="button"
                        size="icon"
                        variant="secondary"
                        class="absolute top-2 right-2"
                        aria-label="Remove image"
                        @click="clearImage"
                    >
                        <X />
                    </Button>
                </div>
                <label
                    v-else
                    for="image"
                    class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed p-10 text-center text-sm text-muted-foreground transition hover:bg-accent/50"
                    :class="{ 'border-foreground/40 bg-accent/50': dragging }"
                    @dragover.prevent="dragging = true"
                    @dragleave="dragging = false"
                    @drop.prevent="onDrop"
                >
                    <ImagePlus class="size-8" aria-hidden="true" />
                    <span
                        ><span class="font-medium text-foreground"
                            >Choose an image</span
                        >
                        or drag it here</span
                    >
                    <span class="text-xs"
                        >JPEG, PNG, GIF or WebP, up to {{ maxMb }} MB. Location
                        data is removed.</span
                    >
                </label>
                <input
                    id="image"
                    ref="fileInput"
                    type="file"
                    accept="image/jpeg,image/png,image/gif,image/webp"
                    class="sr-only"
                    @change="onPick"
                />
                <InputError :message="form.errors.image" />
            </div>

            <div class="space-y-2">
                <Label for="caption">Caption</Label>
                <Textarea
                    id="caption"
                    v-model="form.caption"
                    rows="4"
                    maxlength="2000"
                    placeholder="Say something about it…"
                />
                <InputError :message="form.errors.caption" />
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" /> Submit
                </Button>
                <progress
                    v-if="form.progress"
                    :value="form.progress.percentage"
                    max="100"
                    class="h-1.5 w-40"
                >
                    {{ form.progress.percentage }}%
                </progress>
            </div>
        </form>
    </div>
</template>
