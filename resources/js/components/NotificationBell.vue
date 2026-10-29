<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Bell, CircleAlert, Gavel, Hourglass } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { timeAgo } from '@/lib/format';
import { open, readAll } from '@/routes/notifications';
import type { AppNotification } from '@/types';

const page = usePage();
const feed = computed(() => page.props.notifications);

const icons: Record<NonNullable<AppNotification['kind']>, typeof Bell> = {
    decision: Gavel,
    escalation: CircleAlert,
    backlog: Hourglass,
};

const markAllRead = () =>
    router.post(
        readAll().url,
        {},
        { preserveScroll: true, preserveState: true },
    );
</script>

<template>
    <DropdownMenu v-if="feed">
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="relative"
                :aria-label="
                    feed.unread
                        ? `Notifications, ${feed.unread} unread`
                        : 'Notifications'
                "
            >
                <Bell class="size-5" />
                <span
                    v-if="feed.unread"
                    class="absolute top-1 right-1 flex min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] leading-4 font-semibold text-white"
                    aria-hidden="true"
                    >{{ feed.unread > 9 ? '9+' : feed.unread }}</span
                >
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-80">
            <div class="flex items-center justify-between px-2 py-1.5">
                <DropdownMenuLabel class="p-0">Notifications</DropdownMenuLabel>
                <button
                    v-if="feed.unread"
                    type="button"
                    class="text-xs text-muted-foreground hover:text-foreground"
                    @click.stop="markAllRead"
                >
                    Mark all read
                </button>
            </div>
            <DropdownMenuSeparator />
            <p
                v-if="feed.recent.length === 0"
                class="px-2 py-6 text-center text-sm text-muted-foreground"
            >
                Nothing yet.
            </p>
            <DropdownMenuItem
                v-for="n in feed.recent"
                :key="n.id"
                as-child
                class="cursor-pointer items-start gap-3 py-2"
            >
                <Link :href="open(n.id)">
                    <component
                        :is="n.kind ? icons[n.kind] : Bell"
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <div class="min-w-0 flex-1 space-y-0.5">
                        <p
                            class="text-sm"
                            :class="
                                n.read ? 'text-muted-foreground' : 'font-medium'
                            "
                        >
                            {{ n.title }}
                        </p>
                        <p class="line-clamp-2 text-xs text-muted-foreground">
                            {{ n.body }}
                        </p>
                        <p
                            v-if="n.created_at"
                            class="text-[11px] text-muted-foreground/80"
                        >
                            {{ timeAgo(n.created_at) }}
                        </p>
                    </div>
                    <span
                        v-if="!n.read"
                        class="mt-1.5 size-2 shrink-0 rounded-full bg-blue-600"
                        aria-label="Unread"
                    />
                </Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
