<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Paginated } from '@/types';

defineProps<{ page: Paginated<unknown> }>();

const linkClass =
    'rounded-md border px-3 py-1.5 font-medium transition hover:bg-accent';
</script>

<template>
    <nav
        v-if="page.last_page > 1"
        class="flex items-center justify-between gap-3 text-sm"
        aria-label="Pagination"
    >
        <Link
            v-if="page.prev_page_url"
            :href="page.prev_page_url"
            preserve-scroll
            :class="linkClass"
            >Previous</Link
        >
        <span v-else :class="linkClass" class="pointer-events-none opacity-40"
            >Previous</span
        >
        <span class="text-muted-foreground"
            >Page {{ page.current_page }} of {{ page.last_page }}</span
        >
        <Link
            v-if="page.next_page_url"
            :href="page.next_page_url"
            preserve-scroll
            :class="linkClass"
            >Next</Link
        >
        <span v-else :class="linkClass" class="pointer-events-none opacity-40"
            >Next</span
        >
    </nav>
</template>
