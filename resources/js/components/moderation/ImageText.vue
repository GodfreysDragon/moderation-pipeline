<script setup lang="ts">
import { ScanText } from '@lucide/vue';

defineProps<{ text: string }>();
</script>

<template>
    <!-- What the classifier read in the image. Uploader-controlled, so it is only ever shown as text. -->
    <div class="rounded-lg border bg-muted/40 p-3">
        <p
            class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground"
        >
            <ScanText class="size-3.5" aria-hidden="true" />
            Text in the image
        </p>
        <p class="mt-1 font-mono text-sm break-words">{{ text }}</p>
    </div>
</template>
